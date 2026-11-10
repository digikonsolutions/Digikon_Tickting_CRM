<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_is_exclusively_held_including_shared_employees_across_agencies(): void
    {
        $agencyA = Agency::create(['name' => 'Agency A']);
        $agencyB = Agency::create(['name' => 'Agency B']);
        $clientA = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agencyA->id]);
        $clientB = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agencyB->id]);
        $sharedEmployee = User::factory()->create(['role' => Role::Employee, 'agency_id' => null]);
        $agencyEmployee = User::factory()->create(['role' => Role::Employee, 'agency_id' => $agencyA->id]);

        $assignment = $this->actingAs($clientA)
            ->postJson('/api/agency/assignments', ['employee_id' => $sharedEmployee->id])
            ->assertCreated()
            ->json('assignment.id');

        $this->postJson('/api/agency/assignments', ['employee_id' => $agencyEmployee->id])
            ->assertCreated();

        $this->actingAs($clientB)
            ->getJson('/api/agency/employees/available')
            ->assertOk()
            ->assertJsonMissing(['id' => $sharedEmployee->id]);

        $this->postJson('/api/agency/assignments', ['employee_id' => $sharedEmployee->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_id']);

        $this->assertDatabaseHas('employee_assignments', [
            'id' => $assignment,
            'employee_id' => $sharedEmployee->id,
            'active_employee_id' => $sharedEmployee->id,
            'status' => 'active',
        ]);
    }

    public function test_one_holder_can_hold_multiple_employees_and_duplicate_attempts_leave_one_active_assignment(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employeeA = User::factory()->create(['role' => Role::Employee]);
        $employeeB = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($client)
            ->postJson('/api/agency/assignments', ['employee_id' => $employeeA->id])
            ->assertCreated();
        $this->postJson('/api/agency/assignments', ['employee_id' => $employeeB->id])
            ->assertCreated();
        $this->postJson('/api/agency/assignments', ['employee_id' => $employeeA->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_id']);

        $this->assertDatabaseCount('employee_assignments', 2);
        $this->assertSame(
            1,
            DB::table('employee_assignments')->where('active_employee_id', $employeeA->id)->count()
        );
    }

    public function test_work_items_require_the_callers_active_assignment_and_allocate_up_to_thirty_six_hours_per_week(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $anotherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignmentId = $this->actingAs($client)
            ->postJson('/api/agency/assignments', ['employee_id' => $employee->id])
            ->assertCreated()
            ->json('assignment.id');
        $otherAssignment = $this->actingAs($anotherClient)
            ->postJson('/api/agency/assignments', ['employee_id' => $employee->id])
            ->assertUnprocessable()
            ->json();
        $this->assertArrayHasKey('errors', $otherAssignment);

        $monday = CarbonImmutable::now(config('app.timezone'))->next(CarbonImmutable::MONDAY);
        $project = [
            'title' => 'Long project',
            'description' => 'Spread across three weeks.',
            'estimated_hours' => 70,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDays(20)->toDateString(),
            'assignment_id' => $assignmentId,
        ];

        $this->actingAs($anotherClient)
            ->postJson('/api/agency/projects', $project)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignment_id']);

        $this->actingAs($client)->postJson('/api/agency/projects', $project)->assertCreated();
        $allocations = DB::table('work_item_allocations')
            ->where('employee_id', $employee->id)
            ->get()
            ->groupBy(fn ($allocation): string => CarbonImmutable::parse($allocation->work_date)
                ->startOfWeek(CarbonImmutable::MONDAY)->toDateString());

        $this->assertSame(70.0, round((float) DB::table('work_item_allocations')->sum('hours'), 2));
        foreach ($allocations as $week) {
            $this->assertLessThanOrEqual(36.0, (float) $week->sum('hours'));
        }

        $singleWeek = [
            ...$project,
            'title' => 'Does not fit',
            'estimated_hours' => 37,
            'due_date' => $monday->addDays(5)->toDateString(),
        ];
        $this->postJson('/api/agency/projects', $singleWeek)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignment_id']);
    }

    public function test_cancelling_and_ending_assignments_release_future_capacity_and_free_the_employee(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $clientA = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $clientB = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignmentId = $this->actingAs($clientA)
            ->postJson('/api/agency/assignments', ['employee_id' => $employee->id])
            ->assertCreated()
            ->json('assignment.id');
        $monday = CarbonImmutable::now(config('app.timezone'))->next(CarbonImmutable::MONDAY);
        $project = $this->postJson('/api/agency/projects', [
            'title' => 'Cancel me',
            'description' => 'Future capacity should be released.',
            'estimated_hours' => 6,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->toDateString(),
            'assignment_id' => $assignmentId,
        ])->assertCreated();
        $workItemId = $project->json('project.id');

        $this->patchJson('/api/agency/projects/'.$workItemId.'/cancel')->assertOk();
        $this->assertDatabaseMissing('work_item_allocations', [
            'work_item_id' => $workItemId,
            'work_date' => $monday->toDateString(),
        ]);

        $this->deleteJson('/api/agency/assignments/'.$assignmentId)->assertOk();
        $this->assertDatabaseHas('employee_assignments', [
            'id' => $assignmentId,
            'status' => 'ended',
            'active_employee_id' => null,
        ]);

        $this->actingAs($clientB)
            ->getJson('/api/agency/employees/available')
            ->assertOk()
            ->assertJsonFragment(['id' => $employee->id]);
        $this->postJson('/api/agency/assignments', ['employee_id' => $employee->id])->assertCreated();
    }

    public function test_assignment_lists_scope_usage_breakdown_and_admin_directory_are_authorized(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $clientA = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $clientB = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $employeeA = User::factory()->create(['role' => Role::Employee]);
        $employeeB = User::factory()->create(['role' => Role::Employee]);

        $assignmentA = $this->actingAs($clientA)
            ->postJson('/api/agency/assignments', ['employee_id' => $employeeA->id])
            ->assertCreated()
            ->json('assignment.id');
        $this->actingAs($clientB)
            ->postJson('/api/agency/assignments', ['employee_id' => $employeeB->id])
            ->assertCreated();

        $this->actingAs($clientA)->getJson('/api/agency/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.employee.id', $employeeA->id);
        $this->actingAs($owner)->getJson('/api/agency/assignments')
            ->assertOk()
            ->assertJsonCount(0, 'assignments');
        $this->getJson('/api/agency/assignments?scope=agency')
            ->assertOk()
            ->assertJsonCount(2, 'assignments');
        $this->actingAs($clientA)->getJson('/api/agency/assignments?scope=agency')
            ->assertOk()
            ->assertJsonCount(2, 'assignments');

        $monday = CarbonImmutable::now(config('app.timezone'))->next(CarbonImmutable::MONDAY);
        $this->actingAs($clientA)->postJson('/api/agency/projects', [
            'title' => 'Weekly work',
            'description' => 'Usage reporting.',
            'estimated_hours' => 7,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDay()->toDateString(),
            'assignment_id' => $assignmentA,
        ])->assertCreated();
        $this->assertDatabaseHas('work_items', [
            'assignment_id' => $assignmentA,
            'employee_id' => $employeeA->id,
        ]);
        $this->getJson('/api/agency/assignments/'.$assignmentA.'/usage?week='.$monday->toDateString())
            ->assertOk()
            ->assertJsonPath('used', '7.00')
            ->assertJsonPath('remaining', '29.00')
            ->assertJsonCount(6, 'days')
            ->assertJsonPath('days.0.used', '6.00')
            ->assertJsonPath('days.1.used', '1.00');
        $this->actingAs($clientB)
            ->getJson('/api/agency/assignments/'.$assignmentA.'/usage')
            ->assertOk();

        $this->actingAs($admin)->getJson('/api/admin/assignments?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('pagination.total', 2);
    }
}
