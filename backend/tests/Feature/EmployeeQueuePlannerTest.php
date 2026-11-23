<?php

namespace Tests\Feature;

use App\Models\EmployeeAssignment;
use App\Models\QueueEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\AssignmentService;
use App\Services\EmployeeQueuePlanner;
use App\Services\QueueConflict;
use App\Services\TicketPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

class EmployeeQueuePlannerTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private User $employee;

    private User $owner;

    private EmployeeAssignment $assignment;

    private CarbonImmutable $mon;

    protected function setUp(): void
    {
        parent::setUp();
        // A fixed Monday morning, before the 12:00 cutoff.
        $this->travelTo(CarbonImmutable::parse('2026-12-07 08:00', config('app.timezone')));
        $this->setUpWeek();
        $this->mon = $this->today;
        $agency = $this->makeAgency('Queue Agency');
        $this->owner = $this->makeOwner($agency);
        $this->employee = $this->makeEmployee('Emma');
        $this->assignment = $this->hold($this->employee, $agency, $this->owner);
    }

    private function day(int $offset): string
    {
        return $this->mon->addDays($offset)->toDateString();
    }

    private function item(string $title, float $hours, int $startOffset = 0, int $dueOffset = 12, string $status = 'scheduled'): WorkItem
    {
        $planner = app(EmployeeQueuePlanner::class);

        return WorkItem::create([
            'agency_id' => $this->assignment->agency_id,
            'client_id' => $this->owner->id,
            'assignment_id' => $this->assignment->id,
            'employee_id' => $this->employee->id,
            'title' => $title,
            'description' => 'Desc.',
            'estimated_hours' => $hours,
            'start_date' => $this->day($startOffset),
            'due_date' => $this->day($dueOffset),
            'status' => $status,
            'queue_position' => $planner->nextPosition($this->employee),
        ]);
    }

    /** @return array<string, string> date => hours */
    private function schedule(WorkItem $item): array
    {
        return $item->allocations()->orderBy('work_date')->get()
            ->mapWithKeys(fn ($a): array => [$a->work_date->toDateString() => $a->hours])->all();
    }

    private function planner(): EmployeeQueuePlanner
    {
        return app(EmployeeQueuePlanner::class);
    }

    public function test_queue_order_decides_who_gets_the_earliest_days(): void
    {
        $a = $this->item('A', 12);
        $b = $this->item('B', 6);
        $this->planner()->replan($this->employee);

        $this->assertSame([$this->day(0) => '6.00', $this->day(1) => '6.00'], $this->schedule($a));
        $this->assertSame([$this->day(2) => '6.00'], $this->schedule($b));

        $this->planner()->reorder($this->employee, [$b->id, $a->id], $this->owner);

        $this->assertSame([$this->day(0) => '6.00'], $this->schedule($b->fresh()));
        $this->assertSame([$this->day(1) => '6.00', $this->day(2) => '6.00'], $this->schedule($a->fresh()));
        $this->assertSame(1, $b->fresh()->queue_position);
        $this->assertSame(2, $a->fresh()->queue_position);
        $this->assertSame(2, QueueEvent::query()->where('type', 'reordered')->count());
    }

    public function test_daily_and_weekly_capacity_are_honoured(): void
    {
        $big = $this->item('Big', 40, 0, 20);
        $this->planner()->replan($this->employee);

        $schedule = $this->schedule($big);
        $this->assertSame(array_sum(array_map('floatval', $schedule)), 40.0);
        foreach ($schedule as $hours) {
            $this->assertLessThanOrEqual(6.0, (float) $hours);
        }
        $firstWeek = array_filter($schedule, fn ($h, $d): bool => $d <= $this->day(5), ARRAY_FILTER_USE_BOTH);
        $this->assertSame(36.0, array_sum(array_map('floatval', $firstWeek)));
        $this->assertArrayHasKey($this->day(7), $schedule);
        $this->assertSame('4.00', $schedule[$this->day(7)]);
    }

    public function test_sundays_and_non_working_dates_are_skipped(): void
    {
        config(['scheduling.non_working_dates' => [$this->day(1)]]);
        $item = $this->item('Skips', 36, 0, 14);
        $this->planner()->replan($this->employee);

        $dates = array_keys($this->schedule($item));
        $this->assertNotContains($this->day(1), $dates);
        $this->assertNotContains($this->day(6), $dates); // Sunday
        $this->assertContains($this->day(7), $dates);
    }

    public function test_today_is_used_before_the_cutoff_and_skipped_after_it(): void
    {
        $item = $this->item('Today', 6, 0, 5);
        $this->planner()->replan($this->employee);
        $this->assertSame([$this->day(0) => '6.00'], $this->schedule($item));

        $this->travelTo($this->mon->setTime(14, 0));
        $later = $this->item('Later', 6, 0, 5);
        $this->planner()->replan($this->employee);
        $this->assertSame([$this->day(0) => '6.00'], $this->schedule($item->fresh()), 'hours on a day that has begun stay');
        $this->assertSame([$this->day(1) => '6.00'], $this->schedule($later), 'after the cutoff today can no longer take new work');
    }

    public function test_after_the_cutoff_todays_hours_are_frozen(): void
    {
        $a = $this->item('A', 6, 0, 5);
        $this->planner()->replan($this->employee);
        $this->travelTo($this->mon->setTime(14, 0));
        $b = $this->item('B', 6, 0, 5);
        $this->planner()->reorder($this->employee, [$b->id, $a->id], $this->owner);

        $this->assertSame([$this->day(0) => '6.00'], $this->schedule($a->fresh()), 'hours on a day that has begun do not move');
        $this->assertSame([$this->day(1) => '6.00'], $this->schedule($b->fresh()));
    }

    public function test_a_reorder_that_breaks_a_due_date_fails_and_changes_nothing(): void
    {
        $a = $this->item('Alpha', 12, 0, 1); // due Tuesday
        $b = $this->item('Beta', 6, 0, 5);
        $this->planner()->replan($this->employee);
        $before = [$this->schedule($a), $this->schedule($b), $a->fresh()->queue_position, $b->fresh()->queue_position];

        try {
            $this->planner()->reorder($this->employee, [$b->id, $a->id], $this->owner);
            $this->fail('expected a conflict');
        } catch (QueueConflict $conflict) {
            $this->assertStringContainsString('Alpha', $conflict->getMessage());
            $this->assertStringContainsString('1 day', $conflict->getMessage());
            $this->assertSame($a->id, $conflict->conflicts[0]['work_item_id']);
        }

        $this->assertSame($before, [$this->schedule($a), $this->schedule($b), $a->fresh()->queue_position, $b->fresh()->queue_position]);
        $this->assertSame(1, QueueEvent::query()->where('type', 'conflict_rejected')->count());
        $this->assertSame(0, QueueEvent::query()->where('type', 'reordered')->count());
    }

    public function test_preview_reports_slips_and_writes_nothing(): void
    {
        $a = $this->item('Alpha', 12, 0, 1);
        $b = $this->item('Beta', 6, 0, 5);
        $this->planner()->replan($this->employee);
        $queries = DB::getQueryLog();

        $preview = $this->planner()->preview($this->employee, [$b->id, $a->id]);

        $this->assertFalse($preview['fits']);
        $byId = collect($preview['items'])->keyBy('work_item_id');
        $this->assertSame(1, $byId[$a->id]['slips_by_days']);
        $this->assertFalse($byId[$a->id]['fits']);
        $this->assertTrue($byId[$b->id]['fits']);
        $this->assertSame($this->day(0), $byId[$b->id]['planned_start']);
        $this->assertSame([$this->day(0) => '6.00', $this->day(1) => '6.00'], $this->schedule($a));
        $this->assertSame(1, $a->fresh()->queue_position);
    }

    public function test_the_order_must_list_every_open_item_once(): void
    {
        $a = $this->item('A', 6);
        $this->item('B', 6);
        $this->expectException(ValidationException::class);
        $this->planner()->preview($this->employee, [$a->id]);
    }

    public function test_in_progress_work_is_never_moved_or_overtaken(): void
    {
        $a = $this->item('Started', 12, 0, 12, 'in_progress');
        $b = $this->item('Waiting', 6);
        $this->planner()->replan($this->employee);
        $this->ticket($a, 'in_progress');

        // Not allowed to put something in front of work that has started.
        try {
            $this->planner()->reorder($this->employee, [$b->id, $a->id], $this->owner);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already in progress', $e->getMessage());
        }

        // A replan keeps today's hours on the started project even though it is still before the cutoff.
        $c = $this->item('Rush', 6);
        $this->planner()->reorder($this->employee, [$a->id, $c->id, $b->id], $this->owner);
        $this->assertSame('6.00', $this->schedule($a->fresh())[$this->day(0)]);
        $this->assertSame([$this->day(1) => '6.00'], $this->schedule($a->fresh()) === [] ? [] : array_slice($this->schedule($a->fresh()), 1, 1));
        $this->assertArrayHasKey($this->day(2), $this->schedule($c->fresh()));
    }

    public function test_hours_already_worked_stay_and_only_the_rest_is_planned(): void
    {
        $a = $this->item('Half done', 12, -3, 12, 'in_progress');
        $a->allocations()->create(['employee_id' => $this->employee->id, 'work_date' => $this->mon->subDays(2)->toDateString(), 'hours' => 6]);
        $this->planner()->replan($this->employee);

        $schedule = $this->schedule($a->fresh());
        $this->assertSame('6.00', $schedule[$this->mon->subDays(2)->toDateString()]);
        $this->assertSame(12.0, array_sum(array_map('floatval', $schedule)));
        $this->assertArrayHasKey($this->day(0), $schedule);
    }

    public function test_ticket_allocations_follow_the_project_after_a_reorder(): void
    {
        $a = $this->item('A', 6);
        $b = $this->item('B', 6);
        $this->planner()->replan($this->employee);
        $ticketA = $this->ticket($a, 'todo');
        app(TicketPlanner::class)->plan($a);
        $this->assertSame($this->day(0), $ticketA->fresh()->start_date->toDateString());

        $this->planner()->reorder($this->employee, [$b->id, $a->id], $this->owner);

        $this->assertSame($this->day(1), $ticketA->fresh()->start_date->toDateString());
        $this->assertSame([$this->day(1)], DB::table('ticket_allocations')->where('ticket_id', $ticketA->id)->pluck('work_date')->all());
    }

    public function test_repeated_reorders_never_overbook_a_day(): void
    {
        $items = [$this->item('A', 15), $this->item('B', 9), $this->item('C', 20), $this->item('D', 7)];
        $this->planner()->replan($this->employee);

        foreach ([[3, 2, 1, 0], [1, 3, 0, 2], [2, 0, 3, 1], [0, 1, 2, 3]] as $permutation) {
            $this->planner()->reorder($this->employee, array_map(fn (int $i): int => $items[$i]->id, $permutation), $this->owner);
            $perDay = DB::table('work_item_allocations')->selectRaw('work_date, SUM(hours) AS h')->groupBy('work_date')->pluck('h', 'work_date');
            foreach ($perDay as $hours) {
                $this->assertLessThanOrEqual(6.0, (float) $hours);
            }
            $this->assertEqualsWithDelta(51.0, (float) DB::table('work_item_allocations')->sum('hours'), 0.001);
        }
    }

    public function test_adding_a_project_that_does_not_fit_is_rejected_without_touching_the_others(): void
    {
        $a = $this->item('A', 12, 0, 1);
        $this->planner()->replan($this->employee);
        $late = $this->item('Too big', 6, 0, 1);

        try {
            $this->planner()->replan($this->employee, null, $this->owner, 'added', ['work_item_id' => $late->id]);
            $this->fail('expected a conflict');
        } catch (QueueConflict $e) {
            $this->assertSame($late->id, $e->conflicts[0]['work_item_id']);
        }
        $this->assertSame([], $this->schedule($late));
        $this->assertCount(2, $this->schedule($a));
    }

    public function test_assignment_end_clears_positions_and_logs_removals(): void
    {
        $a = $this->item('A', 6);
        $this->planner()->replan($this->employee);

        app(AssignmentService::class)->end($this->assignment, $this->owner);

        $this->assertSame(0, $a->fresh()->queue_position);
        $this->assertSame('cancelled', $a->fresh()->status);
        $this->assertSame(1, QueueEvent::query()->where('type', 'removed')->where('work_item_id', $a->id)->count());
    }

    private function ticket(WorkItem $item, string $status): Ticket
    {
        static $number = 0;

        return Ticket::create([
            'agency_id' => $item->agency_id,
            'work_item_id' => $item->id,
            'created_by' => $this->owner->id,
            'number' => ++$number,
            'title' => 'Ticket '.$number,
            'description' => 'Do it.',
            'estimated_hours' => 6,
            'priority' => 'normal',
            'status' => $status,
            'position' => $number,
        ]);
    }
}
