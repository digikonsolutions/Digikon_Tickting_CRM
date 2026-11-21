<?php

namespace Tests\Feature;

use App\Models\AgencyContract;
use App\Models\Ticket;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/** The owner dashboard's single payload: KPIs, merged attention list, projects, employees' week and activity. */
class AgencyOwnerDashboardTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWeek();
    }

    private function ticket(WorkItem $project, string $status, float $hours = 2, string $title = 'Ticket'): Ticket
    {
        $ticket = Ticket::create(['work_item_id' => $project->id, 'title' => $title, 'description' => 'd', 'estimated_hours' => $hours, 'position' => 0]);
        $ticket->forceFill(['status' => $status])->save();

        return $ticket;
    }

    private function dashboard($owner)
    {
        return $this->actingAs($owner)->getJson('/api/agency/dashboard')->assertOk()->json('dashboard');
    }

    public function test_a_quiet_agency_gets_an_empty_attention_list_and_zero_kpis(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $this->hold($this->makeEmployee('Ann'), $agency, $this->makeClient($agency));

        $d = $this->dashboard($owner);

        $this->assertSame([], $d['needs_attention']);
        $this->assertSame(['open_projects' => 0, 'tickets_in_progress' => 0, 'tickets_waiting_on_you' => 0, 'clients' => 1], $d['kpis']);
        $this->assertSame([], $d['active_projects']);
        $this->assertSame('Ann', $d['employees'][0]['name']);
        $this->assertCount(6, $d['employees'][0]['days']);
        $this->assertSame(['seats', 'used'], array_slice(array_keys($d['seats']), 0, 2));
    }

    public function test_kpis_active_projects_and_ticket_hour_progress(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $client = $this->makeClient($agency, 'Acme Client');
        $assignment = $this->hold($this->makeEmployee('Ann'), $agency, $client);
        $project = $this->book($assignment, 'in_progress', [[$this->today->addDays(2), 2]], $this->today->addDays(9), 'Website');
        $this->ticket($project, 'done', 3);
        $this->ticket($project, 'in_progress', 1);
        $this->ticket($project, 'in_review', 2);
        $this->ticket($project, 'cancelled', 50);                        // cancelled work is not part of the progress

        $d = $this->dashboard($owner);

        $this->assertSame(1, $d['kpis']['open_projects']);
        $this->assertSame(1, $d['kpis']['tickets_in_progress']);
        $this->assertSame(1, $d['kpis']['tickets_waiting_on_you']);
        $row = $d['active_projects'][0];
        $this->assertSame('Website', $row['title']);
        $this->assertSame('Acme Client', $row['client_name']);
        $this->assertEquals(['ticket_hours_total' => 6.0, 'ticket_hours_done' => 3.0, 'progress_percent' => 50, 'health' => 'on_track'], array_intersect_key($row, array_flip(['ticket_hours_total', 'ticket_hours_done', 'progress_percent', 'health'])));
        $this->assertSame('Ann', $row['employee']['name']);
    }

    public function test_attention_merges_every_source_in_severity_order_with_one_action_each(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        AgencyContract::create(['agency_id' => $agency->id, 'status' => 'active', 'start_date' => $this->today->subMonth()->toDateString(), 'end_date' => $this->today->addDays(5)->toDateString(), 'plan_name' => 'Small', 'hours_per_month' => 10]);
        $assignment = $this->hold($this->makeEmployee('Ann'), $agency, $this->makeClient($agency));
        $week = \Carbon\CarbonImmutable::parse(\App\Services\AgencyInsights::weekBounds()['week_start']);
        $overdue = $this->book($assignment, 'in_progress', [[$week->addDay(), 9]], $this->today->subDay(), 'Late one');
        $this->ticket($overdue, 'waiting_on_client');
        $this->book($assignment, 'scheduled', [[$week->addDays(2), 24]], $this->today->addDay(), 'Soon one');

        $d = $this->dashboard($owner);
        $types = array_column($d['needs_attention'], 'type');

        foreach (['contract_ends_soon', 'overdue_projects', 'due_soon_projects', 'tickets_waiting', 'employee_near_capacity'] as $expected) {
            $this->assertContains($expected, $types);
        }
        $severities = array_column($d['needs_attention'], 'severity');
        $this->assertSame($severities, collect($severities)->sortBy(fn ($s) => ['danger' => 0, 'warning' => 1, 'info' => 2][$s])->values()->all());
        foreach ($d['needs_attention'] as $item) {
            $this->assertNotEmpty($item['label']);
            $this->assertTrue(isset($item['action']['to']) xor isset($item['action']['request']), $item['type'].' needs exactly one action');
        }
        $renewal = collect($d['needs_attention'])->firstWhere('type', 'contract_ends_soon');
        $this->assertSame('renewal', $renewal['action']['request']);
    }

    public function test_an_agency_without_employees_is_told_to_request_one(): void
    {
        $owner = $this->makeOwner($this->makeAgency('Northwind'));

        $this->assertContains('no_employee', array_column($this->dashboard($owner)['needs_attention'], 'type'));
    }

    public function test_activity_lists_recent_ticket_events_and_new_clients_for_this_agency_only(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $client = $this->makeClient($agency, 'Newest Client');
        $project = $this->book($this->hold($this->makeEmployee('Ann'), $agency, $client), 'in_progress', [[$this->today->addDays(2), 2]]);
        $ticket = $this->ticket($project, 'todo', 1, 'Fix the form');
        \Illuminate\Support\Facades\DB::table('ticket_events')->insert(['ticket_id' => $ticket->id, 'work_item_id' => $project->id, 'actor_name' => 'Ann', 'type' => 'comment', 'visibility' => 'public', 'payload' => '{}', 'created_at' => now()]);
        \Illuminate\Support\Facades\DB::table('ticket_events')->insert(['ticket_id' => $ticket->id, 'work_item_id' => $project->id, 'actor_name' => 'Ann', 'type' => 'comment', 'visibility' => 'internal', 'payload' => '{}', 'created_at' => now()]);

        $rival = $this->makeAgency('Rival');
        $rivalProject = $this->book($this->hold($this->makeEmployee('Zed'), $rival, $this->makeClient($rival, 'Rival Client')), 'in_progress', [[$this->today->addDays(3), 2]]);
        $rivalTicket = $this->ticket($rivalProject, 'in_review', 1, 'Secret ticket');
        \Illuminate\Support\Facades\DB::table('ticket_events')->insert(['ticket_id' => $rivalTicket->id, 'work_item_id' => $rivalProject->id, 'actor_name' => 'Zed', 'type' => 'comment', 'visibility' => 'public', 'payload' => '{}', 'created_at' => now()]);

        $d = $this->dashboard($owner);
        $texts = array_column($d['activity'], 'text');

        $this->assertContains('Ann commented on “Fix the form”', $texts);
        $this->assertContains('Newest Client was added as a client', $texts);
        $this->assertSame(1, count(array_filter($texts, fn ($t) => str_contains($t, 'Fix the form'))), 'internal events stay out');
        $this->assertStringNotContainsString('Secret', json_encode($d));
        $this->assertStringNotContainsString('Rival', json_encode($d));
        $this->assertLessThanOrEqual(10, count($d['activity']));
        $this->assertSame(0, $d['kpis']['tickets_waiting_on_you']);       // the rival's in_review ticket is not ours
    }

    public function test_clients_keep_their_reduced_dashboard(): void
    {
        $agency = $this->makeAgency('Northwind');
        $client = $this->makeClient($agency);

        $d = $this->dashboard($client);

        $this->assertSame('agency_client', $d['role']);
        $this->assertArrayNotHasKey('kpis', $d);
        $this->assertArrayNotHasKey('activity', $d);
    }
}
