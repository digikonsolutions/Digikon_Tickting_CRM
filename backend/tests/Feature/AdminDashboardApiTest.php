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

class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summarises_counts_tickets_scheduled_hours_and_recent_projects(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $a = $this->project('A scheduled', 'scheduled', [[$today->addDay(), 4], [$today->addDays(2), 2]]);
        $b = $this->project('B in progress', 'in_progress', [[$today->addDay(), 1.5]]);
        $this->project('C completed', 'completed', [[$today->addDay(), 6]]);
        $this->project('D cancelled', 'cancelled', [[$today->addDay(), 6]]);
        $e = $this->project('E scheduled far away', 'scheduled', [[$today->addDays(8), 6]]);
        foreach ([$a, $a, $b] as $position => $project) {
            Ticket::create(['work_item_id' => $project->id, 'title' => 'T'.$position, 'description' => 'd', 'estimated_hours' => 1, 'position' => $position]);
        }

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('today', $today->toDateString())
            ->assertJsonPath('projects.total', 5)
            ->assertJsonPath('projects.scheduled', 2)
            ->assertJsonPath('projects.in_progress', 1)
            ->assertJsonPath('projects.completed', 1)
            ->assertJsonPath('projects.cancelled', 1)
            ->assertJsonPath('tickets.total', 3)
            ->assertJsonCount(7, 'scheduled_hours')
            ->assertJsonPath('scheduled_hours.0.date', $today->toDateString())
            ->assertJsonPath('scheduled_hours.0.hours', '0.00')
            ->assertJsonPath('scheduled_hours.1.hours', '5.50')   // A 4 + B 1.5; completed and cancelled excluded
            ->assertJsonPath('scheduled_hours.2.hours', '2.00')
            ->assertJsonPath('scheduled_hours.6.hours', '0.00')   // E is beyond the 7-day window
            ->assertJsonCount(4, 'recent_projects')
            ->assertJsonPath('recent_projects.0.title', 'E scheduled far away')
            ->assertJsonPath('recent_projects.0.agency_name', 'Northwind');
        $this->assertSame(
            ['E scheduled far away', 'D cancelled', 'C completed', 'B in progress'],
            collect($response->json('recent_projects'))->pluck('title')->all()
        );
        $this->assertSame(2, $response->json('recent_projects.3.tickets_total') + 1); // B has one ticket (progress fields present)
    }

    public function test_recent_projects_can_be_filtered_by_status(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        foreach (range(1, 6) as $number) {
            $this->project('Scheduled '.$number, 'scheduled', [[$today->addDay(), 1]]);
        }
        $this->project('Done', 'completed', [[$today->addDay(), 1]]);

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard?status=scheduled')->assertOk()
            ->assertJsonCount(4, 'recent_projects')
            ->assertJsonPath('projects.total', 7);
        $this->assertSame(['Scheduled 6', 'Scheduled 5', 'Scheduled 4', 'Scheduled 3'], collect($response->json('recent_projects'))->pluck('title')->all());
        $this->getJson('/api/admin/dashboard?status=completed')->assertJsonCount(1, 'recent_projects');
        $this->getJson('/api/admin/dashboard?status=bogus')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_dashboard_is_admin_only_and_uses_a_constant_number_of_queries(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        foreach ([Role::AgencyOwner, Role::AgencyClient, Role::Employee, Role::TeamLead] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/admin/dashboard')->assertForbidden();
        }

        $admin = User::factory()->create(['role' => Role::Admin]);
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $this->project('One', 'scheduled', [[$today->addDay(), 1]]);
        $this->actingAs($admin);
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/admin/dashboard')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $this->getJson('/api/admin/dashboard')->assertOk();
        $before = $measure();
        foreach (range(1, 15) as $number) {
            $this->project('More '.$number, 'in_progress', [[$today->addDays(2), 1]]);
        }

        $this->assertSame($before, $measure());
    }

    /**
     * @param list<array{CarbonImmutable, float}> $allocations
     */
    private function project(string $title, string $status, array $allocations): WorkItem
    {
        static $agency = null;
        $agency = Agency::query()->where('name', 'Northwind')->first() ?? Agency::create(['name' => 'Northwind']);
        $client = User::query()->where('email', 'dash-client@example.com')->first()
            ?? User::factory()->create(['email' => 'dash-client@example.com', 'role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::query()->where('email', 'dash-employee@example.com')->first()
            ?? User::factory()->create(['email' => 'dash-employee@example.com', 'role' => Role::Employee]);
        $assignment = EmployeeAssignment::query()->where('holder_id', $client->id)->first()
            ?? EmployeeAssignment::create([
                'holder_id' => $client->id, 'agency_id' => $agency->id, 'employee_id' => $employee->id,
                'status' => 'active', 'active_employee_id' => $employee->id, 'started_at' => now(),
            ]);
        $first = $allocations[0][0];
        $item = WorkItem::create([
            'agency_id' => $agency->id, 'client_id' => $client->id, 'assignment_id' => $assignment->id, 'employee_id' => $employee->id,
            'title' => $title, 'description' => 'Desc.', 'estimated_hours' => array_sum(array_column($allocations, 1)),
            'start_date' => $first->toDateString(), 'due_date' => $first->addDays(10)->toDateString(), 'status' => $status,
        ]);
        foreach ($allocations as [$date, $hours]) {
            $item->allocations()->create(['employee_id' => $employee->id, 'work_date' => $date->toDateString(), 'hours' => $hours]);
        }

        return $item;
    }
}
