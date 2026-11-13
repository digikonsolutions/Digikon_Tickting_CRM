<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolderRemovalReleasesAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_deactivating_a_client_releases_the_clients_employee(): void
    {
        [$owner, $client, $employee, $assignmentId, $project] = $this->holdAndBook();

        $this->actingAs($owner)->patchJson('/api/agency/users/'.$client->id, ['is_active' => false])->assertOk();

        $this->assertReleased($assignmentId, $project, $employee, $owner);
    }

    public function test_deleting_a_holder_releases_their_employee_and_booked_hours(): void
    {
        [$owner, $client, $employee, , $project] = $this->holdAndBook();

        $this->actingAs($owner)->deleteJson('/api/agency/users/'.$client->id)->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $client->id]);
        $this->assertDatabaseHas('work_items', ['id' => $project->id, 'status' => 'cancelled']);
        $this->assertDatabaseMissing('work_item_allocations', ['work_item_id' => $project->id]);
        $this->getJson('/api/agency/employees/available')->assertJsonFragment(['id' => $employee->id]);
    }

    public function test_other_holders_assignments_are_untouched(): void
    {
        [$owner, $client] = $this->holdAndBook();
        $other = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($owner)->postJson('/api/agency/assignments', ['employee_id' => $other->id])->assertCreated();

        $this->patchJson('/api/agency/users/'.$client->id, ['is_active' => false])->assertOk();

        $this->assertDatabaseHas('employee_assignments', ['employee_id' => $other->id, 'status' => 'active']);
    }

    private function assertReleased(int $assignmentId, WorkItem $project, User $employee, User $owner): void
    {
        $this->assertDatabaseHas('employee_assignments', ['id' => $assignmentId, 'status' => 'ended', 'active_employee_id' => null]);
        $this->assertDatabaseHas('work_items', ['id' => $project->id, 'status' => 'cancelled']);
        $this->actingAs($owner)->getJson('/api/agency/employees/available')->assertJsonFragment(['id' => $employee->id]);
    }

    /**
     * @return array{User, User, User, int, WorkItem}
     */
    private function holdAndBook(): array
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignmentId = $this->actingAs($client)
            ->postJson('/api/agency/assignments', ['employee_id' => $employee->id])->assertCreated()->json('assignment.id');
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
        $projectId = $this->postJson('/api/agency/projects', [
            'title' => 'Held work',
            'description' => 'Booked hours.',
            'estimated_hours' => 4,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDay()->toDateString(),
            'assignment_id' => $assignmentId,
        ])->assertCreated()->json('project.id');

        return [$owner, $client, $employee, $assignmentId, WorkItem::findOrFail($projectId)];
    }
}
