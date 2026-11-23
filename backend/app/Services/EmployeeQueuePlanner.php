<?php

namespace App\Services;

use App\Models\QueueEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plans an employee's queue: the ordered list of the open projects they hold.
 *
 * Projects are allocated earliest-first IN QUEUE ORDER, each inside its own start..due window, under the daily and
 * weekly capacity, Sundays, non-working dates and the cutoff for today. Hours already worked (days before today, and
 * today once the cutoff has passed) are frozen and never move. A project with a ticket in progress is pinned: it stays
 * ahead of the rest and today's hours on it are kept, so nothing interrupts work that has started.
 *
 * A change fails as a whole (QueueConflict, nothing saved) when it would push a project further past its due date
 * than the current order already does.
 */
class EmployeeQueuePlanner
{
    public const OPEN = ['scheduled', 'in_progress'];

    /** Days searched past a due date to find where an overflowing project would finish. */
    private const HORIZON_DAYS = 730;

    public function __construct(private WorkItemScheduler $scheduler) {}

    /**
     * Open projects of an employee in queue order (pinned, i.e. in-progress, projects first).
     *
     * @return Collection<int, WorkItem>
     */
    public function openItems(User $employee, bool $lock = false): Collection
    {
        $query = WorkItem::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', self::OPEN)
            ->orderBy('queue_position')
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $items = $query->get();
        $pinned = $this->pinnedIds($items);

        return $items->sortBy(fn (WorkItem $item): int => in_array($item->id, $pinned, true) ? 0 : 1)->values();
    }

