<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agency_user_can_fetch_a_single_project_from_their_agency(): void
    {
        [$agency, $owner, $client, $employee, $assignment] = $this->agencyFixture();
        $project = $this->makeProject($agency, $client, $employee, $assignment);

        foreach ([$owner, $client] as $viewer) {
            $this->actingAs($viewer)->getJson('/api/agency/projects/'.$project->id)
                ->assertOk()
                ->assertJsonPath('project.id', $project->id)
                ->assertJsonPath('project.assignment_id', $assignment->id)
                ->assertJsonPath('project.employee.id', $employee->id);
        }
    }

    public function test_single_project_is_hidden_from_other_agencies_and_wrong_roles(): void
    {
        [$agency, , $client, $employee, $assignment] = $this->agencyFixture();
        $project = $this->makeProject($agency, $client, $employee, $assignment);
        $otherAgency = Agency::create(['name' => 'Other agency']);
        $outsider = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]);

        $this->actingAs($outsider)->getJson('/api/agency/projects/'.$project->id)->assertNotFound();
        $this->actingAs($employee)->getJson('/api/agency/projects/'.$project->id)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->getJson('/api/agency/projects/'.$project->id)->assertForbidden();
        $this->actingAs($client)->getJson('/api/agency/projects/999999')->assertNotFound();
    }

    public function test_single_project_requires_authentication(): void
    {
        $this->getJson('/api/agency/projects/1')->assertUnauthorized();
    }

    public function test_single_project_is_forbidden_for_a_suspended_agency(): void
    {
        [$agency, , $client, $employee, $assignment] = $this->agencyFixture();
        $project = $this->makeProject($agency, $client, $employee, $assignment);
        $agency->update(['status' => 'suspended']);

        $this->actingAs($client)->getJson('/api/agency/projects/'.$project->id)->assertForbidden();
    }

    /**
     * @return array{Agency, User, User, User, EmployeeAssignment}
     */
    private function agencyFixture(): array
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = EmployeeAssignment::create([
            'holder_id' => $client->id,
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);

        return [$agency, $owner, $client, $employee, $assignment];
    }

    private function makeProject(
        Agency $agency,
        User $client,
        User $employee,
        EmployeeAssignment $assignment,
        float $hours = 4,
        string $title = 'Project',
        string $status = 'scheduled',
    ): WorkItem {
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeek();
        $item = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => $title,
            'description' => 'Description.',
            'estimated_hours' => $hours,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDays(5)->toDateString(),
            'status' => $status,
        ]);
        $item->allocations()->create([
            'employee_id' => $employee->id,
            'work_date' => $monday->toDateString(),
            'hours' => number_format(min($hours, 6), 2, '.', ''),
        ]);

        return $item;
    }
}
