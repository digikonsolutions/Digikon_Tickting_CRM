<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketAttentionAndFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_attention_lists_tickets_waiting_on_the_client_longest_first_and_only_for_the_callers_agency(): void
    {
        [$agency, $client, $employee, $project] = $this->fixture('Northwind');
        $review = $this->ticket($project, 'Needs review', 'in_review', now()->subDays(3));
        $question = $this->ticket($project, 'Has a question', 'waiting_on_client', now()->subDay());
        $this->ticket($project, 'Employee is on it', 'in_progress', now()->subDays(5));
        $this->ticket($project, 'Done', 'done', now()->subDays(6));
        [, , , $otherProject] = $this->fixture('Other agency');
        $this->ticket($otherProject, 'Not mine', 'in_review', now()->subDays(9));

        $this->actingAs($client)->getJson('/api/agency/attention')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'tickets')
            ->assertJsonPath('tickets.0.id', $review->id)
            ->assertJsonPath('tickets.0.waiting_on', 'client')
            ->assertJsonPath('tickets.0.project_title', 'Project')
            ->assertJsonPath('tickets.1.id', $question->id)
            ->assertJsonPath('tickets.1.key', $question->key);
    }

    public function test_attention_is_limited_to_five_but_reports_the_real_total(): void
    {
        [, $client, , $project] = $this->fixture('Northwind');
        foreach (range(1, 7) as $number) {
            $this->ticket($project, 'Review '.$number, 'in_review', now()->subHours($number));
        }

        $this->actingAs($client)->getJson('/api/agency/attention')
            ->assertOk()->assertJsonCount(5, 'tickets')->assertJsonPath('total', 7);
    }

    public function test_attention_requires_an_active_agency_user(): void
    {
        [$agency, $client, $employee] = $this->fixture('Northwind');
        $this->getJson('/api/agency/attention')->assertUnauthorized();
        $this->actingAs($employee)->getJson('/api/agency/attention')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))->getJson('/api/agency/attention')->assertForbidden();
        $agency->update(['status' => 'suspended']);
        $this->actingAs($client)->getJson('/api/agency/attention')->assertForbidden();
    }

    public function test_admin_ticket_list_filters_by_status_agency_employee_waiting_on_and_search(): void
    {
        [$agencyA, , $employeeA, $projectA] = $this->fixture('Northwind');
        [$agencyB, , $employeeB, $projectB] = $this->fixture('Zephyr');
        $a1 = $this->ticket($projectA, 'Landing page', 'in_review');
        $a2 = $this->ticket($projectA, 'Checkout bug', 'blocked');
        $b1 = $this->ticket($projectB, 'Landing page', 'in_progress');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ids = fn (string $query): array => collect($this->actingAs($admin)->getJson('/api/admin/tickets?'.$query)->assertOk()->json('tickets'))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame([$a1->id, $a2->id, $b1->id], $ids(''));
        $this->assertSame([$a1->id], $ids('status=in_review'));
        $this->assertSame([$a1->id, $a2->id], $ids('agency_id='.$agencyA->id));
        $this->assertSame([$b1->id], $ids('employee_id='.$employeeB->id));
        $this->assertSame([$a1->id], $ids('waiting_on=client'));
        $this->assertSame([$a2->id], $ids('waiting_on=team'));
        $this->assertSame([$b1->id], $ids('waiting_on=employee'));
        $this->assertSame([$a1->id, $b1->id], $ids('search=landing'));
        $this->assertSame([$a2->id], $ids('search='.$a2->key));
        $this->assertSame([$b1->id], $ids('search='.$b1->key));
        $this->assertSame([$b1->id], $ids('status=in_progress&agency_id='.$agencyB->id.'&search=landing'));
        $this->getJson('/api/admin/tickets?waiting_on=nobody')->assertUnprocessable()->assertJsonValidationErrors('waiting_on');
        $this->getJson('/api/admin/tickets?status=bogus')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/admin/tickets')->assertJsonPath('tickets.0.key', $a1->key)
            ->assertJsonStructure(['tickets' => [['waiting_on', 'waiting_since', 'creator', 'key', 'allowed_actions']]]);
    }

    public function test_ticket_list_query_count_does_not_grow_with_the_number_of_tickets(): void
    {
        [, $client, $employee, $project] = $this->fixture('Northwind');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->ticket($project, 'One', 'in_review');
        $this->ticket($project, 'Two', 'blocked');
        $measure = function (User $user, string $url): int {
            $this->actingAs($user)->getJson($url)->assertOk(); // warm up
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $urls = [
            [$client, '/api/agency/projects/'.$project->id.'/tickets'],
            [$employee, '/api/work-items/mine/tickets'],
            [$admin, '/api/admin/tickets'],
            [$client, '/api/agency/attention'],
        ];
        $before = array_map(fn (array $pair): int => $measure(...$pair), $urls);

        foreach (range(1, 6) as $number) {
            $this->ticket($project, 'More '.$number, $number % 2 ? 'in_review' : 'waiting_on_client');
        }

        $this->assertSame($before, array_map(fn (array $pair): int => $measure(...$pair), $urls));
    }

    public function test_reorder_records_events_only_for_tickets_that_actually_moved(): void
    {
        [, $client, , $project] = $this->fixture('Northwind');
        $first = $this->actingAs($client)->postJson('/api/agency/projects/'.$project->id.'/tickets', ['title' => 'A', 'description' => 'd', 'estimated_hours' => 1])->json('ticket');
        $second = $this->postJson('/api/agency/projects/'.$project->id.'/tickets', ['title' => 'B', 'description' => 'd', 'estimated_hours' => 1])->json('ticket');
        $third = $this->postJson('/api/agency/projects/'.$project->id.'/tickets', ['title' => 'C', 'description' => 'd', 'estimated_hours' => 1])->json('ticket');

        // Swap B and C; A keeps its place.
        $this->patchJson('/api/agency/projects/'.$project->id.'/tickets/reorder', ['ticket_ids' => [$first['id'], $third['id'], $second['id']]])->assertOk();

        $count = fn (array $ticket): int => TicketEvent::where('ticket_id', $ticket['id'])->where('type', 'reordered')->count();
        $this->assertSame(0, $count($first));
        $this->assertSame(1, $count($second));
        $this->assertSame(1, $count($third));
        $this->assertSame(['from_position' => 3, 'to_position' => 2], TicketEvent::where('ticket_id', $third['id'])->where('type', 'reordered')->first()->payload);
    }

    public function test_comments_count_never_includes_internal_notes_for_agency_roles(): void
    {
        [, $client, $employee, $project] = $this->fixture('Northwind');
        $ticket = $this->ticket($project, 'Has notes', 'in_progress');
        \App\Models\TicketComment::create(['ticket_id' => $ticket->id, 'user_id' => $client->id, 'body' => 'Public one', 'is_internal' => false]);
        \App\Models\TicketComment::create(['ticket_id' => $ticket->id, 'user_id' => $employee->id, 'body' => 'Internal one', 'is_internal' => true]);
        \App\Models\TicketComment::create(['ticket_id' => $ticket->id, 'user_id' => $employee->id, 'body' => 'Internal two', 'is_internal' => true]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $count = fn (User $viewer, string $url, string $path = 'tickets.0.comments_count'): int => $this->actingAs($viewer)->getJson($url)->assertOk()->json($path);
        $this->assertSame(1, $count($client, '/api/agency/projects/'.$project->id.'/tickets'));
        $this->assertSame(3, $count($employee, '/api/work-items/mine/tickets'));
        $this->assertSame(3, $count($admin, '/api/admin/tickets'));
        $this->assertSame(1, $count($client, '/api/tickets/key/'.$ticket->key, 'ticket.comments_count'));
        $this->assertSame(3, $count($admin, '/api/tickets/key/'.$ticket->key, 'ticket.comments_count'));
    }

    /**
     * @return array{Agency, User, User, WorkItem}
     */
    private function fixture(string $agencyName): array
    {
        $agency = Agency::create(['name' => $agencyName, 'ticket_prefix' => Agency::generatePrefix($agencyName)]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = EmployeeAssignment::create([
            'holder_id' => $client->id, 'agency_id' => $agency->id, 'employee_id' => $employee->id,
            'status' => 'active', 'active_employee_id' => $employee->id, 'started_at' => now(),
        ]);
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
        $project = WorkItem::create([
            'agency_id' => $agency->id, 'client_id' => $client->id, 'assignment_id' => $assignment->id,
            'employee_id' => $employee->id, 'title' => 'Project', 'description' => 'Description.',
            'estimated_hours' => 30, 'start_date' => $monday->toDateString(), 'due_date' => $monday->addDays(5)->toDateString(),
            'status' => 'scheduled',
        ]);
        foreach (range(0, 4) as $offset) {
            $project->allocations()->create(['employee_id' => $employee->id, 'work_date' => $monday->addDays($offset)->toDateString(), 'hours' => 6]);
        }

        return [$agency, $client, $employee, $project];
    }

    private function ticket(WorkItem $project, string $title, string $status, ?\DateTimeInterface $waitingSince = null): Ticket
    {
        $agency = Agency::findOrFail($project->agency_id);
        $agency->update(['ticket_sequence' => $agency->ticket_sequence + 1]);
        $ticket = Ticket::create([
            'work_item_id' => $project->id, 'agency_id' => $agency->id, 'number' => $agency->ticket_sequence,
            'title' => $title, 'description' => 'Description.', 'estimated_hours' => 1,
            'position' => $agency->ticket_sequence, 'status' => $status,
        ]);
        if ($waitingSince) {
            TicketEvent::create([
                'ticket_id' => $ticket->id, 'work_item_id' => $project->id, 'actor_name' => 'System', 'actor_role' => 'system',
                'type' => 'status_changed', 'visibility' => 'public', 'payload' => ['from' => 'in_progress', 'to' => $status],
                'created_at' => $waitingSince,
            ]);
        }

        return $ticket;
    }
}