    /**
     * IDs of open projects that have a ticket in progress.
     *
     * @return list<int>
     */
    public function pinnedIds(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        return Ticket::query()
            ->whereIn('work_item_id', $items->pluck('id'))
            ->where('status', 'in_progress')
            ->pluck('work_item_id')
            ->unique()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Dry run of a new order. Writes nothing.
     *
     * @param  list<int>  $newOrder  every open project exactly once
     * @return array{items: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, fits: bool}
     */
    public function preview(User $employee, array $newOrder, ?int $newItemId = null): array
    {
        $items = $this->openItems($employee);
        $order = $this->validatedOrder($items, $newOrder);

        $allocations = $this->persistedAllocations($items);
        $result = $this->simulate($items, $order, $allocations);
        // A project that is being added is compared with the queue as it is without it.
        $existing = $newItemId === null ? $items : $items->reject(fn (WorkItem $item): bool => $item->id === $newItemId)->values();
        $baseline = $this->simulate($existing, array_values(array_diff($items->pluck('id')->all(), [$newItemId])), $allocations);
        $current = $this->persistedRange($items);

        return $this->present($items, $result, $baseline, $current);
    }

    /**
     * Re-allocates the whole queue in order (the given order, or the stored one) and saves it. Runs in one
     * transaction under row locks; throws QueueConflict, saving nothing, when a project would slip further.
     *
     * @param  list<int>|null  $order
     * @return array{items: list<array<string, mixed>>, changed: list<int>, order: list<int>}
     */
    public function replan(User $employee, ?array $order = null, ?User $actor = null, string $event = 'replanned', array $meta = [], bool $allowSlips = false): array
    {
        return DB::transaction(function () use ($employee, $order, $actor, $event, $meta, $allowSlips): array {
            User::query()->lockForUpdate()->findOrFail($employee->id);
            $items = $this->openItems($employee, lock: true);
            $current = $items->pluck('id')->all();
            $ids = $order === null ? $current : $this->validatedOrder($items, $order);

            $allocations = $this->persistedAllocations($items);
            $result = $this->simulate($items, $ids, $allocations);
            // A project being added has no history to compare with: it must fit outright, and the baseline is the queue without it.
            $newId = in_array($event, ['added', 'rush_inserted'], true) ? ($meta['work_item_id'] ?? null) : null;
            $existing = $newId === null ? $items : $items->reject(fn (WorkItem $item): bool => $item->id === $newId)->values();
            $baseline = $this->simulate($existing, array_values(array_diff($current, [$newId])), $allocations);
            $conflicts = $this->conflicts($items, $result, $baseline);
            if ($allowSlips) {
                // The caller has accepted that other projects move past their due dates (a confirmed rush insert);
                // the new project itself must still fit, and nothing may be left without a place to be done.
                $conflicts = array_values(array_filter(
                    $conflicts,
                    fn (array $c): bool => $c['work_item_id'] === $newId || $c['slips_by_days'] >= self::HORIZON_DAYS
                ));
            }
            if ($conflicts !== []) {
                throw QueueConflict::forConflicts($conflicts);
            }

            $changed = $this->persist($employee, $items, $ids, $result, $allocations);

            // Tickets draw their dates from the project's hours, so every project that moved is planned again.
            $planner = app(TicketPlanner::class);
            foreach ($items->whereIn('id', $changed) as $item) {
                $planner->plan($item, null, [
                    'type' => 'queue_replanned',
                    'actor' => $actor,
                    'actor_name' => $actor?->name,
                ]);
            }

            $this->writeEvents($employee, $items, $current, $ids, $changed, $actor, $event, $meta);

            return ['items' => $this->present($items, $result, $baseline, [])['items'], 'changed' => $changed, 'order' => $ids];
        });
    }

    /**
     * Owner reorder. Same as replan() with a new order, and a rejected change is recorded in the audit trail
     * (outside the rolled-back transaction).
     *
     * @param  list<int>  $order
     */
    public function reorder(User $employee, array $order, User $actor): array
    {
        try {
            return $this->replan($employee, $order, $actor, 'reordered');
        } catch (QueueConflict $conflict) {
            $this->event($employee, null, $actor, 'conflict_rejected', ['order' => $order, 'conflicts' => $conflict->conflicts]);
            throw $conflict;
        }
    }

    /** Position for a project joining the end of the queue. */
    public function nextPosition(User $employee): int
    {
        return (int) WorkItem::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', self::OPEN)
            ->max('queue_position') + 1;
    }

    /**
     * Position right behind the projects already in progress, where a rush project goes.
     * Items from that position on are shifted down by one.
     */
    public function makeRoomAfterActive(User $employee): int
    {
        $items = $this->openItems($employee, lock: true);
        $pinned = count($this->pinnedIds($items));
        $position = $pinned + 1;
        WorkItem::query()
            ->whereIn('id', $items->slice($pinned)->pluck('id'))
            ->increment('queue_position');

        return $position;
    }

    /**
     * Frees the queue positions of closed projects and records the removal.
     *
     * @param  iterable<WorkItem>  $items
     */
    public function removed(iterable $items, ?User $actor, string $reason): void
    {
        foreach ($items as $item) {
            if ($item->employee_id === null) {
                continue;
            }
            $this->event(User::query()->find($item->employee_id) ?? new User(['id' => $item->employee_id]), $item, $actor, 'removed', ['reason' => $reason]);
            WorkItem::query()->whereKey($item->id)->update(['queue_position' => 0]);
        }
    }

    /** @param  array<string, mixed>  $meta */
    public function event(User $employee, ?WorkItem $item, ?User $actor, string $type, array $meta = []): void
    {
        QueueEvent::create([
            'agency_id' => $item?->agency_id ?? $employee->agency_id ?? $this->agencyOf($employee),
            'employee_id' => $employee->id,
            'work_item_id' => $item?->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    private function agencyOf(User $employee): ?int
    {
        $id = DB::table('employee_assignments')->where('employee_id', $employee->id)->where('status', 'active')->value('agency_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @param  list<int>  $order
     * @return list<int>
     */
    private function validatedOrder(Collection $items, array $order): array
    {
        $order = array_map('intval', array_values($order));
        $actual = $items->pluck('id')->sort()->values()->all();
        $given = collect($order)->sort()->values()->all();
        if (count($order) !== count($actual) || $actual !== $given) {
            throw ValidationException::withMessages([
                'ordered_work_item_ids' => 'The ordered project IDs must include every open project exactly once.',
            ]);
        }

        // Work that has started is never interrupted: projects in progress stay ahead of the rest.
        $pinned = $this->pinnedIds($items);
        $seenOther = false;
        foreach ($order as $id) {
            if (! in_array($id, $pinned, true)) {
                $seenOther = true;
            } elseif ($seenOther) {
                $title = $items->firstWhere('id', $id)?->title;
                throw ValidationException::withMessages([
                    'ordered_work_item_ids' => '"'.$title.'" is already in progress and has to stay ahead of work that has not started.',
                ]);
            }
        }

        return $order;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @return array<int, array<string, int>> work item id => [date => units]
     */
    private function persistedAllocations(Collection $items): array
    {
        $rows = [];
        foreach (WorkItemAllocation::query()->whereIn('work_item_id', $items->pluck('id'))->get() as $row) {
            $rows[$row->work_item_id][$row->work_date->toDateString()] = (int) round((float) $row->hours * 100);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @return array<int, array{start: string|null, end: string|null}>
     */
    private function persistedRange(Collection $items): array
    {
        $ranges = [];
        foreach ($this->persistedAllocations($items) as $id => $byDate) {
            $dates = array_keys($byDate);
            sort($dates);
            $ranges[$id] = ['start' => $dates[0] ?? null, 'end' => $dates[array_key_last($dates)] ?? null];
        }

        return $ranges;
    }

    /**
     * The last date whose hours are frozen for a project.
     */
    private function freezeThrough(array $context, bool $pinned): string
    {
        $today = CarbonImmutable::parse($context['today']);
        if ($pinned || ! $context['before_cutoff']) {
            return $today->toDateString();
        }

        return $today->subDay()->toDateString();
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @param  list<int>  $order
     * @param  array<int, array<string, int>>  $allocations
     * @return array<int, array{new: array<string, int>, frozen: array<string, int>, start: string|null, end: string|null, slip: int, fits: bool}>
     */
    private function simulate(Collection $items, array $order, array $allocations): array
    {
        $context = $this->scheduler->dateContext();
        $timezone = config('app.timezone');
        $dayCap = WorkItemScheduler::dailyCapacityUnits();
        $weekCap = (int) round((float) config('scheduling.weekly_capacity_hours', 36) * 100);
        $pinned = $this->pinnedIds($items);
        $byId = $items->keyBy('id');

        $booked = [];
        $weekBooked = [];
        $weekOf = fn (string $date): string => CarbonImmutable::parse($date, $timezone)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

        $frozen = [];
        foreach ($items as $item) {
            $through = $this->freezeThrough($context, in_array($item->id, $pinned, true));
            foreach ($allocations[$item->id] ?? [] as $date => $units) {
                if ($date <= $through) {
                    $frozen[$item->id][$date] = $units;
                    $booked[$date] = ($booked[$date] ?? 0) + $units;
                    $weekBooked[$weekOf($date)] = ($weekBooked[$weekOf($date)] ?? 0) + $units;
                }
            }
        }

        $out = [];
        foreach ($order as $id) {
            /** @var WorkItem $item */
            $item = $byId[$id];
            $through = $this->freezeThrough($context, in_array($id, $pinned, true));
            $frozenUnits = array_sum($frozen[$id] ?? []);
            $remaining = max(0, (int) round((float) $item->estimated_hours * 100) - $frozenUnits);
            $due = CarbonImmutable::parse($item->due_date->toDateString(), $timezone);

            $from = CarbonImmutable::parse(max($item->start_date->toDateString(), $context['today']), $timezone);
            if ($from->toDateString() <= $through) {
                $from = CarbonImmutable::parse($through, $timezone)->addDay();
            }

            $new = [];
            $last = null;
            $limit = $due->max($from)->addDays(self::HORIZON_DAYS);
            for ($date = $from; $remaining > 0 && $date->lessThanOrEqualTo($limit); $date = $date->addDay()) {
                if ($this->scheduler->skipReason($date, $context) !== null) {
                    continue;
                }
                $key = $date->toDateString();
                $week = $weekOf($key);
                $free = min($dayCap - ($booked[$key] ?? 0), $weekCap - ($weekBooked[$week] ?? 0));
                if ($free <= 0) {
                    continue;
                }
                $take = min($free, $remaining);
                $new[$key] = $take;
                $booked[$key] = ($booked[$key] ?? 0) + $take;
                $weekBooked[$week] = ($weekBooked[$week] ?? 0) + $take;
                $remaining -= $take;
                $last = $date;
            }

            $dates = array_merge(array_keys($frozen[$id] ?? []), array_keys($new));
            sort($dates);
            $end = $dates === [] ? null : $dates[array_key_last($dates)];
            $fits = $remaining === 0;
            $slip = 0;
            if (! $fits) {
                $slip = self::HORIZON_DAYS;
            } elseif ($end !== null && $end > $due->toDateString()) {
                $slip = (int) $due->diffInDays(CarbonImmutable::parse($end, $timezone));
            }

            $out[$id] = [
                'new' => $new,
                'frozen' => $frozen[$id] ?? [],
                'start' => $dates[0] ?? null,
                'end' => $end,
                'slip' => $slip,
                'fits' => $slip === 0,
            ];
        }

        return $out;
    }

    /**
     * Projects that would slip further than they do in the current order.
     *
     * @param  Collection<int, WorkItem>  $items
     * @return list<array{work_item_id: int, title: string, slips_by_days: int, due_date: string}>
     */
    private function conflicts(Collection $items, array $result, array $baseline): array
    {
        $conflicts = [];
        foreach ($result as $id => $row) {
            if ($row['slip'] > ($baseline[$id]['slip'] ?? 0)) {
                $item = $items->firstWhere('id', $id);
                $conflicts[] = [
                    'work_item_id' => $id,
                    'title' => $item->title,
                    'slips_by_days' => $row['slip'] - ($baseline[$id]['slip'] ?? 0),
                    'due_date' => $item->due_date->toDateString(),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @param  array<int, array{start: string|null, end: string|null}>  $current
     * @return array{items: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, fits: bool}
     */
    private function present(Collection $items, array $result, array $baseline, array $current): array
    {
        $rows = [];
        foreach ($result as $id => $row) {
            $item = $items->firstWhere('id', $id);
            $rows[] = [
                'work_item_id' => $id,
                'title' => $item->title,
                'due_date' => $item->due_date->toDateString(),
                'planned_start' => $row['start'],
                'planned_end' => $row['end'],
                'slips_by_days' => $row['slip'],
                'fits' => $row['fits'],
                'current_start' => $current[$id]['start'] ?? null,
                'current_end' => $current[$id]['end'] ?? null,
                'moves' => $current !== [] && (($current[$id]['start'] ?? null) !== $row['start'] || ($current[$id]['end'] ?? null) !== $row['end']),
            ];
        }
        $conflicts = $this->conflicts($items, $result, $baseline);

        return ['items' => $rows, 'conflicts' => $conflicts, 'fits' => $conflicts === []];
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @param  list<int>  $ids
     * @param  array<int, array<string, int>>  $allocations
     * @return list<int> ids whose hours changed
     */
    private function persist(User $employee, Collection $items, array $ids, array $result, array $allocations): array
    {
        $context = $this->scheduler->dateContext();
        $pinned = $this->pinnedIds($items);
        $changed = [];

        foreach ($ids as $position => $id) {
            $through = $this->freezeThrough($context, in_array($id, $pinned, true));
            $existing = array_filter($allocations[$id] ?? [], fn (int $units, string $date): bool => $date > $through, ARRAY_FILTER_USE_BOTH);
            $new = $result[$id]['new'];
            ksort($existing);
            ksort($new);

            if ($existing !== $new) {
                DB::table('work_item_allocations')->where('work_item_id', $id)->whereDate('work_date', '>', $through)->delete();
                foreach ($new as $date => $units) {
                    WorkItemAllocation::create([
                        'work_item_id' => $id,
                        'employee_id' => $employee->id,
                        'work_date' => $date,
                        'hours' => WorkItemScheduler::unitsToHours($units),
                    ]);
                }
                $changed[] = $id;
            }

            WorkItem::query()->whereKey($id)->update(['queue_position' => $position + 1]);
        }

        return $changed;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @param  list<int>  $before
     * @param  list<int>  $after
     * @param  list<int>  $changed
     */
    private function writeEvents(User $employee, Collection $items, array $before, array $after, array $changed, ?User $actor, string $event, array $meta): void
    {
        if ($event === 'reordered') {
            foreach ($after as $index => $id) {
                $from = array_search($id, $before, true);
                if ($from !== $index) {
                    $this->event($employee, $items->firstWhere('id', $id), $actor, 'reordered', [...$meta, 'from_position' => $from + 1, 'to_position' => $index + 1]);
                }
            }

            return;
        }

        if (in_array($event, ['added', 'rush_inserted'], true)) {
            $id = $meta['work_item_id'] ?? null;
            $this->event($employee, $id ? $items->firstWhere('id', $id) : null, $actor, $event, $meta);

            return;
        }

        foreach ($changed as $id) {
            $this->event($employee, $items->firstWhere('id', $id), $actor, 'replanned', $meta);
        }
    }
}
