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
use Tests\TestCase;

/**
 * What the employee workspace (/my-work) reads from the API: today's date in the app timezone, the working days and
 * the hour limits, and when each ticket last changed. Only the caller's own work is ever returned.
 */
class EmployeeWorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $other;

    private WorkItem $mine;

    private WorkItem $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Karachi']);
        $agency = Agency::create(['name' => 'Acme']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $this->employee = User::factory()->create(['role' => Role::Employee]);
        $this->other = User::factory()->create(['role' => Role::Employee]);
        $this->mine = $this->project($agency, $owner, $this->employee, 'Mine');
        $this->theirs = $this->project($agency, $owner, $this->other, 'Theirs');
    }

    private function project(Agency $agency, User $owner, User $employee, string $title): WorkItem
    {
        $assignment = EmployeeAssignment::create([
            'holder_id' => $owner->id, 'agency_id' => $agency->id, 'employee_id' => $employee->id, 'status' => 'active',
            'source' => 'owner', 'active_employee_id' => $employee->id, 'started_at' => now(),
        ]);
        $item = WorkItem::create([
            'agency_id' => $agency->id, 'client_id' => $owner->id, 'assignment_id' => $assignment->id, 'employee_id' => $employee->id,
            'title' => $title, 'description' => 'D.', 'estimated_hours' => 6, 'start_date' => '2026-12-10', 'due_date' => '2026-12-12', 'status' => 'scheduled',
        ]);
        static $number = 0;
        Ticket::create([
            'agency_id' => $agency->id, 'work_item_id' => $item->id, 'created_by' => $owner->id, 'number' => ++$number,
            'title' => "{$title} ticket", 'description' => 'x', 'estimated_hours' => 2, 'priority' => 'normal', 'status' => 'todo', 'position' => 1,
        ]);

        return $item;
    }

    public function test_today_is_the_app_timezone_date_not_utc(): void
    {
        // 22:30 UTC on the 9th is already 03:30 on the 10th in Karachi.
        $this->travelTo(CarbonImmutable::parse('2026-12-09 22:30', 'UTC'));

        $this->actingAs($this->employee)->getJson('/api/work-items/mine')->assertOk()
            ->assertJsonPath('clock.today', '2026-12-10')
            ->assertJsonPath('clock.timezone', 'Asia/Karachi');
        $this->getJson('/api/work-items/mine/tickets')->assertOk()->assertJsonPath('clock.today', '2026-12-10');
    }

    public function test_limits_and_working_days_come_from_the_scheduling_config(): void
    {
        config(['scheduling.daily_capacity_hours' => 5, 'scheduling.weekly_capacity_hours' => 25, 'scheduling.working_days' => [1, 2, 3, 4, 5]]);

        $this->actingAs($this->employee)->getJson('/api/work-items/mine')->assertOk()
            ->assertJsonPath('clock.daily_hours', 5)
            ->assertJsonPath('clock.weekly_hours', 25)
            ->assertJsonPath('clock.working_days', [1, 2, 3, 4, 5])
            // The existing key stays as it was.
            ->assertJsonPath('capacity.daily_hours', 5)
            ->assertJsonPath('capacity.weekly_hours', 25);
        $this->getJson('/api/work-items/mine/tickets')->assertOk()
            ->assertJsonPath('capacity.weekly_hours', 25)
            ->assertJsonPath('clock.working_days', [1, 2, 3, 4, 5]);
    }

    public function test_tickets_say_when_they_last_changed_and_only_the_callers_work_is_listed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-09 08:00', 'Asia/Karachi'));
        Ticket::query()->where('work_item_id', $this->mine->id)->first()->update(['title' => 'Renamed']);

        $response = $this->actingAs($this->employee)->getJson('/api/work-items/mine/tickets')->assertOk()
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.title', 'Renamed')
            ->assertJsonPath('tickets.0.updated_at', CarbonImmutable::parse('2026-12-09 08:00', 'Asia/Karachi')->toISOString());
        $this->assertStringNotContainsString('Theirs', $response->getContent());

        $projects = $this->getJson('/api/work-items/mine')->assertOk()->assertJsonCount(1, 'projects');
        $this->assertSame($this->mine->id, $projects->json('projects.0.id'));
    }

    public function test_only_employees_reach_these_endpoints(): void
    {
        $owner = User::query()->where('role', Role::AgencyOwner->value)->first();
        $this->actingAs($owner)->getJson('/api/work-items/mine')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/work-items/mine/tickets')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/work-items/mine')->assertUnauthorized();
    }
}
