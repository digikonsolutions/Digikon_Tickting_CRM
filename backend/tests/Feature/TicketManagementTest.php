<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_agency_clients_and_owner_can_view_and_manage_project_tickets(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();
        $otherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $otherProject = $this->createProject(
            $agency,
            $otherClient,
            User::factory()->create(['role' => Role::Employee]),
            8
        );

        $created = $this->actingAs($client)->postJson($this->projectTicketsUrl($project), [
            'title' => 'Design login screen',
            'description' => 'Build the first screen.',
            'estimated_hours' => 7,
            'priority' => 'high',
        ])->assertCreated()
            ->assertJsonPath('ticket.created_by', $client->id)
            ->assertJsonPath('ticket.priority', 'high')
            ->assertJsonPath('ticket.position', 1)
            ->assertJsonPath('ticket.start_date', $project->start_date->toDateString())
            ->assertJsonPath('ticket.due_date', $project->start_date->addDay()->toDateString())
            ->assertJsonPath('ticket.schedule.0.hours', '6.00')
            ->assertJsonPath('ticket.schedule.1.hours', '1.00');
        $ticketId = $created->json('ticket.id');
        $this->actingAs($otherClient)->postJson($this->projectTicketsUrl($project), $this->ticketData(1))
            ->assertCreated();

        $this->actingAs($owner)->getJson($this->projectTicketsUrl($project))
            ->assertOk()->assertJsonCount(2, 'tickets')
            ->assertJsonPath('tickets.0.id', $ticketId);
        $this->actingAs($client)->getJson($this->projectTicketsUrl($otherProject))->assertOk();
        $this->actingAs($owner)->postJson($this->projectTicketsUrl($otherProject), $this->ticketData(2))
            ->assertCreated();
        $this->actingAs($otherClient)->postJson($this->projectTicketsUrl($otherProject), $this->ticketData(2))
            ->assertCreated();
        $this->getJson($this->projectTicketsUrl($otherProject))->assertOk()->assertJsonCount(2, 'tickets');
        $this->actingAs(User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => Agency::create(['name' => 'Other tenant'])->id]))
            ->getJson($this->projectTicketsUrl($project))
            ->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->postJson($this->projectTicketsUrl($project), $this->ticketData(1))
            ->assertForbidden();
        $this->actingAs($owner)->postJson($this->projectTicketsUrl($project), $this->ticketData(1))
            ->assertCreated();

        $ownerProject = $this->createProject(
            $agency,
            $owner,
            User::factory()->create(['role' => Role::Employee]),
            4
        );
        $this->postJson($this->projectTicketsUrl($ownerProject), $this->ticketData(2))
            ->assertCreated();
    }

    public function test_ticket_hour_overflow_is_rejected_without_persisting_failed_ticket(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture(8);

        $this->actingAs($client)->postJson($this->projectTicketsUrl($project), $this->ticketData(6))
            ->assertCreated();
        $this->postJson($this->projectTicketsUrl($project), $this->ticketData(3))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['estimated_hours'])
            ->assertJsonPath('errors.estimated_hours.0', "Ticket hours exceed the project's hours. Remaining: 2.00 h.");

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(6.0, (float) DB::table('ticket_allocations')->sum('hours'));

        $this->patchJson('/api/agency/tickets/'.Ticket::firstOrFail()->id, [
            'estimated_hours' => 9,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['estimated_hours'])
            ->assertJsonPath('errors.estimated_hours.0', "Ticket hours exceed the project's hours. Remaining: 8.00 h.");
    }

    public function test_ticket_update_and_reorder_rebuild_earliest_first_allocations(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture(8);
        $monday = $project->start_date->toDateString();
        $tuesday = $project->start_date->addDay()->toDateString();
        $first = $this->actingAs($client)->postJson($this->projectTicketsUrl($project), $this->ticketData(4, 'First'))
            ->assertCreated()->json('ticket');
        $second = $this->postJson($this->projectTicketsUrl($project), $this->ticketData(4, 'Second'))
            ->assertCreated()->json('ticket');

        $this->assertSame($monday, $first['start_date']);
        $this->assertSame($monday, $second['start_date']);
        $this->assertSame($tuesday, $second['due_date']);

        $this->patchJson('/api/agency/tickets/'.$first['id'], ['estimated_hours' => 2])
            ->assertOk()
            ->assertJsonPath('ticket.estimated_hours', '2.00');
        $this->patchJson($this->projectTicketsUrl($project).'/reorder', [
            'ticket_ids' => [$second['id'], $first['id']],
        ])->assertOk()
            ->assertJsonPath('tickets.0.id', $second['id'])
            ->assertJsonPath('tickets.0.start_date', $monday)
            ->assertJsonPath('tickets.1.id', $first['id'])
            ->assertJsonPath('tickets.1.position', 1);

        $this->patchJson($this->projectTicketsUrl($project).'/reorder', [
            'ticket_ids' => [$second['id']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['ticket_ids']);

        $this->actingAs($client)->patchJson('/api/agency/tickets/'.$second['id'], [
            'priority' => 'urgent',
        ])->assertOk()->assertJsonPath('ticket.priority', 'urgent');
    }

    public function test_ticket_delete_cancels_and_replans_and_project_cancel_cancels_its_tickets(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture(8);
        $first = $this->actingAs($client)->postJson($this->projectTicketsUrl($project), $this->ticketData(4, 'First'))->json('ticket');
        $second = $this->postJson($this->projectTicketsUrl($project), $this->ticketData(4, 'Second'))->json('ticket');

        $this->deleteJson('/api/agency/tickets/'.$first['id'])->assertOk()
            ->assertJsonPath('ticket.status', 'cancelled')
            ->assertJsonCount(0, 'ticket.schedule');
        $this->assertDatabaseHas('ticket_allocations', [
            'ticket_id' => $second['id'],
            'work_date' => $project->start_date->toDateString(),
            'hours' => '4.00',
        ]);

        $this->actingAs($owner)->patchJson('/api/agency/projects/'.$project->id.'/cancel')->assertOk();
        $this->assertDatabaseHas('tickets', ['id' => $second['id'], 'status' => 'cancelled']);
        $this->assertDatabaseMissing('ticket_allocations', ['ticket_id' => $second['id']]);
    }

    public function test_project_cancel_preserves_done_tickets_and_past_ticket_allocations(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture(8);
        $doneTicket = $this->actingAs($client)
            ->postJson($this->projectTicketsUrl($project), $this->ticketData(2, 'Done ticket'))
            ->assertCreated()
            ->json('ticket');
        $openTicket = $this->postJson($this->projectTicketsUrl($project), $this->ticketData(2, 'Open ticket'))
            ->assertCreated()
            ->json('ticket');

        $pastDate = now(config('app.timezone'))->subDay()->toDateString();
        DB::table('tickets')->where('id', $doneTicket['id'])->update(['status' => 'done']);
        DB::table('ticket_allocations')->where('ticket_id', $doneTicket['id'])->update(['work_date' => $pastDate]);
        DB::table('ticket_allocations')->insert([
            'ticket_id' => $openTicket['id'],
            'work_date' => $pastDate,
            'hours' => '1.00',
        ]);

        $this->patchJson('/api/agency/projects/'.$project->id.'/cancel')->assertOk();

        $this->assertDatabaseHas('tickets', ['id' => $doneTicket['id'], 'status' => 'done']);
        $this->assertDatabaseHas('ticket_allocations', [
            'ticket_id' => $doneTicket['id'],
            'work_date' => $pastDate,
        ]);
        $this->assertDatabaseHas('tickets', ['id' => $openTicket['id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('ticket_allocations', [
            'ticket_id' => $openTicket['id'],
            'work_date' => $pastDate,
        ]);
        $this->assertDatabaseMissing('ticket_allocations', [
            'ticket_id' => $openTicket['id'],
            'work_date' => $project->start_date->toDateString(),
        ]);
    }

    public function test_agency_client_can_cancel_another_client_project_but_not_a_project_in_another_agency(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();
        $otherAgency = Agency::create(['name' => 'Other tenant']);
        $otherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]);
        $otherEmployee = User::factory()->create(['role' => Role::Employee]);
        $otherProject = $this->createProject($otherAgency, $otherClient, $otherEmployee, 4);

        $this->actingAs($client)
            ->patchJson('/api/agency/projects/'.$project->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('project.status', 'cancelled');
        $this->patchJson('/api/agency/projects/'.$otherProject->id.'/cancel')
            ->assertNotFound();
    }

    public function test_same_agency_client_can_update_reorder_and_cancel_another_clients_tickets(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture(8);
        $otherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $ticket = $this->actingAs($client)
            ->postJson($this->projectTicketsUrl($project), $this->ticketData(3))
            ->assertCreated()
            ->json('ticket');

        $this->actingAs($otherClient)
            ->patchJson('/api/agency/tickets/'.$ticket['id'], ['title' => 'Updated by agency client'])
            ->assertOk()
            ->assertJsonPath('ticket.title', 'Updated by agency client');
        $this->postJson($this->projectTicketsUrl($project), $this->ticketData(3, 'Second'))
            ->assertCreated();
        $secondId = Ticket::query()->where('title', 'Second')->value('id');
        $this->patchJson($this->projectTicketsUrl($project).'/reorder', [
            'ticket_ids' => [$secondId, $ticket['id']],
        ])->assertOk()->assertJsonPath('tickets.0.id', $secondId);
        $this->deleteJson('/api/agency/tickets/'.$ticket['id'])
            ->assertOk()
            ->assertJsonPath('ticket.status', 'cancelled');
    }

    public function test_employee_ticket_status_flow_updates_project_and_enforces_assignment(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();
        $ticket = $this->actingAs($client)->postJson($this->projectTicketsUrl($project), $this->ticketData(4))
            ->assertCreated()->json('ticket');
        $otherEmployee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($otherEmployee)->patchJson('/api/tickets/'.$ticket['id'].'/status', ['status' => 'in_progress'])
            ->assertNotFound();
        $this->actingAs($employee)->getJson('/api/work-items/mine/tickets')
            ->assertOk()->assertJsonCount(1, 'tickets');
        $this->patchJson('/api/tickets/'.$ticket['id'].'/status', ['status' => 'done'])
            ->assertUnprocessable();
        $this->patchJson('/api/tickets/'.$ticket['id'].'/status', ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('ticket.status', 'in_progress');
        $this->assertDatabaseHas('work_items', ['id' => $project->id, 'status' => 'in_progress']);
        $this->patchJson('/api/tickets/'.$ticket['id'].'/status', ['status' => 'in_review'])->assertOk();
        $this->patchJson('/api/tickets/'.$ticket['id'].'/status', ['status' => 'done'])
            ->assertOk()->assertJsonPath('ticket.status', 'done');
        $this->assertDatabaseHas('work_items', ['id' => $project->id, 'status' => 'completed']);

        $this->actingAs($owner)->postJson($this->projectTicketsUrl($project), $this->ticketData(1))
            ->assertUnprocessable();
    }

    public function test_admin_ticket_directory_is_read_only_and_comments_allow_only_project_participants(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();
        $ticket = $this->actingAs($client)->postJson($this->projectTicketsUrl($project), $this->ticketData(2))
            ->assertCreated()->json('ticket');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $stranger = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $unrelatedEmployee = User::factory()->create(['role' => Role::Employee]);
        $teamLead = User::factory()->create(['role' => Role::TeamLead]);

        $this->actingAs($admin)->getJson('/api/admin/tickets')
            ->assertOk()->assertJsonCount(1, 'tickets')
            ->assertJsonPath('pagination.total', 1);
        $this->patchJson('/api/agency/tickets/'.$ticket['id'], ['title' => 'Admin edit'])->assertForbidden();

        foreach ([$admin, $owner, $client, $employee] as $participant) {
            $response = $this->actingAs($participant)->postJson('/api/tickets/'.$ticket['id'].'/comments', [
                'body' => 'A valid project comment.',
            ])->assertCreated();
            $this->assertNotNull($response->json('comment.created_at'));
            $this->assertNotNull(DB::table('ticket_comments')
                ->where('id', $response->json('comment.id'))
                ->value('updated_at'));
        }

        $this->actingAs($stranger)->postJson('/api/tickets/'.$ticket['id'].'/comments', [
            'body' => 'Cross-client comment.',
        ])->assertCreated();
        $this->actingAs($unrelatedEmployee)->postJson('/api/tickets/'.$ticket['id'].'/comments', [
            'body' => 'Unassigned employee comment.',
        ])->assertForbidden();
        $this->actingAs($teamLead)->getJson('/api/admin/tickets')->assertForbidden();
        $this->actingAs($teamLead)->postJson('/api/tickets/'.$ticket['id'].'/comments', [
            'body' => 'Forbidden role comment.',
        ])->assertForbidden();
    }

    public function test_tickets_require_open_project_and_nonagency_roles_cannot_manage_them(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();
        $project->update(['status' => 'completed']);

        $this->actingAs($client)->postJson($this->projectTicketsUrl($project), $this->ticketData(2))
            ->assertUnprocessable();
        $teamLead = User::factory()->create(['role' => Role::TeamLead]);
        $this->actingAs($teamLead)->getJson($this->projectTicketsUrl($project))->assertForbidden();
        $this->actingAs($employee)->getJson($this->projectTicketsUrl($project))->assertForbidden();
    }

    public function test_ticket_mutations_require_an_active_assignment_in_the_same_agency(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture();
        $ticket = $this->actingAs($client)
            ->postJson($this->projectTicketsUrl($project), $this->ticketData(2))
            ->assertCreated()
            ->json('ticket');

        $project->assignment()->update([
            'status' => 'ended',
            'active_employee_id' => null,
            'ended_at' => now(),
        ]);

        $this->patchJson('/api/agency/tickets/'.$ticket['id'], ['title' => 'Should not update'])
            ->assertNotFound();
        $this->deleteJson('/api/agency/tickets/'.$ticket['id'])->assertNotFound();
        $this->patchJson($this->projectTicketsUrl($project).'/reorder', [
            'ticket_ids' => [$ticket['id']],
        ])->assertNotFound();
    }

    /**
     * @return array{Agency, User, User, User, WorkItem}
     */
    private function projectFixture(float $hours = 10): array
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeek();
        $workItem = $this->createProject($agency, $client, $employee, $hours, $monday);

        return [$agency, $owner, $client, $employee, $workItem];
    }

    private function createProject(
        Agency $agency,
        User $client,
        User $employee,
        float $hours,
        ?CarbonImmutable $monday = null
    ): WorkItem {
        $monday ??= CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeek();
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

        return $workItem;
    }

    private function projectTicketsUrl(WorkItem $workItem): string
    {
        return '/api/agency/projects/'.$workItem->id.'/tickets';
    }

    /**
     * @return array{title: string, description: string, estimated_hours: float}
     */
    private function ticketData(float $hours, string $title = 'Ticket'): array
    {
        return [
            'title' => $title,
            'description' => 'Ticket description.',
            'estimated_hours' => $hours,
        ];
    }
}
