<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\WorkItemScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkItemSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_create_projects_and_scheduler_allocates_earliest_first(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $anotherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = $this->createAssignment($client, $employee);
        $monday = now()->startOfWeek()->addWeek();
        $tuesday = $monday->copy()->addDay();

        $this->actingAs($client)
            ->postJson('/api/agency/projects', [
                'title' => 'Landing page',
                'description' => 'Build the landing page.',
                'estimated_hours' => 9,
                'start_date' => $monday->toDateString(),
                'due_date' => $tuesday->toDateString(),
                'assignment_id' => $assignment->id,
            ])
            ->assertCreated()
            ->assertJsonPath('project.status', 'scheduled')
            ->assertJsonPath('project.estimated_hours', '9.00')
            ->assertJsonPath('project.schedule.0.work_date', $monday->toDateString())
            ->assertJsonPath('project.schedule.0.hours', '6.00')
            ->assertJsonPath('project.schedule.1.work_date', $tuesday->toDateString())
            ->assertJsonPath('project.schedule.1.hours', '3.00');

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

    public function test_schedule_allocates_earliest_first_and_accounts_for_existing_work(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = $this->createAssignment($client, $employee);
        $monday = now()->startOfWeek()->addWeek();
        $tuesday = $monday->copy()->addDay();
        $wednesday = $monday->copy()->addDays(2);
        $payload = [
            'description' => 'Task.',
            'start_date' => $monday->toDateString(),
            'due_date' => $wednesday->toDateString(),
            'assignment_id' => $assignment->id,
        ];

        $this->actingAs($client)->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'First project',
            'estimated_hours' => 12,
        ])->assertCreated()
            ->assertJsonPath('project.schedule.0.work_date', $monday->toDateString())
            ->assertJsonPath('project.schedule.0.hours', '6.00')
            ->assertJsonPath('project.schedule.1.work_date', $tuesday->toDateString())
            ->assertJsonPath('project.schedule.1.hours', '6.00');

        $this->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Second project',
            'estimated_hours' => 6,
        ])->assertCreated()
            ->assertJsonPath('project.schedule.0.work_date', $wednesday->toDateString())
            ->assertJsonPath('project.schedule.0.hours', '6.00');
    }

    public function test_clients_can_view_and_assign_admin_created_employees_across_agencies(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $sharedEmployee = User::factory()->create(['role' => Role::Employee, 'agency_id' => null]);
        $anotherEmployee = User::factory()->create(['role' => Role::Employee, 'agency_id' => null]);
        $monday = now()->startOfWeek()->addWeek();

        $this->actingAs($client)
            ->getJson('/api/agency/employees/available')
            ->assertOk()
            ->assertJsonCount(2, 'employees')
            ->assertJsonFragment(['id' => $sharedEmployee->id])
            ->assertJsonFragment(['id' => $anotherEmployee->id]);

        $assignment = $this->createAssignment($client, $anotherEmployee);

        $this->postJson('/api/agency/projects', [
            'title' => 'Shared employee assignment',
            'description' => 'Admin-created employees can be assigned across agencies.',
            'estimated_hours' => 1,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->toDateString(),
            'assignment_id' => $assignment->id,
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
        $assignment = $this->createAssignment($client, $employee);
        $monday = now()->startOfWeek()->addWeek();
        $tuesday = $monday->copy()->addDay();
        $payload = [
            'title' => 'First task',
            'description' => 'Task.',
            'estimated_hours' => 6,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->toDateString(),
            'assignment_id' => $assignment->id,
        ];

        $this->actingAs($client)->postJson('/api/agency/projects', $payload)->assertCreated();
        $this->assertDatabaseHas('work_item_allocations', ['employee_id' => $employee->id, 'hours' => '6.00']);
        $this->actingAs($owner)
            ->getJson('/api/agency/employees/available')
            ->assertOk()
            ->assertJsonMissing(['id' => $employee->id]);
        $this->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Second task',
            'estimated_hours' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['assignment_id']);

        $this->actingAs($anotherClient)->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Another client Tuesday task',
            'estimated_hours' => 3,
            'start_date' => $tuesday->toDateString(),
            'due_date' => $tuesday->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['assignment_id']);

        $this->actingAs($client)->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Tuesday task',
            'estimated_hours' => 4,
            'start_date' => $tuesday->toDateString(),
            'due_date' => $tuesday->toDateString(),
        ])->assertCreated();

        $this->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Tuesday task beyond remaining capacity',
            'estimated_hours' => 3,
            'start_date' => $tuesday->toDateString(),
            'due_date' => $tuesday->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['assignment_id']);

        $this->assertSame(2, WorkItem::query()->count());
    }

    public function test_clients_in_different_agencies_cannot_use_an_employee_held_by_another_agency(): void
    {
        $firstAgency = Agency::create(['name' => 'First agency']);
        $secondAgency = Agency::create(['name' => 'Second agency']);
        $firstClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $firstAgency->id]);
        $secondClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $secondAgency->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'agency_id' => null]);
        $assignment = $this->createAssignment($firstClient, $employee);
        $monday = now()->startOfWeek()->addWeek();
        $payload = [
            'title' => 'Shared capacity',
            'description' => 'The employee is shared across agencies.',
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->toDateString(),
            'assignment_id' => $assignment->id,
        ];

        $this->actingAs($firstClient)->postJson('/api/agency/projects', [
            ...$payload,
            'estimated_hours' => 6,
        ])->assertCreated();

        $this->actingAs($secondClient)->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Competing project',
            'estimated_hours' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['assignment_id']);

        $this->assertSame(1, WorkItem::query()->count());
    }

    public function test_cancelled_project_releases_future_capacity(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $otherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = $this->createAssignment($client, $employee);
        $monday = now()->startOfWeek()->addWeek();
        $payload = [
            'title' => 'Original project',
            'description' => 'This project will be cancelled.',
            'estimated_hours' => 6,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->toDateString(),
            'assignment_id' => $assignment->id,
        ];

        $response = $this->actingAs($client)->postJson('/api/agency/projects', $payload)->assertCreated();
        $workItemId = $response->json('project.id');
        $workItem = WorkItem::findOrFail($workItemId);
        $workItem->allocations()->create([
            'employee_id' => $employee->id,
            'work_date' => now()->subDay()->toDateString(),
            'hours' => 1,
        ]);
        $workItem->allocations()->create([
            'employee_id' => $employee->id,
            'work_date' => now()->toDateString(),
            'hours' => 1,
        ]);

        $this->actingAs($otherClient)
            ->patchJson('/api/agency/projects/'.$workItemId.'/cancel')
            ->assertNotFound();

        $this->actingAs($owner)
            ->patchJson('/api/agency/projects/'.$workItemId.'/cancel')
            ->assertOk()
            ->assertJsonPath('project.status', 'cancelled')
            ->assertJsonCount(1, 'project.schedule');

        $this->assertDatabaseMissing('work_item_allocations', [
            'work_item_id' => $workItemId,
            'work_date' => $monday->toDateString(),
        ]);
        $this->assertDatabaseMissing('work_item_allocations', [
            'work_item_id' => $workItemId,
            'work_date' => now()->toDateString(),
        ]);
        $this->assertTrue(DB::table('work_item_allocations')
            ->where('work_item_id', $workItemId)
            ->whereDate('work_date', now()->subDay()->toDateString())
            ->exists());
        $this->patchJson('/api/agency/projects/'.$workItemId.'/cancel')->assertUnprocessable();

        $completed = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'title' => 'Completed project',
            'description' => 'Completed projects cannot be cancelled.',
            'estimated_hours' => 1,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->toDateString(),
            'status' => 'completed',
        ]);
        $this->patchJson('/api/agency/projects/'.$completed->id.'/cancel')->assertUnprocessable();

        $this->actingAs($client)->postJson('/api/agency/projects', [
            ...$payload,
            'title' => 'Replacement project',
        ])->assertCreated();
    }

    public function test_seventy_hour_project_never_exceeds_thirty_six_hours_in_a_monday_to_saturday_week(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = $this->createAssignment($client, $employee);
        $monday = now()->startOfWeek()->addWeek();
        $due = $monday->copy()->addDays(20);

        $this->actingAs($client)->postJson('/api/agency/projects', [
            'title' => 'Long project',
            'description' => 'Spread across a three-week date range.',
            'estimated_hours' => 70,
            'start_date' => $monday->toDateString(),
            'due_date' => $due->toDateString(),
            'assignment_id' => $assignment->id,
        ])->assertCreated();

        $allocations = DB::table('work_item_allocations')
            ->where('employee_id', $employee->id)
            ->get()
            ->groupBy(fn ($allocation): string => CarbonImmutable::parse($allocation->work_date)->startOfWeek()->toDateString());

        $this->assertSame(70.0, round((float) DB::table('work_item_allocations')->sum('hours'), 2));
        foreach (range(0, 2) as $weekOffset) {
            $weekStart = $monday->copy()->addWeeks($weekOffset)->toDateString();
            $this->assertLessThanOrEqual(36.0, (float) ($allocations->get($weekStart)?->sum('hours') ?? 0));
        }
    }

    public function test_non_working_dates_and_today_cutoff_are_excluded_from_capacity(): void
    {
        config([
            'app.timezone' => 'Asia/Karachi',
            'scheduling.non_working_dates' => ['2026-11-11'],
            'scheduling.today_cutoff_hour' => 12,
        ]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $today = CarbonImmutable::parse('2026-11-10', 'Asia/Karachi');
        $scheduler = app(WorkItemScheduler::class);

        $this->travelTo(CarbonImmutable::parse('2026-11-10 11:59:00', 'Asia/Karachi'));
        $this->assertSame(
            [['work_date' => '2026-11-10', 'units' => 100]],
            $scheduler->plan($employee, $today, $today, 100)
        );
        $this->assertSame(600, $scheduler->availableUnits($employee, $today, $today));

        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'Asia/Karachi'));
        $this->assertNull($scheduler->plan($employee, $today, $today, 100));
        $this->assertSame(0, $scheduler->availableUnits($employee, $today, $today));

        $holiday = $today->addDay();
        $this->assertNull($scheduler->plan($employee, $holiday, $holiday, 100));
        $this->assertSame(0, $scheduler->availableUnits($employee, $holiday, $holiday));
    }

    public function test_client_is_limited_to_three_open_projects_and_configured_project_hours(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = $this->createAssignment($client, $employee);
        $monday = now()->startOfWeek()->addWeek();
        $payload = [
            'title' => 'Open project',
            'description' => 'Open projects are capped for each client.',
            'estimated_hours' => 1,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->copy()->addDays(2)->toDateString(),
            'assignment_id' => $assignment->id,
        ];

        $this->actingAs($client);
        foreach (range(1, 3) as $number) {
            $this->postJson('/api/agency/projects', [...$payload, 'title' => 'Project '.$number])->assertCreated();
        }

        $this->postJson('/api/agency/projects', [...$payload, 'title' => 'Fourth project'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['projects']);

        $this->postJson('/api/agency/projects', [...$payload, 'estimated_hours' => 180.01])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['estimated_hours']);
    }

    public function test_scheduler_skips_sunday_and_checks_capacity_after_earliest_first_allocation(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = $this->createAssignment($owner, $employee);
        $saturday = now()->startOfWeek()->addWeek()->addDays(5);
        $monday = $saturday->copy()->addDays(2);
        $payload = [
            'title' => 'Weekend-spanning task',
            'description' => 'Sunday is not a workday.',
            'estimated_hours' => 7,
            'start_date' => $saturday->toDateString(),
            'due_date' => $monday->toDateString(),
            'assignment_id' => $assignment->id,
        ];

        $this->actingAs($owner)->postJson('/api/agency/projects', $payload)
            ->assertCreated()
            ->assertJsonPath('project.schedule.0.work_date', $saturday->toDateString())
            ->assertJsonPath('project.schedule.0.hours', '6.00')
            ->assertJsonPath('project.schedule.1.work_date', $monday->toDateString())
            ->assertJsonPath('project.schedule.1.hours', '1.00');

        $this->getJson('/api/agency/employees/available')
            ->assertOk()
            ->assertJsonMissing(['id' => $employee->id]);
    }

    public function test_owner_can_see_agency_projects_admin_can_view_all_and_employees_can_update_assigned_status(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $assignment = $this->createAssignment($client, $employee);
        $monday = now()->startOfWeek()->addWeek();
        $workItem = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'assignment_id' => $assignment->id,
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
        $this->patchJson('/api/work-items/'.$workItem->id.'/status', ['status' => 'completed'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('work_items', ['id' => $workItem->id, 'status' => 'scheduled']);
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
            'assignment_id' => $assignment->id,
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/agency/projects', [])->assertForbidden();
    }

    public function test_employee_routes_reject_users_linked_to_a_suspended_agency(): void
    {
        $agency = Agency::create(['name' => 'Suspended agency', 'status' => 'suspended']);
        $employee = User::factory()->create([
            'role' => Role::Employee,
            'agency_id' => $agency->id,
        ]);
        $workItem = WorkItem::create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'title' => 'Suspended agency work',
            'description' => 'This work should not be accessible.',
            'estimated_hours' => 1,
            'start_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($employee)
            ->getJson('/api/work-items/mine')
            ->assertForbidden();

        $this->patchJson('/api/work-items/'.$workItem->id.'/status', ['status' => 'in_progress'])
            ->assertForbidden();

        $this->assertDatabaseHas('work_items', ['id' => $workItem->id, 'status' => 'scheduled']);
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

    private function createAssignment(User $holder, User $employee): EmployeeAssignment
    {
        return EmployeeAssignment::create([
            'holder_id' => $holder->id,
            'agency_id' => $holder->agency_id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);
    }
}
