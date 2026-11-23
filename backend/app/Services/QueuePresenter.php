<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItemAllocation;
use Carbon\CarbonImmutable;

/**
 * The read model behind every queue endpoint: what an employee is doing now, what is next, and how much room is
 * left. The same shape serves owners, clients, the employee and admins; it carries no agency ids, other agencies or
 * pool wording, and no prices.
 */
class QueuePresenter
{
    public function __construct(private EmployeeQueuePlanner $planner, private WorkItemScheduler $scheduler) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $employee): array
    {
        $timezone = config('app.timezone');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $items = $this->planner->openItems($employee);
        $pinned = $this->planner->pinnedIds($items);

        $allocations = WorkItemAllocation::query()->whereIn('work_item_id', $items->pluck('id'))->get()->groupBy('work_item_id');
        $items->load('client');

        $upNext = [];
        foreach ($items as $item) {
            if (in_array($item->id, $pinned, true)) {
                continue;
            }
            $rows = $allocations->get($item->id, collect())->sortBy('work_date');
            $scheduled = (int) round($rows->sum(fn ($row): float => (float) $row->hours) * 100);
            $end = $rows->isEmpty() ? null : $rows->last()->work_date->toDateString();
            $upNext[] = [
                'work_item_id' => $item->id,
                'title' => $item->title,
                'client_name' => $item->client?->name,
                'created_by_id' => $item->client_id,
                'estimated_hours' => $item->estimated_hours,
                'planned_start' => $rows->isEmpty() ? null : $rows->first()->work_date->toDateString(),
                'planned_end' => $end,
                'due_date' => $item->due_date->toDateString(),
                'status' => $item->status,
                'position' => $item->queue_position,
                'fits' => $scheduled >= (int) round((float) $item->estimated_hours * 100) && ($end === null || $end <= $item->due_date->toDateString()),
            ];
        }

        return [
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'photo_url' => $employee->photoUrl()],
            'now' => $this->now($employee, $items, $allocations, $today),
            'up_next' => $upNext,
            // Work that has started stays in front; an order for the planner is these ids followed by up_next's.
            'pinned_work_item_ids' => $pinned,
            'capacity' => $this->capacity($employee, $items, $allocations, $today),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function now(User $employee, $items, $allocations, string $today): ?array
    {
        $ticket = Ticket::query()
            ->with(['agency', 'workItem'])
            ->withSum(['timeEntries as logged_minutes' => fn ($query) => $query->whereNotNull('minutes')], 'minutes')
            ->whereIn('work_item_id', $items->pluck('id'))
            ->where('status', 'in_progress')
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();

        if ($ticket !== null) {
            return [
                'kind' => 'ticket',
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->key,
                'title' => $ticket->title,
                'work_item_id' => $ticket->work_item_id,
                'project_title' => $ticket->workItem?->title,
                'started_at' => $ticket->started_at?->toISOString(),
                'hours_logged' => round(((int) $ticket->logged_minutes) / 60, 2),
                'hours_estimated' => (float) $ticket->estimated_hours,
            ];
        }

        foreach ($items as $item) {
            $todays = $allocations->get($item->id, collect())->first(fn ($row): bool => $row->work_date->toDateString() === $today);
            if ($todays !== null) {
                return [
                    'kind' => 'scheduled',
                    'ticket_id' => null,
                    'ticket_key' => null,
                    'title' => $item->title,
                    'work_item_id' => $item->id,
                    'project_title' => $item->title,
                    'started_at' => null,
                    'hours_logged' => null,
                    'hours_estimated' => (float) $todays->hours,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function capacity(User $employee, $items, $allocations, string $today): array
    {
        $timezone = config('app.timezone');
        $now = CarbonImmutable::parse($today, $timezone);
        $weekStart = $now->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $weekEnd = $now->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)->toDateString();

        $todayUnits = 0;
        $weekUnits = 0;
        $lastEnd = null;
        foreach ($allocations->flatten() as $row) {
            $date = $row->work_date->toDateString();
            $units = (int) round((float) $row->hours * 100);
            if ($date === $today) {
                $todayUnits += $units;
            }
            if ($date >= $weekStart && $date <= $weekEnd) {
                $weekUnits += $units;
            }
            $lastEnd = $lastEnd === null || $date > $lastEnd ? $date : $lastEnd;
        }

        $weekCap = (int) round((float) config('scheduling.weekly_capacity_hours', 36) * 100);

        // First working day from today that still has spare hours, by the scheduler's own rules.
        $free = null;
        foreach ($this->scheduler->dailyAvailability($employee, $now, $now->addDays(120)) as $day) {
            if ($day['working_day'] && (float) $day['available_hours'] > 0) {
                $free = ['date' => $day['date'], 'available_hours' => $day['available_hours']];
                break;
            }
        }

        return [
            'daily_hours' => config('scheduling.daily_capacity_hours', 6),
            'weekly_hours' => config('scheduling.weekly_capacity_hours', 36),
            'hours_today_used' => WorkItemScheduler::unitsToHours($todayUnits),
            'hours_week_used' => WorkItemScheduler::unitsToHours($weekUnits),
            'hours_week_remaining' => WorkItemScheduler::unitsToHours(max(0, $weekCap - $weekUnits)),
            'free_from' => $free,
            'queue_ends' => $lastEnd,
        ];
    }
}
