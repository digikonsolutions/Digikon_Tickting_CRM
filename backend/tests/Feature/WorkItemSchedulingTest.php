<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkItemSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_create_projects_and_scheduler_spreads_hours_across_workdays(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $anotherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $monday = now()->startOfWeek()->addWeek();
        $tuesday = $monday->copy()->addDay();

        $this->actingAs($client)
            ->postJson('/api/agency/projects', [
                'title' => 'Landing page',
                'description' => 'Build the landing page.',
                'estimated_hours' => 9,
                'start_date' => $monday->toDateString(),
                'due_date' => $tuesday->toDateString(),
                'employee_id' => $employee->id,
            ])
            ->assertCreated()
            ->assertJsonPath('project.status', 'scheduled')
            ->assertJsonPath('project.estimated_hours', '9.00')
            ->assertJsonPath('project.schedule.0.work_date', $monday->toDateString())
            ->assertJsonPath('project.schedule.0.hours', '4.50')
            ->assertJsonPath('project.schedule.1.work_date', $tuesday->toDateString())
            ->assertJsonPath('project.schedule.1.hours', '4.50');

        $this->actingAs($client)->getJson('/api/agency/projects')
            ->assertOk()
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.employee.id', $employee->id);

        WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $anotherClient->id,
            'employee_id' => $employee->id,
            'title' => 'Another client project',
            'description' => 'Private to its creator.',
            'estimated_hours' => 1,
            'start_date' => $monday->toDateString(),
            'due_date' => $tuesday->toDateString(),
            'status' => 'scheduled',
        ]);
        $this->getJson('/api/agency/projects')
            ->assertOk()
            ->assertJsonCount(1, 'projects');
        $this->actingAs(User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]))
            ->getJson('/api/agency/projects')
            ->assertOk()
            ->assertJsonCount(2, 'projects');
    }

    public function test_schedule_balances_project_hours_across_days_and_accounts_for_same_clients_existing_work(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $monday = now()->startOfWeek()->addWeek();
        $tuesday = $monday->copy()->addDay();
        $wednesday = $monday->copy()->addDays(2);
        $payload = [
            'description' => 'Task.',
            'start_date' => $monday->toDateString(),
            'due_date' => $wednesday->toDateString(),
            'employee_id' => $employee->id,
        ];

        $this->actingAs($client)->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'First project',
            'estimated_hours' => 12,
        ])->assertCreated()
            ->assertJsonPath('project.schedule.0.work_date', $monday->toDateString())
            ->assertJsonPath('project.schedule.0.hours', '4.00')
            ->assertJsonPath('project.schedule.1.work_date', $tuesday->toDateString())
            ->assertJsonPath('project.schedule.1.hours', '4.00')
            ->assertJsonPath('project.schedule.2.work_date', $wednesday->toDateString())
            ->assertJsonPath('project.schedule.2.hours', '4.00');

        $this->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Second project',
            'estimated_hours' => 6,
        ])->assertCreated()
            ->assertJsonPath('project.schedule.0.work_date', $monday->toDateString())
            ->assertJsonPath('project.schedule.0.hours', '2.00')
            ->assertJsonPath('project.schedule.1.work_date', $tuesday->toDateString())
            ->assertJsonPath('project.schedule.1.hours', '2.00')
            ->assertJsonPath('project.schedule.2.work_date', $wednesday->toDateString())
            ->assertJsonPath('project.schedule.2.hours', '2.00');
    }

    public function test_clients_can_view_and_assign_admin_created_employees_across_agencies(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $sharedEmployee = User::factory()->create(['role' => Role::Employee, 'agency_id' => null]);
        $anotherEmployee = User::factory()->create(['role' => Role::Employee, 'agency_id' => null]);
        $monday = now()->startOfWeek()->addWeek();

        $this->actingAs($client)
            ->getJson('/api/agency/employees?start_date='.$monday->toDateString()
                .'&due_date='.$monday->toDateString().'&hours=1')
            ->assertOk()
            ->assertJsonCount(2, 'employees')
            ->assertJsonFragment(['id' => $sharedEmployee->id, 'available' => true])
            ->assertJsonFragment(['id' => $anotherEmployee->id, 'available' => true]);

        $this->postJson('/api/agency/projects', [
            'title' => 'Shared employee assignment',
            'description' => 'Admin-created employees can be assigned across agencies.',
            'estimated_hours' => 1,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->toDateString(),
            'employee_id' => $anotherEmployee->id,
        ])->assertCreated()
            ->assertJsonPath('project.employee.id', $anotherEmployee->id);
    }

    public function test_assignment_rejects_daily_overbooking_but_allows_multiple_tasks_within_capacity(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $anotherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $monday = now()->startOfWeek()->addWeek();
        $tuesday = $monday->copy()->addDay();
        $payload = [
            'title' => 'First task',
            'description' => 'Task.',
            'estimated_hours' => 6,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->toDateString(),
            'employee_id' => $employee->id,
        ];

        $this->actingAs($client)->postJson('/api/agency/projects', $payload)->assertCreated();
        $this->assertDatabaseHas('work_item_allocations', ['employee_id' => $employee->id, 'hours' => '6.00']);
        $this->actingAs($owner)
            ->getJson('/api/agency/employees?start_date='.$monday->toDateString()
                .'&due_date='.$monday->toDateString().'&hours=1')
            ->assertOk()
            ->assertJsonPath('employees.0.available', false);
        $this->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Second task',
            'estimated_hours' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);

        $this->actingAs($anotherClient)->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Another client Tuesday task',
            'estimated_hours' => 3,
            'start_date' => $tuesday->toDateString(),
            'due_date' => $tuesday->toDateString(),
        ])->assertCreated();

        $this->actingAs($client)->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Tuesday task',
            'estimated_hours' => 4,
            'start_date' => $tuesday->toDateString(),
            'due_date' => $tuesday->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);

        $this->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Tuesday task within remaining capacity',
            'estimated_hours' => 3,
            'start_date' => $tuesday->toDateString(),
            'due_date' => $tuesday->toDateString(),
        ])->assertCreated();

        $this->assertSame(3, WorkItem::query()->count());
    }

    public function test_scheduler_skips_sunday_and_checks_capacity_after_balancing_work_days(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $saturday = now()->startOfWeek()->addWeek()->addDays(5);
        $monday = $saturday->copy()->addDays(2);
        $payload = [
            'title' => 'Weekend-spanning task',
            'description' => 'Sunday is not a workday.',
            'estimated_hours' => 7,
            'start_date' => $saturday->toDateString(),
            'due_date' => $monday->toDateString(),
            'employee_id' => $employee->id,
        ];

        $this->actingAs($owner)->postJson('/api/agency/projects', $payload)
            ->assertCreated()
            ->assertJsonPath('project.schedule.0.work_date', $saturday->toDateString())
            ->assertJsonPath('project.schedule.0.hours', '3.50')
            ->assertJsonPath('project.schedule.1.work_date', $monday->toDateString())
            ->assertJsonPath('project.schedule.1.hours', '3.50');

        $this->getJson('/api/agency/employees?start_date='.$saturday->toDateString()
            .'&due_date='.$saturday->toDateString().'&hours=2.51')
            ->assertOk()
            ->assertJsonPath('employees.0.available', false)
            ->assertJsonPath('employees.0.available_hours', '2.50');
    }

    public function test_owner_can_see_agency_projects_admin_can_view_all_and_employees_can_update_assigned_status(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $monday = now()->startOfWeek()->addWeek();
        $workItem = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'title' => 'Assigned work',
            'description' => 'Task.',
            'estimated_hours' => 1,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->toDateString(),
            'status' => 'scheduled',
        ]);
        $workItem->allocations()->create([
            'employee_id' => $employee->id,
            'work_date' => $monday->toDateString(),
            'hours' => 1,
        ]);

        $this->actingAs($owner)->getJson('/api/agency/projects')
            ->assertOk()
            ->assertJsonCount(1, 'projects');
        $this->actingAs($admin)->getJson('/api/admin/projects')
            ->assertOk()
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.agency_name', 'Northwind');
        $this->patchJson('/api/agency/projects/'.$workItem->id, ['status' => 'completed'])
            ->assertNotFound();

        $this->actingAs($employee)->getJson('/api/work-items/mine')
            ->assertOk()
            ->assertJsonCount(1, 'projects');
        $this->patchJson('/api/work-items/'.$workItem->id.'/status', ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('project.status', 'in_progress');
        $this->patchJson('/api/work-items/'.$workItem->id.'/status', ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('project.status', 'completed');
        $this->actingAs($client)->postJson('/api/agency/projects', [
            'title' => 'Replacement work',
            'description' => 'Completed work released its future booking.',
            'estimated_hours' => 1,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->toDateString(),
            'employee_id' => $employee->id,
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/agency/projects', [])->assertForbidden();
    }

    public function test_agency_directory_includes_shared_employees(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'agency_id' => null]);

        $this->actingAs($client)
            ->getJson('/api/agency/directory')
            ->assertOk()
            ->assertJsonFragment(['id' => $employee->id, 'role' => Role::Employee->value]);
    }
}
