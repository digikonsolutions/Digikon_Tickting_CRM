<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\AssignmentService;
use App\Services\UserAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TicketActivityTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_mutation_produces_expected_ticket_event(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        // 1. Create ticket -> 'created' event
        $res = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Initial Title',
            'description' => 'Initial Desc',
            'estimated_hours' => 2,
            'priority' => 'normal',
        ])->assertCreated()->json('ticket');

        $ticketId = $res['id'];
        $ticket = Ticket::findOrFail($ticketId);

        $events = TicketEvent::where('ticket_id', $ticketId)->get();
        $this->assertCount(1, $events);
        $this->assertSame('created', $events[0]->type);
        $this->assertSame($client->name, $events[0]->actor_name);
        $this->assertSame('agency_client', $events[0]->actor_role);

        // 2. Update ticket -> 'updated' event
        $this->patchJson("/api/agency/tickets/{$ticketId}", [
            'title' => 'Updated Title',
            'priority' => 'high',
        ])->assertOk();

        $updatedEvent = TicketEvent::where('ticket_id', $ticketId)->where('type', 'updated')->first();
        $this->assertNotNull($updatedEvent);
        $this->assertSame('Initial Title', $updatedEvent->payload['changes']['title']['from']);
        $this->assertSame('Updated Title', $updatedEvent->payload['changes']['title']['to']);
        $this->assertSame('normal', $updatedEvent->payload['changes']['priority']['from']);
        $this->assertSame('high', $updatedEvent->payload['changes']['priority']['to']);

        // 3. Status changed -> 'status_changed' event
        $this->actingAs($employee)->patchJson("/api/tickets/{$ticketId}/status", [
            'status' => 'in_progress',
        ])->assertOk();

        $statusEvent = TicketEvent::where('ticket_id', $ticketId)->where('type', 'status_changed')->first();
        $this->assertNotNull($statusEvent);
        $this->assertSame('todo', $statusEvent->payload['from']);
        $this->assertSame('in_progress', $statusEvent->payload['to']);

        // 4. Comment -> 'comment' event
        $this->actingAs($client)->postJson("/api/tickets/{$ticketId}/comments", [
            'body' => 'A user comment',
        ])->assertCreated();

        $commentEvent = TicketEvent::where('ticket_id', $ticketId)->where('type', 'comment')->first();
        $this->assertNotNull($commentEvent);
        $this->assertNotNull($commentEvent->payload['comment_id']);

        // 5. Cancel ticket directly -> 'cancelled' event with cause = ticket
        $this->deleteJson("/api/agency/tickets/{$ticketId}")->assertOk();
        $cancelEvent = TicketEvent::where('ticket_id', $ticketId)->where('type', 'cancelled')->first();
        $this->assertNotNull($cancelEvent);
        $this->assertSame('ticket', $cancelEvent->payload['cause']);
    }

    public function test_reorder_produces_reordered_event(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $t1 = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'T1',
            'description' => 'D1',
            'estimated_hours' => 2,
        ])->json('ticket');

        $t2 = $this->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'T2',
            'description' => 'D2',
            'estimated_hours' => 2,
        ])->json('ticket');

        $this->patchJson("/api/agency/projects/{$project->id}/tickets/reorder", [
            'ticket_ids' => [$t2['id'], $t1['id']],
        ])->assertOk();

        $this->assertSame(1, TicketEvent::where('ticket_id', $t1['id'])->where('type', 'reordered')->count());
        $this->assertSame(1, TicketEvent::where('ticket_id', $t2['id'])->where('type', 'reordered')->count());
    }

    public function test_project_cancellation_produces_cancelled_event_with_project_cause(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Project Ticket',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        $this->patchJson("/api/agency/projects/{$project->id}/cancel")->assertOk();

        $event = TicketEvent::where('ticket_id', $ticket['id'])->where('type', 'cancelled')->first();
        $this->assertNotNull($event);
        $this->assertSame('project', $event->payload['cause']);
        $this->assertSame($client->name, $event->actor_name);
    }

    public function test_assignment_end_produces_cancelled_event_with_system_actor(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Assigned Ticket',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        $assignmentService = app(AssignmentService::class);
        $assignmentService->end($project->assignment);

        $event = TicketEvent::where('ticket_id', $ticket['id'])->where('type', 'cancelled')->first();
        $this->assertNotNull($event);
        $this->assertSame('assignment_ended', $event->payload['cause']);
        $this->assertSame('System', $event->actor_name);
        $this->assertSame('system', $event->actor_role);
    }

    public function test_employee_deactivation_produces_cancelled_event(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Employee Ticket',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        $accountService = app(UserAccountService::class);
        $accountService->updateManagedUser($employee, ['is_active' => false], $employee->email, false);

        $event = TicketEvent::where('ticket_id', $ticket['id'])->where('type', 'cancelled')->first();
        $this->assertNotNull($event);
        $this->assertSame('System', $event->actor_name);
    }

    public function test_internal_comments_cannot_be_created_or_read_by_agency_roles(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Comment Ticket',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        // Agency client cannot post internal comment
        $this->actingAs($client)->postJson("/api/tickets/{$ticket['id']}/comments", [
            'body' => 'Secret client note',
            'is_internal' => true,
        ])->assertForbidden();

        // Agency owner cannot post internal comment
        $this->actingAs($owner)->postJson("/api/tickets/{$ticket['id']}/comments", [
            'body' => 'Secret owner note',
            'is_internal' => true,
        ])->assertForbidden();

        // Employee can post internal comment
        $this->actingAs($employee)->postJson("/api/tickets/{$ticket['id']}/comments", [
            'body' => 'Internal employee note',
            'is_internal' => true,
        ])->assertCreated()->assertJsonPath('comment.is_internal', true);

        // Employee can also post public comment
        $this->actingAs($employee)->postJson("/api/tickets/{$ticket['id']}/comments", [
            'body' => 'Public employee note',
            'is_internal' => false,
        ])->assertCreated()->assertJsonPath('comment.is_internal', false);

        // Agency client reading comments gets ONLY public comment
        $clientComments = $this->actingAs($client)->getJson("/api/tickets/{$ticket['id']}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'comments')
            ->assertJsonPath('total', 1)
            ->json('comments');

        $this->assertSame('Public employee note', $clientComments[0]['body']);

        // Assigned employee reading comments gets BOTH comments
        $this->actingAs($employee)->getJson("/api/tickets/{$ticket['id']}/comments")
            ->assertOk()
            ->assertJsonCount(2, 'comments')
            ->assertJsonPath('total', 2);

        // Admin reading comments gets BOTH comments
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->getJson("/api/tickets/{$ticket['id']}/comments")
            ->assertOk()
            ->assertJsonCount(2, 'comments')
            ->assertJsonPath('total', 2);
    }

    public function test_deleting_actor_keeps_event_with_snapshot_name(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'To be deleted actor',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        $clientName = $client->name;
        $client->delete();

        $event = TicketEvent::where('ticket_id', $ticket['id'])->where('type', 'created')->first();
        $this->assertNotNull($event);
        $this->assertNull($event->actor_id);
        $this->assertSame($clientName, $event->actor_name);
        $this->assertSame('agency_client', $event->actor_role);
    }

    public function test_ticket_event_update_and_delete_guards_throw(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Guard Ticket',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        $event = TicketEvent::where('ticket_id', $ticket['id'])->first();
        $this->assertNotNull($event);

        $this->expectException(RuntimeException::class);
        $event->update(['type' => 'tampered']);
    }

    public function test_ticket_event_delete_guard_throws(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Guard Ticket 2',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        $event = TicketEvent::where('ticket_id', $ticket['id'])->first();
        $this->assertNotNull($event);

        $this->expectException(RuntimeException::class);
        $event->delete();
    }

    /**
     * @return array{Agency, User, User, User, WorkItem}
     */
    private function projectFixture(string $agencyName = 'Northwind', float $hours = 10): array
    {
        $prefix = Agency::generatePrefix($agencyName);
        $agency = Agency::create(['name' => $agencyName, 'ticket_prefix' => $prefix]);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeek();

        $assignment = EmployeeAssignment::create([
            'holder_id' => $client->id,
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);

        $workItem = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => 'Project',
            'description' => 'Project description.',
            'estimated_hours' => $hours,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDays(5)->toDateString(),
            'status' => 'scheduled',
        ]);

        $remaining = (int) round($hours * 100);
        for ($date = $monday; $remaining > 0; $date = $date->addDay()) {
            if ($date->dayOfWeek === CarbonImmutable::SUNDAY) {
                continue;
            }

            $units = min(600, $remaining);
            $workItem->allocations()->create([
                'employee_id' => $employee->id,
                'work_date' => $date->toDateString(),
                'hours' => number_format($units / 100, 2, '.', ''),
            ]);
            $remaining -= $units;
        }

        return [$agency, $owner, $client, $employee, $workItem];
    }
}
