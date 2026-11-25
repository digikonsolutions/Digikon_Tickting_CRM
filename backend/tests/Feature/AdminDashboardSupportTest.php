<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Services\TicketEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** The support block of the admin dashboard: live queue counts and a short feed of what changed most recently. */
class AdminDashboardSupportTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    private int $sequence = 700;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    /** A support-module ticket: it has a `type`, and no project. */
    private function support(string $status, string $priority = 'normal', ?int $assignee = null): Ticket
    {
        $this->sequence++;

        return Ticket::create([
            'agency_id' => $this->agency->id, 'created_by' => $this->u['owner']->id, 'number' => $this->sequence, 'ref' => 'SUP-'.$this->sequence,
            'type' => 'bug', 'kind' => 'support', 'title' => 'Support '.$this->sequence, 'description' => 'x', 'estimated_hours' => 0,
            'priority' => $priority, 'status' => $status, 'position' => $this->sequence, 'assigned_to_id' => $assignee,
        ]);
    }

    private function clock(Ticket $ticket, string $dueAt, ?string $metAt = null): void
    {
        DB::table('ticket_sla_clocks')->insert([
            'ticket_id' => $ticket->id, 'kind' => 'response', 'target_minutes' => 60, 'started_at' => now()->subDays(2), 'due_at' => $dueAt,
            'met_at' => $metAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function snapshot(): array
    {
        return $this->actingAs($this->u['admin'])->getJson('/api/admin/dashboard')->assertOk()->json('support');
    }

    public function test_the_dashboard_counts_open_unassigned_critical_and_overdue_support_tickets(): void
    {
        $this->support('new');
        $this->support('triage', 'urgent');
        $this->support('assigned', 'urgent', $this->u['employee']->id);
        $late = $this->support('in_progress', 'high', $this->u['employee']->id);
        $this->clock($late, now()->subHour()->toDateTimeString());
        $this->support('resolved');
        $this->support('closed', 'urgent');

        $support = $this->snapshot();

        $this->assertSame(4, $support['open']);
        $this->assertSame(2, $support['unassigned'], 'new and triage tickets nobody owns');
        $this->assertSame(2, $support['critical'], 'finished urgent tickets are not counted');
        $this->assertSame(1, $support['breached_sla']);
        $this->assertSame(2, $support['resolved_today']);
    }

    public function test_a_clock_that_was_met_or_is_not_due_yet_is_not_a_breach(): void
    {
        $met = $this->support('in_progress', 'normal', $this->u['employee']->id);
        $this->clock($met, now()->subHour()->toDateTimeString(), now()->subHours(2)->toDateTimeString());
        $future = $this->support('in_progress', 'normal', $this->u['employee']->id);
        $this->clock($future, now()->addHour()->toDateTimeString());
        $done = $this->support('resolved');
        $this->clock($done, now()->subHour()->toDateTimeString());

        $this->assertSame(0, $this->snapshot()['breached_sla']);
    }

    public function test_project_tickets_without_a_type_are_not_support_tickets(): void
    {
        $this->makeTicket('owner', 'Landing page', 2);

        $support = $this->snapshot();

        $this->assertSame(0, $support['open']);
        $this->assertSame([], $support['recent_activity']);
    }

    public function test_the_feed_lists_the_latest_support_events_newest_first_in_plain_words(): void
    {
        $recorder = app(TicketEventRecorder::class);
        $ticket = $this->support('new');
        $recorder->record($ticket, 'created', ['type' => 'bug'], $this->u['owner']);
        $recorder->record($ticket, 'status_changed', ['from' => 'new', 'to' => 'triage', 'to_label' => 'Triage'], $this->u['admin']);
        $recorder->record($ticket, 'sla_breached', ['kind' => 'response'], null, 'internal');
        // Events of project tickets belong to another feed.
        $project = $this->makeTicket('owner', 'Landing page', 2);
        $recorder->record($project, 'updated', ['changes' => []], $this->u['owner']);

        $feed = $this->snapshot()['recent_activity'];

        $this->assertSame(['SLA breached (response)', 'Moved to Triage', 'New ticket opened'], array_column($feed, 'text'));
        $this->assertSame($ticket->ref, $feed[0]['ticket_ref']);
        $this->assertSame($this->agency->name, $feed[0]['agency_name']);
        $this->assertSame($this->u['admin']->name, $feed[1]['actor_name']);
    }

    public function test_the_feed_is_capped_and_a_quiet_queue_returns_empty_lists(): void
    {
        $this->assertSame(['open' => 0, 'unassigned' => 0, 'critical' => 0, 'breached_sla' => 0, 'resolved_today' => 0, 'recent_activity' => []], $this->snapshot());

        $recorder = app(TicketEventRecorder::class);
        $ticket = $this->support('new');
        foreach (range(1, 12) as $i) {
            $recorder->record($ticket, 'priority_changed', ['to_label' => "P{$i}"], $this->u['admin']);
        }

        $this->assertCount(8, $this->snapshot()['recent_activity']);
    }

    public function test_only_admins_get_the_support_block(): void
    {
        foreach (['owner', 'client', 'employee'] as $who) {
            $this->actingAs($this->u[$who])->getJson('/api/admin/dashboard')->assertForbidden();
        }
    }

    public function test_adding_support_tickets_does_not_add_queries(): void
    {
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->u['admin'])->getJson('/api/admin/dashboard')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $recorder = app(TicketEventRecorder::class);
        $recorder->record($this->support('new'), 'created', [], $this->u['owner']);
        $measure();
        $before = $measure();

        foreach (range(1, 10) as $i) {
            $recorder->record($this->support('in_progress', 'urgent', $this->u['employee']->id), 'created', [], $this->u['owner']);
        }

        $this->assertSame($before, $measure());
    }

    public function test_the_admin_ticket_directory_lists_project_tickets_not_support_tickets(): void
    {
        $project = $this->makeTicket('owner', 'Landing page', 2);
        $support = $this->support('new');

        $keys = collect($this->actingAs($this->u['admin'])->getJson('/api/admin/tickets')->assertOk()->json('tickets'))->pluck('id')->all();

        $this->assertContains($project->id, $keys);
        $this->assertNotContains($support->id, $keys);
    }
}