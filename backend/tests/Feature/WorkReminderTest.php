<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\NotificationPreferences;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class WorkReminderTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $owner;

    private User $employee;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-12-09 08:00', config('app.timezone')));
        $this->today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $this->agency = Agency::create(['name' => 'Acme']);
        $this->owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $this->agency->id]);
        $this->employee = User::factory()->create(['role' => Role::Employee, 'is_active' => true]);
    }

    private function project(int $startOffset, int $dueOffset, string $status = 'scheduled', ?User $employee = null, string $title = 'Site'): WorkItem
    {
        return WorkItem::create([
            'agency_id' => $this->agency->id, 'client_id' => $this->owner->id, 'employee_id' => ($employee ?? $this->employee)->id,
            'title' => $title, 'description' => 'D.', 'estimated_hours' => 6,
            'start_date' => $this->today->addDays($startOffset)->toDateString(), 'due_date' => $this->today->addDays($dueOffset)->toDateString(),
            'status' => $status,
        ]);
    }

    private function ticket(WorkItem $project, string $status = 'todo', int $startOffset = 0, int $dueOffset = 9): Ticket
    {
        static $number = 0;

        return Ticket::create([
            'agency_id' => $this->agency->id, 'work_item_id' => $project->id, 'created_by' => $this->owner->id, 'number' => ++$number,
            'title' => 'Ticket '.$number, 'description' => 'x', 'estimated_hours' => 1, 'priority' => 'normal', 'status' => $status, 'position' => $number,
            'start_date' => $this->today->addDays($startOffset)->toDateString(), 'due_date' => $this->today->addDays($dueOffset)->toDateString(),
        ]);
    }

    private function sweep(): void
    {
        $this->artisan('app:send-work-reminders')->assertSuccessful();
    }

    /** @return list<string> */
    private function kinds(?User $user = null): array
    {
        return DatabaseNotification::query()->where('notifiable_id', ($user ?? $this->employee)->id)->orderBy('created_at')->get()
            ->map(fn ($n) => $n->data['kind'] ?? $n->data['type'])->sort()->values()->all();
    }

    public function test_a_project_starting_today_and_each_deadline_stage_is_notified(): void
    {
        $this->project(0, 20, 'scheduled', null, 'Starts now');
        $this->project(-3, 1, 'in_progress', null, 'Due tomorrow');
        $this->project(-3, 0, 'in_progress', null, 'Due today');
        $this->project(-3, 3, 'in_progress', null, 'Due in three');
        $this->project(-9, -2, 'in_progress', null, 'Late');
        $this->project(-3, 5, 'in_progress', null, 'Not yet');

        $this->artisan('app:send-work-reminders')->expectsOutput('Reminders sent: 5')->assertSuccessful();

        $this->assertSame(['deadline_overdue', 'deadline_soon', 'deadline_soon', 'deadline_today', 'project_start'], $this->kinds());
        $bodies = DatabaseNotification::query()->get()->pluck('data.body')->implode(' | ');
        $this->assertStringContainsString('Project “Due tomorrow” is due tomorrow.', $bodies);
        $this->assertStringContainsString('overdue by 2 days', $bodies);
        $this->assertStringNotContainsString('Not yet', $bodies);
        $this->assertSame('/my-work', DatabaseNotification::query()->first()->data['url_path']);
    }

    public function test_running_twice_creates_no_duplicates_and_overdue_repeats_each_day(): void
    {
        $this->project(0, 1);
        $this->project(-9, -1, 'in_progress', null, 'Late');

        $this->sweep();
        $first = DatabaseNotification::query()->count();
        $this->assertSame(3, $first);
        $this->artisan('app:send-work-reminders')->expectsOutput('Reminders sent: 0');
        $this->assertSame($first, DatabaseNotification::query()->count());

        $this->travelTo($this->today->addDay()->setTime(8, 0));
        $this->sweep();
        // Tomorrow: the "start" and old "due tomorrow" are not repeated; the project now due today and the overdue one are new.
        $this->assertSame($first + 2, DatabaseNotification::query()->count());
        $this->sweep();
        $this->assertSame($first + 2, DatabaseNotification::query()->count());
    }

    public function test_tickets_notify_only_when_todo_or_in_progress_on_open_projects(): void
    {
        $project = $this->project(-3, 30, 'in_progress');
        $todo = $this->ticket($project, 'todo', 0, 20);
        $this->ticket($project, 'in_review', 0, 0);
        $this->ticket($project, 'done', 0, 0);
        $this->ticket($project, 'cancelled', 0, 0);
        $this->ticket($project, 'in_progress', -2, 1);

        $this->sweep();

        $this->assertSame(['deadline_soon', 'ticket_start'], $this->kinds());
        $start = DatabaseNotification::query()->get()->first(fn ($n) => ($n->data['kind'] ?? '') === 'ticket_start');
        $this->assertSame($todo->id, $start->data['ticket_id']);
    }

    public function test_closed_projects_and_their_tickets_are_ignored(): void
    {
        foreach (['completed', 'cancelled'] as $status) {
            $project = $this->project(0, 0, $status);
            $this->ticket($project, 'todo', 0, 0);
        }
        $this->sweep();
        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    public function test_inactive_employees_and_other_roles_get_nothing(): void
    {
        $inactive = User::factory()->create(['role' => Role::Employee, 'is_active' => false]);
        $this->project(0, 0, 'scheduled', $inactive);
        $this->project(0, 0, 'scheduled', $this->owner, 'Owner row');
        $this->sweep();
        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    public function test_a_failed_delivery_does_not_stop_the_others_and_is_retried(): void
    {
        $this->project(0, 20);
        $this->project(-3, 1, 'in_progress', null, 'Second');
        $calls = 0;
        $this->mock(NotificationPreferences::class)->shouldReceive('channelsFor')->andReturnUsing(function () use (&$calls): array {
            if (++$calls === 1) {
                throw new \RuntimeException('channel down');
            }

            return ['database'];
        });

        $this->artisan('app:send-work-reminders')->expectsOutput('Reminders sent: 1')->assertSuccessful();
        $this->assertSame(1, DatabaseNotification::query()->count());

        // The one that failed is not remembered as sent, so the next run delivers it.
        $this->artisan('app:send-work-reminders')->expectsOutput('Reminders sent: 1')->assertSuccessful();
        $this->assertSame(2, DatabaseNotification::query()->count());
    }
}
