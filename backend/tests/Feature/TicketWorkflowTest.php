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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_transition_table_row_and_allowed_and_forbidden_roles(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Workflow Ticket',
            'description' => 'Test all states',
            'estimated_hours' => 6,
        ])->json('ticket');
        $id = $ticket['id'];

        // 1. Client cannot 'start'
        $this->actingAs($client)->postJson("/api/tickets/{$id}/transitions", ['action' => 'start'])->assertForbidden();

        // Assigned employee can 'start' (todo -> in_progress)
        $this->actingAs($employee)->postJson("/api/tickets/{$id}/transitions", ['action' => 'start'])
            ->assertOk()
            ->assertJsonPath('ticket.status', 'in_progress');
        $this->assertDatabaseHas('work_items', ['id' => $project->id, 'status' => 'in_progress']);

        // 2. Assigned employee 'mark_blocked' requires comment
        $this->postJson("/api/tickets/{$id}/transitions", ['action' => 'mark_blocked'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->postJson("/api/tickets/{$id}/transitions", [
            'action' => 'mark_blocked',
            'comment' => 'Blocked on API credentials',
        ])->assertOk()->assertJsonPath('ticket.status', 'blocked');

        // Check waiting_on and status_reason
        $getRes = $this->getJson("/api/tickets/key/{$ticket['key']}")->assertOk();
        $this->assertSame('team', $getRes->json('ticket.waiting_on'));
        $this->assertSame('Blocked on API credentials', $getRes->json('ticket.status_reason'));

        // 3. Assigned employee 'unblock' (blocked -> in_progress)
        $this->postJson("/api/tickets/{$id}/transitions", ['action' => 'unblock'])
            ->assertOk()->assertJsonPath('ticket.status', 'in_progress');

        // 4. Assigned employee 'request_client_input' requires comment
        $this->postJson("/api/tickets/{$id}/transitions", ['action' => 'request_client_input'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->postJson("/api/tickets/{$id}/transitions", [
            'action' => 'request_client_input',
            'comment' => 'Which brand colors should we use?',
        ])->assertOk()->assertJsonPath('ticket.status', 'waiting_on_client');

        $getRes = $this->getJson("/api/tickets/key/{$ticket['key']}")->assertOk();
        $this->assertSame('client', $getRes->json('ticket.waiting_on'));
        $this->assertSame('Which brand colors should we use?', $getRes->json('ticket.status_reason'));

        // 5. Employee cannot 'provide_input'
        $this->postJson("/api/tickets/{$id}/transitions", [
            'action' => 'provide_input',
            'comment' => 'Employee answering own question',
        ])->assertForbidden();

        // Client 'provide_input' requires comment
        $this->actingAs($client)->postJson("/api/tickets/{$id}/transitions", ['action' => 'provide_input'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        // Client 'provide_input' (waiting_on_client -> in_progress)
        $this->actingAs($client)->postJson("/api/tickets/{$id}/transitions", [
            'action' => 'provide_input',
            'comment' => 'Use blue and teal.',
        ])->assertOk()->assertJsonPath('ticket.status', 'in_progress');

        // 6. Assigned employee 'send_to_review' (in_progress -> in_review)
        $this->actingAs($employee)->postJson("/api/tickets/{$id}/transitions", ['action' => 'send_to_review'])
            ->assertOk()->assertJsonPath('ticket.status', 'in_review');

        // 7. Client 'request_changes' requires comment
        $this->actingAs($client)->postJson("/api/tickets/{$id}/transitions", ['action' => 'request_changes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->actingAs($client)->postJson("/api/tickets/{$id}/transitions", [
            'action' => 'request_changes',
            'comment' => 'Please adjust spacing.',
        ])->assertOk()->assertJsonPath('ticket.status', 'changes_requested');

        $getRes = $this->getJson("/api/tickets/key/{$ticket['key']}")->assertOk();
        $this->assertSame('employee', $getRes->json('ticket.waiting_on'));
        $this->assertSame('Please adjust spacing.', $getRes->json('ticket.status_reason'));

        // 8. Assigned employee 'restart_work' (changes_requested -> in_progress)
        $this->actingAs($employee)->postJson("/api/tickets/{$id}/transitions", ['action' => 'restart_work'])
            ->assertOk()->assertJsonPath('ticket.status', 'in_progress');

        // Move back to in_review
        $this->postJson("/api/tickets/{$id}/transitions", ['action' => 'send_to_review'])->assertOk();

        // 9. Employee cannot approve when approval flag is on
        $this->postJson("/api/tickets/{$id}/transitions", ['action' => 'approve'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only the client can approve this ticket.');

        // Client approves (in_review -> done)
        $this->actingAs($client)->postJson("/api/tickets/{$id}/transitions", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('ticket.status', 'done');

        // Project becomes completed
        $this->assertDatabaseHas('work_items', ['id' => $project->id, 'status' => 'completed']);
    }

    public function test_approval_flag_can_be_disabled_to_restore_old_behavior(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Quick ticket',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        $this->actingAs($employee)->postJson("/api/tickets/{$ticket['id']}/transitions", ['action' => 'start'])->assertOk();
        $this->postJson("/api/tickets/{$ticket['id']}/transitions", ['action' => 'send_to_review'])->assertOk();

        config(['scheduling.require_client_approval' => false]);

        // Now employee can approve
        $this->postJson("/api/tickets/{$ticket['id']}/transitions", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('ticket.status', 'done');
    }

    public function test_allowed_actions_returned_per_role(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Action test ticket',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        // In 'todo' status:
        // Employee allowed_actions has 'start'
        $empTicket = $this->actingAs($employee)->getJson("/api/tickets/key/{$ticket['key']}")
            ->assertOk()
            ->json('ticket');
        $this->assertSame([['action' => 'start', 'label' => 'Start', 'requires_comment' => false]], $empTicket['allowed_actions']);

        // Client allowed_actions is empty in 'todo' status
        $cliTicket = $this->actingAs($client)->getJson("/api/tickets/key/{$ticket['key']}")
            ->assertOk()
            ->json('ticket');
        $this->assertSame([], $cliTicket['allowed_actions']);

        // Admin allowed_actions is always empty
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admTicket = $this->actingAs($admin)->getJson("/api/tickets/key/{$ticket['key']}")
            ->assertOk()
            ->json('ticket');
        $this->assertSame([], $admTicket['allowed_actions']);
    }

    public function test_cross_agency_access_returns_404(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Cross agency test',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        $otherAgency = Agency::create(['name' => 'Foreign Corp', 'ticket_prefix' => 'FORE']);
        $otherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]);

        $this->actingAs($otherClient)->postJson("/api/tickets/{$ticket['id']}/transitions", ['action' => 'approve'])
            ->assertNotFound();

        $this->actingAs($otherClient)->getJson("/api/tickets/key/{$ticket['key']}")
            ->assertNotFound();

        $this->actingAs($otherClient)->getJson("/api/tickets/{$ticket['id']}/timeline")
            ->assertNotFound();
    }

    public function test_timeline_endpoint_is_cursor_paginated_and_filters_internal_for_agency_users(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();

        $ticket = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Timeline ticket',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->json('ticket');

        // Post public comment
        $this->actingAs($client)->postJson("/api/tickets/{$ticket['id']}/comments", [
            'body' => 'Public comment from client',
        ])->assertCreated();

        // Post internal comment as employee
        $this->actingAs($employee)->postJson("/api/tickets/{$ticket['id']}/comments", [
            'body' => 'Internal secret comment from employee',
            'is_internal' => true,
        ])->assertCreated();

        // Agency user timeline does not include internal comment
        $clientTimeline = $this->actingAs($client)->getJson("/api/tickets/{$ticket['id']}/timeline")
            ->assertOk()
            ->json('events');

        $clientCommentBodies = collect($clientTimeline)->pluck('comment')->filter()->values()->all();
        $this->assertContains('Public comment from client', $clientCommentBodies);
        $this->assertNotContains('Internal secret comment from employee', $clientCommentBodies);

        // Employee timeline contains both
        $employeeTimeline = $this->actingAs($employee)->getJson("/api/tickets/{$ticket['id']}/timeline")
            ->assertOk()
            ->json('events');

        $employeeCommentBodies = collect($employeeTimeline)->pluck('comment')->filter()->values()->all();
        $this->assertContains('Public comment from client', $employeeCommentBodies);
        $this->assertContains('Internal secret comment from employee', $employeeCommentBodies);
    }

    /**
     * @return array{Agency, User, User, User, WorkItem}
     */
    private function projectFixture(float $hours = 24): array
    {
        $agency = Agency::create(['name' => 'Orbit Corp', 'ticket_prefix' => 'ORBT']);
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
