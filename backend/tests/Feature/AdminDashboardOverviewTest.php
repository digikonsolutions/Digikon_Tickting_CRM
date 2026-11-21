<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The employee- and agency-first part of GET /api/admin/dashboard (App\Services\AdminDashboardService). */
class AdminDashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    private function agency(string $name, string $status = 'active'): Agency
    {
        $agency = Agency::create(['name' => $name]);
        if ($status !== 'active') {
            $agency->update(['status' => $status]);
        }

        return $agency;
    }

    private function employee(string $name, array $attributes = []): User
    {
        return User::factory()->create(['name' => $name, 'role' => Role::Employee, ...$attributes]);
    }

    private function client(Agency $agency, string $name = 'Client'): User
    {
        return User::factory()->create(['name' => $name, 'role' => Role::AgencyClient, 'agency_id' => $agency->id]);
    }

    private function hold(User $employee, Agency $agency, ?User $holder = null): EmployeeAssignment
    {
        return EmployeeAssignment::create([
            'holder_id' => ($holder ?? $this->client($agency, 'Holder '.$employee->id))->id,
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);
    }

    /**
     * A project on the assignment with the given allocations ([[CarbonImmutable date, hours], ...]).
     *
     * @param  list<array{CarbonImmutable, float}>  $allocations
     */
    private function book(EmployeeAssignment $assignment, string $status, array $allocations, ?CarbonImmutable $due = null): WorkItem
    {
        $first = $allocations[0][0];
        $item = WorkItem::create([
            'agency_id' => $assignment->agency_id,
            'client_id' => $assignment->holder_id,
            'assignment_id' => $assignment->id,
            'employee_id' => $assignment->employee_id,
            'title' => 'Project '.uniqid(),
            'description' => 'Desc.',
            'estimated_hours' => array_sum(array_column($allocations, 1)),
            'start_date' => $first->toDateString(),
            'due_date' => ($due ?? $this->monday->addDays(10))->toDateString(),
            'status' => $status,
        ]);
        foreach ($allocations as [$date, $hours]) {
            $item->allocations()->create(['employee_id' => $assignment->employee_id, 'work_date' => $date->toDateString(), 'hours' => $hours]);
        }

        return $item;
    }

    /** @return list<array{CarbonImmutable, float}> $hours spread over Monday..Saturday at up to 6 h a day */
    private function weekHours(float $hours, ?CarbonImmutable $monday = null): array
    {
        $monday ??= $this->monday;
        $rows = [];
        for ($day = 0; $day < 6 && $hours > 0; $day++) {
            $rows[] = [$monday->addDays($day), min(6, $hours)];
            $hours -= 6;
        }

        return $rows;
    }

    private function dashboard(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->getJson('/api/admin/dashboard'.$query)->assertOk();
    }

    public function test_dashboard_is_admin_only(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        foreach ([Role::AgencyOwner, Role::AgencyClient, Role::AgencyMember, Role::Employee, Role::TeamLead] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/admin/dashboard')->assertForbidden();
        }
    }

    public function test_available_and_held_employees_are_counted_correctly(): void
    {
        $agencyX = $this->agency('Agency X');
        $agencyY = $this->agency('Agency Y');
        $held1 = $this->employee('Held One');
        $held2 = $this->employee('Held Two');
        $this->employee('Free One');
        $freed = $this->employee('Freed Again');
        $this->employee('Deactivated', ['is_active' => false]);
        $this->hold($held1, $agencyX);
        $this->hold($held2, $agencyY);
        // An ended assignment frees the employee again.
        $ended = $this->hold($freed, $agencyX);
        $ended->update(['status' => 'ended', 'active_employee_id' => null, 'ended_at' => now()]);
        // Admins, agency users and team leads are never employees.
        $this->client($agencyX);
        User::factory()->create(['role' => Role::TeamLead]);

        $this->dashboard()
            ->assertJsonPath('employees.total', 5)
            ->assertJsonPath('employees.active', 4)
            ->assertJsonPath('employees.inactive', 1)
            ->assertJsonPath('employees.available', 2)
            ->assertJsonPath('employees.held', 2)
            ->assertJsonPath('agencies.total', 2)
            ->assertJsonPath('agencies.active', 2)
            ->assertJsonPath('agencies.suspended', 0);
    }

    public function test_agencies_are_counted_by_status(): void
    {
        $this->agency('One');
        $this->agency('Two');
        $this->agency('Three', 'suspended');

        $this->dashboard()->assertJsonPath('agencies.total', 3)->assertJsonPath('agencies.active', 2)->assertJsonPath('agencies.suspended', 1);
    }

    public function test_utilization_uses_this_weeks_non_cancelled_hours_of_active_employees_over_36(): void
    {
        $agency = $this->agency('Agency X');
        [$a, $b, $c] = [$this->employee('Ann'), $this->employee('Bob'), $this->employee('Cy')];
        $gone = $this->employee('Gone', ['is_active' => false]);
        $assignmentA = $this->hold($a, $agency);
        $assignmentB = $this->hold($b, $agency);
        $assignmentC = $this->hold($c, $agency);
        $assignmentGone = $this->hold($gone, $agency);

        $this->book($assignmentA, 'in_progress', $this->weekHours(18));
        $this->book($assignmentB, 'scheduled', $this->weekHours(36));
        // None of these count: cancelled work, last week, next week, and an inactive employee's hours.
        $this->book($assignmentC, 'cancelled', $this->weekHours(12));
        $this->book($assignmentC, 'scheduled', [[$this->monday->subDay(), 6]]);
        $this->book($assignmentC, 'scheduled', [[$this->monday->addDays(7), 6]]);
        $this->book($assignmentGone, 'scheduled', $this->weekHours(24));
        // Completed work still counts (the usage endpoint counts it too).
        $this->book($assignmentA, 'completed', [[$this->monday->addDays(5), 0]]);

        $response = $this->dashboard()
            ->assertJsonPath('employees.avg_utilization_percent', 50)   // (18 + 36 + 0) / (36 * 3 active)
            ->assertJsonPath('week_start', $this->monday->toDateString())
            ->assertJsonPath('week_end', $this->monday->addDays(5)->toDateString())
            ->assertJsonPath('weekly_budget_hours', 36);

        $rows = collect($response->json('employee_capacity'));
        $this->assertSame(['Bob', 'Ann', 'Cy'], $rows->pluck('name')->all());      // utilization desc
        $this->assertSame([100, 50, 0], $rows->pluck('utilization_percent')->all());
        $this->assertSame([36.0, 18.0, 0.0], $rows->pluck('used_hours')->map(fn ($h) => (float) $h)->all());
        $this->assertSame([36.0, 36.0, 36.0], $rows->pluck('budget_hours')->map(fn ($h) => (float) $h)->all());
        $this->assertNotContains('Gone', $rows->pluck('name')->all());
    }

    public function test_capacity_rows_show_department_and_who_holds_the_employee(): void
    {
        $agency = $this->agency('Northwind');
        $holder = $this->client($agency, 'Hannah Holder');
        $held = $this->employee('Held');
        $free = $this->employee('Free');
        $held->employeeProfile()->create(['department' => 'web']);
        $this->hold($held, $agency, $holder);

        $rows = collect($this->dashboard()->json('employee_capacity'))->keyBy('name');

        $this->assertArrayHasKey('photo_url', $rows['Held']);
        $this->assertSame('web', $rows['Held']['department']);
        $this->assertSame('held', $rows['Held']['status']);
        $this->assertSame(['name' => 'Hannah Holder', 'agency_id' => $agency->id, 'agency_name' => 'Northwind'], $rows['Held']['holder']);
        $this->assertNull($rows['Free']['department']);               // no profile row
        $this->assertSame('available', $rows['Free']['status']);
        $this->assertNull($rows['Free']['holder']);
    }

    public function test_employee_capacity_is_the_top_eight_and_can_be_filtered(): void
    {
        $agency = $this->agency('Agency X');
        $busy = $this->employee('Busy');
        $this->book($this->hold($busy, $agency), 'in_progress', $this->weekHours(36));
        $calm = $this->employee('Calm');
        $this->book($this->hold($calm, $agency), 'in_progress', $this->weekHours(12));
        foreach (range(1, 9) as $number) {
            $this->employee('Free '.str_pad((string) $number, 2, '0', STR_PAD_LEFT));
        }

        $all = $this->dashboard()->json('employee_capacity');
        $this->assertCount(8, $all);
        $this->assertSame(['Busy', 'Calm'], array_slice(array_column($all, 'name'), 0, 2));

        $available = $this->dashboard('?employee_filter=available')->json('employee_capacity');
        $this->assertCount(8, $available);
        $this->assertSame(['available'], array_values(array_unique(array_column($available, 'status'))));

        $held = $this->dashboard('?employee_filter=held')->json('employee_capacity');
        $this->assertSame(['Busy', 'Calm'], array_column($held, 'name'));

        $overloaded = $this->dashboard('?employee_filter=overloaded')->json('employee_capacity');
        $this->assertSame(['Busy'], array_column($overloaded, 'name'));       // 36 h is over 90 percent, 12 h is not
    }

    public function test_invalid_query_values_are_rejected(): void
    {
        $this->actingAs($this->admin);
        $this->getJson('/api/admin/dashboard?week=next-tuesday')->assertUnprocessable()->assertJsonValidationErrors('week');
        $this->getJson('/api/admin/dashboard?week=2026-13-45')->assertUnprocessable()->assertJsonValidationErrors('week');
        $this->getJson('/api/admin/dashboard?employee_filter=everyone')->assertUnprocessable()->assertJsonValidationErrors('employee_filter');
    }

    public function test_week_parameter_moves_the_reporting_week_to_the_monday_of_that_date(): void
    {
        $agency = $this->agency('Agency X');
        $employee = $this->employee('Ann');
        $assignment = $this->hold($employee, $agency);
        $nextMonday = $this->monday->addDays(7);
        $this->book($assignment, 'scheduled', $this->weekHours(12, $nextMonday));

        $this->dashboard()->assertJsonPath('employee_capacity.0.used_hours', 0);
        // Wednesday of next week resolves to next week's Monday.
        $this->dashboard('?week='.$nextMonday->addDays(2)->toDateString())
            ->assertJsonPath('week_start', $nextMonday->toDateString())
            ->assertJsonPath('week_end', $nextMonday->addDays(5)->toDateString())
            ->assertJsonPath('employee_capacity.0.used_hours', 12)
            ->assertJsonPath('employees.avg_utilization_percent', 33);
    }

    public function test_agency_rows_report_owner_held_employees_open_projects_and_booked_hours(): void
    {
        $x = $this->agency('Xylo');
        $y = $this->agency('Yarrow');
        $this->agency('Zephyr', 'suspended');
        User::factory()->create(['name' => 'Olivia Owner', 'role' => Role::AgencyOwner, 'agency_id' => $x->id]);
        $ann = $this->employee('Ann');
        $bob = $this->employee('Bob');
        $assignmentX = $this->hold($ann, $x);
        $assignmentY = $this->hold($bob, $y);
        $this->book($assignmentX, 'in_progress', $this->weekHours(12));
        $this->book($assignmentX, 'scheduled', [[$this->monday->addDays(4), 6]]);
        $this->book($assignmentX, 'cancelled', [[$this->monday->addDays(3), 6]]);     // not open, not booked
        $this->book($assignmentX, 'completed', [[$this->monday->addDays(2), 0]]);      // not open
        $this->book($assignmentY, 'scheduled', $this->weekHours(30));

        $rows = collect($this->dashboard()->json('agency_rows'));

        $this->assertSame(['Yarrow', 'Xylo', 'Zephyr'], $rows->pluck('name')->all());   // booked hours desc
        $xylo = $rows->firstWhere('name', 'Xylo');
        $this->assertSame('Olivia Owner', $xylo['owner_name']);
        $this->assertSame('active', $xylo['status']);
        $this->assertSame(1, $xylo['employees_held']);
        $this->assertSame(2, $xylo['open_projects']);
        $this->assertSame(18.0, (float) $xylo['hours_booked_this_week']);
        $yarrow = $rows->firstWhere('name', 'Yarrow');
        $this->assertNull($yarrow['owner_name']);
        $this->assertSame(30.0, (float) $yarrow['hours_booked_this_week']);
        $zephyr = $rows->firstWhere('name', 'Zephyr');
        $this->assertSame('suspended', $zephyr['status']);
        $this->assertSame([0, 0, 0.0], [$zephyr['employees_held'], $zephyr['open_projects'], (float) $zephyr['hours_booked_this_week']]);
    }

    public function test_work_summary_counts_projects_and_tickets(): void
    {
        $agency = $this->agency('Agency X');
        $assignment = $this->hold($this->employee('Ann'), $agency);
        $this->book($assignment, 'scheduled', [[$this->monday, 1]]);
        $this->book($assignment, 'in_progress', [[$this->monday->addDay(), 1]]);
        $this->book($assignment, 'in_progress', [[$this->monday->addDays(2), 1]]);
        $this->book($assignment, 'completed', [[$this->monday->addDays(3), 1]]);
        $this->book($assignment, 'cancelled', [[$this->monday->addDays(4), 1]]);
        \App\Models\Ticket::create(['work_item_id' => WorkItem::first()->id, 'title' => 'T', 'description' => 'd', 'estimated_hours' => 1, 'position' => 0]);

        $this->dashboard()
            ->assertJsonPath('work_summary.projects.total', 5)
            ->assertJsonPath('work_summary.projects.cancelled', 1)
            ->assertJsonPath('work_summary.projects.scheduled', 1)
            ->assertJsonPath('work_summary.projects.in_progress', 2)
            ->assertJsonPath('work_summary.projects.completed', 1)
            ->assertJsonPath('work_summary.tickets.total', 1);
    }

    public function test_attention_lists_every_issue_most_severe_first(): void
    {
        $x = $this->agency('Xylo');
        $y = $this->agency('Yarrow');
        $this->agency('Idle Agency');                       // no held employees, no open projects
        $this->agency('Suspended Idle', 'suspended');       // suspended agencies are not flagged
        $busy = $this->employee('Busy');
        $other = $this->employee('Other');
        $assignmentBusy = $this->hold($busy, $x);
        $this->hold($other, $y);
        $this->book($assignmentBusy, 'in_progress', $this->weekHours(34));   // 94 percent: over 90

        // Idle employees: never held and created 10 days ago; freed 9 days ago. Not idle: new, freed 3 days ago.
        $this->employee('Old and free', ['created_at' => now()->subDays(10)]);
        $this->employee('Brand new');
        foreach ([[9, 'Freed long ago'], [3, 'Freed recently']] as [$days, $name]) {
            $employee = $this->employee($name);
            $assignment = $this->hold($employee, $x);
            $assignment->update(['status' => 'ended', 'active_employee_id' => null, 'ended_at' => now()->subDays($days)]);
        }

        // Projects: one overdue, one due in 48 hours, one due in three days (not flagged), one overdue but completed.
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $this->book($assignmentBusy, 'scheduled', [[$this->monday, 0.5]], $today->subDays(2));
        $this->book($assignmentBusy, 'scheduled', [[$this->monday->addDay(), 0.5]], $today->addDays(2));
        $this->book($assignmentBusy, 'scheduled', [[$this->monday->addDays(2), 0.5]], $today->addDays(3));
        $this->book($assignmentBusy, 'completed', [[$this->monday->addDays(3), 0.5]], $today->subDays(9));

        // Documents: expired, expiring in 10 days, expiring in 40 days, no expiry.
        foreach ([-1, 10, 40, null] as $offset) {
            DB::table('employee_documents')->insert([
                'user_id' => $busy->id, 'type' => 'cv', 'original_name' => 'cv.pdf', 'path' => 'employees/x/'.uniqid().'.pdf',
                'disk' => 'local', 'mime' => 'application/pdf', 'size' => 10,
                'expires_at' => $offset === null ? null : $today->addDays($offset)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $attention = collect($this->dashboard()->json('attention'));

        $this->assertSame(
            ['overdue_projects', 'expired_documents', 'overloaded_employees', 'due_soon_projects', 'expiring_documents', 'idle_employees', 'idle_agencies'],
            $attention->pluck('type')->all()
        );
        $this->assertSame(['danger', 'danger', 'warning', 'warning', 'warning', 'info', 'info'], $attention->pluck('severity')->all());
        $counts = $attention->pluck('count', 'type');
        $this->assertSame(1, $counts['overdue_projects']);
        $this->assertSame(1, $counts['expired_documents']);
        $this->assertSame(1, $counts['overloaded_employees']);
        $this->assertSame(1, $counts['due_soon_projects']);
        $this->assertSame(1, $counts['expiring_documents']);
        $this->assertSame(2, $counts['idle_employees']);
        $this->assertSame(1, $counts['idle_agencies']);
        $this->assertSame('1 project is overdue', $attention->firstWhere('type', 'overdue_projects')['label']);
        $this->assertSame('2 employees have been available for 7+ days', $attention->firstWhere('type', 'idle_employees')['label']);
        $this->assertSame('/admin/projects', $attention->firstWhere('type', 'overdue_projects')['link']);
        $this->assertSame('/admin/agency-owners', $attention->firstWhere('type', 'idle_agencies')['link']);
        foreach ($attention as $item) {
            $this->assertStringStartsWith('/admin/', $item['link']);
        }
    }

    public function test_attention_is_empty_when_nothing_needs_it(): void
    {
        $agency = $this->agency('Agency X');
        $assignment = $this->hold($this->employee('Ann'), $agency);
        $this->book($assignment, 'in_progress', $this->weekHours(12));

        $this->dashboard()->assertJsonPath('attention', []);
    }

    public function test_numbers_match_the_existing_assignment_usage_endpoint(): void
    {
        $agency = $this->agency('Agency X');
        $client = $this->client($agency, 'Holder');
        $employee = $this->employee('Ann');
        $assignment = $this->hold($employee, $agency, $client);
        $this->book($assignment, 'in_progress', $this->weekHours(21));
        $this->book($assignment, 'scheduled', [[$this->monday->addDays(5), 3]]);
        $this->book($assignment, 'cancelled', [[$this->monday->addDays(4), 6]]);

        $usage = $this->actingAs($client)
            ->getJson("/api/agency/assignments/{$assignment->id}/usage?week=".$this->monday->toDateString())
            ->assertOk();
        $row = $this->actingAs($this->admin)->getJson('/api/admin/dashboard?week='.$this->monday->toDateString())
            ->assertOk()->json('employee_capacity.0');

        $this->assertSame((float) $usage->json('used'), (float) $row['used_hours']);
        $this->assertSame(24.0, (float) $row['used_hours']);
        $this->assertSame((float) $usage->json('capacity.weekly_hours'), (float) $row['budget_hours']);
        $this->assertSame($usage->json('week_start'), $this->dashboard('?week='.$this->monday->toDateString())->json('week_start'));
        $this->assertSame(67, $row['utilization_percent']);                 // 24 / 36
        // The assignment's own agency shows the same hours.
        $agencyRow = collect($this->dashboard()->json('agency_rows'))->firstWhere('id', $agency->id);
        $this->assertSame((float) $usage->json('used'), (float) $agencyRow['hours_booked_this_week']);
    }

    public function test_query_count_does_not_grow_with_the_number_of_employees_and_agencies(): void
    {
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $agency = $this->agency('Seed');
        $this->book($this->hold($this->employee('Seed Employee'), $agency), 'in_progress', $this->weekHours(6));
        $measure();
        $before = $measure();

        foreach (range(1, 20) as $number) {
            $other = $this->agency('Agency '.$number);
            User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $other->id]);
            $employee = $this->employee('Employee '.$number);
            $employee->employeeProfile()->create(['department' => 'web']);
            $this->book($this->hold($employee, $other), 'in_progress', $this->weekHours(6));
        }

        $this->assertSame($before, $measure());
    }
}
