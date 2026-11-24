<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AdminDashboardRequest;
use App\Http\Requests\StoreWorkItemRequest;
use App\Http\Requests\UpdateWorkItemRequest;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemAllocation;
use App\Services\AdminDashboardService;
use App\Services\EmployeeQueuePlanner;
use App\Services\QueueConflict;
use App\Services\TicketEventRecorder;
use App\Services\TicketPlanner;
use App\Services\TimeTracker;
use App\Services\WorkflowNotificationService;
use App\Services\TimelineReview;
use App\Services\WorkItemScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkItemController extends Controller
{
    public function agencyIndex(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $agency = $viewer->agency;
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'in:open,scheduled,in_progress,completed,cancelled'],
            'assignment_id' => ['sometimes', 'integer'],
            'mine' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);

        $items = WorkItem::query()
            ->with(['agency', 'client', 'employee', 'assignment', 'allocations'])
            ->withTicketProgress()
            ->where('agency_id', $agency->id)
            ->when($request->boolean('mine'), fn ($query) => $query->where('client_id', $viewer->id))
            ->when($filters['status'] ?? null, function ($query, string $status): void {
                $status === 'open'
                    ? $query->whereIn('status', ['scheduled', 'in_progress'])
                    : $query->where('status', $status);
            })
            ->when($filters['assignment_id'] ?? null, fn ($query, int $assignmentId) => $query->where('assignment_id', $assignmentId))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', '%'.$search.'%')
                        ->orWhereHas('client', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('employee', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        $counts = WorkItem::query()
            ->where('agency_id', $agency->id)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'projects' => collect($items->items())->map(fn (WorkItem $item): array => $this->payload($item))->values(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            // Counts the whole agency, regardless of filters or page.
            'summary' => [
                'total' => (int) $counts->sum(),
                'scheduled' => (int) ($counts['scheduled'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'completed' => (int) ($counts['completed'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
            ],
        ]);
    }

    /**
     * Per-day capacity for a held employee, computed by the same rules as the scheduler's plan().
     */
    public function capacity(Request $request, WorkItemScheduler $scheduler): JsonResponse
    {
        $agency = $request->user()->agency;
        $data = $request->validate([
            'assignment_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'], config('app.timezone'));
        $to = CarbonImmutable::parse($data['to'], config('app.timezone'));
        $this->validateDateRange($from, $to, 'to');

        $assignment = EmployeeAssignment::query()
            ->with('employee')
            ->whereKey($data['assignment_id'])
            ->where('agency_id', $agency->id)
            ->first();
        abort_unless($assignment !== null && $assignment->usableBy($request->user()), 404);

        if ($assignment->status !== 'active' || ! $assignment->employee?->is_active) {
            throw ValidationException::withMessages([
                'assignment_id' => 'This employee assignment is no longer active.',
            ]);
        }

        $days = $scheduler->dailyAvailability($assignment->employee, $from, $to);
        $totalUnits = array_sum(array_map(
            fn (array $day): int => (int) round((float) $day['available_hours'] * 100),
            $days
        ));

        return response()->json([
            'assignment_id' => $assignment->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_available_hours' => WorkItemScheduler::unitsToHours($totalUnits),
            'days' => $days,
        ]);
    }

    public function agencyShow(Request $request, WorkItem $workItem): JsonResponse
    {
        $agency = $request->user()->agency;
        abort_unless($workItem->agency_id === $agency->id, 404);

        return response()->json([
            'project' => $this->payload($workItem->load(['agency', 'client', 'employee', 'assignment', 'allocations']), withAccess: $workItem->canShowAccessDetailsTo($request->user())),
        ]);
    }

    public function store(StoreWorkItemRequest $request, EmployeeQueuePlanner $queue): JsonResponse
    {
        $creator = $request->user();
        $agency = $creator->agency;
        $data = $request->validated();
        $assignment = EmployeeAssignment::query()
            ->whereKey($data['assignment_id'])
            ->where('agency_id', $agency->id)
            ->where('status', 'active')
            ->first();

        if (! $assignment || ! $assignment->usableBy($creator)) {
            throw ValidationException::withMessages([
                'assignment_id' => 'Choose an active employee assignment held by your account.',
            ]);
        }
        $adminOnly = (bool) config('scheduling.admin_only_scheduling', true);
        if ($adminOnly) {
            // No dates from the agency: a placeholder window, until an admin plans the days.
            $start = CarbonImmutable::now(config('app.timezone'))->startOfDay();
            $due = $start->addDays((int) config('scheduling.provisional_window_days', 14));
        } else {
            $start = CarbonImmutable::parse($data['start_date'], config('app.timezone'));
            $due = CarbonImmutable::parse($data['due_date'], config('app.timezone'));
            $this->validateDateRange($start, $due);
        }
        $requestedUnits = (int) round((float) ($data['estimated_hours'] ?? 0) * 100);

        $item = DB::transaction(function () use ($creator, $agency, $data, $assignment, $start, $due, $requestedUnits, $queue, $adminOnly): WorkItem {
            User::query()->lockForUpdate()->findOrFail($creator->id);

            if (
                $creator->role === Role::AgencyClient
                && WorkItem::query()
                    ->where('client_id', $creator->id)
                    ->whereIn('status', ['scheduled', 'in_progress'])
                    ->count() >= (int) config('scheduling.max_open_projects_per_client', 3)
            ) {
                throw ValidationException::withMessages([
                    'projects' => 'You cannot have more than '.config('scheduling.max_open_projects_per_client', 3).' open projects.',
                ]);
            }

            $lockedAssignment = EmployeeAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if (
                $lockedAssignment->status !== 'active'
                || ! $lockedAssignment->usableBy($creator)
            ) {
                throw ValidationException::withMessages([
                    'assignment_id' => 'Choose an active employee assignment held by your account.',
                ]);
            }

            $lockedEmployee = User::query()->lockForUpdate()->findOrFail($lockedAssignment->employee_id);
            if (! $lockedEmployee->is_active || $lockedEmployee->role !== Role::Employee) {
                throw ValidationException::withMessages([
                    'assignment_id' => 'The assigned employee is no longer active.',
                ]);
            }

            $item = WorkItem::create([
                'agency_id' => $agency->id,
                'client_id' => $creator->id,
                'assignment_id' => $lockedAssignment->id,
                'employee_id' => $lockedEmployee->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'estimated_hours' => WorkItemScheduler::unitsToHours($requestedUnits),
                'start_date' => $start->toDateString(),
                'due_date' => $due->toDateString(),
                'status' => 'scheduled',
                'awaiting_schedule' => $adminOnly,
                // New projects join the end of the employee's queue; the planner then allocates the hours.
                'queue_position' => $queue->nextPosition($lockedEmployee),
                ...Arr::only($data, [...WorkItem::BRIEF_FIELDS, 'service_type', 'details']),
            ]);

            // With admin-only scheduling nothing is allocated here: the request waits for an admin to plan its days.
            if (! $adminOnly) {
                try {
                    $queue->replan($lockedEmployee, null, $creator, 'added', ['work_item_id' => $item->id]);
                } catch (QueueConflict) {
                    throw ValidationException::withMessages([
                        'assignment_id' => 'This employee assignment does not have enough capacity in the selected date range. Extend the dates.',
                    ]);
                }
            }

            return $item;
        });

        // With timeline review on, the proposal notifies admins, the employee and the creator itself.
        if (TimelineReview::required()) {
            app(TimelineReview::class)->propose($item->fresh(), $creator);
        } else {
            app(WorkflowNotificationService::class)->projectCreated($item->fresh()->load(['employee', 'agency']), $creator);
        }

        return response()->json([
            'project' => $this->payload($item->fresh()->load(['agency', 'client', 'employee', 'assignment', 'allocations'])),
        ], 201);
    }

    /**
     * Edit an existing project. Agency owners only, their own agency, any status including
     * completed and cancelled. Title, description and the brief fields are always editable;
     * hours, dates and the assigned employee reschedule the project and so are limited to
     * projects that are still open (scheduled or in progress). Nothing here changes `status`:
     * that is only ever set by ticket completion (automatic) or the cancel endpoint (explicit).
     */
    public function update(UpdateWorkItemRequest $request, WorkItem $workItem, WorkItemScheduler $scheduler, TicketPlanner $planner): JsonResponse
    {
        $user = $request->user();
        $agency = $user->agency;
        abort_unless(
            $user->role === Role::AgencyOwner && $agency !== null && $workItem->agency_id === $agency->id,
            404
        );

        $data = $request->validated();
        $schedulingFields = ['estimated_hours', 'start_date', 'due_date', 'assignment_id'];
        $wantsReschedule = (bool) array_intersect($schedulingFields, array_keys($data));

        // The service and its answers describe the work to be done, so like the schedule they belong to open projects only.
        if ((array_key_exists('service_type', $data) || array_key_exists('details', $data)) && ! in_array($workItem->status, ['scheduled', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'status' => 'The service and its details can only be changed on an open (scheduled or in progress) project.',
            ]);
        }
        // Another service makes the old answers meaningless: they go unless new ones are sent with it.
        if (array_key_exists('service_type', $data) && $data['service_type'] !== $workItem->service_type && ! array_key_exists('details', $data)) {
            $data['details'] = null;
        }

        if ($wantsReschedule && ! in_array($workItem->status, ['scheduled', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Hours, dates and the assigned employee can only be changed on an open (scheduled or in progress) project.',
            ]);
        }

        $item = DB::transaction(function () use ($user, $agency, $workItem, $data, $wantsReschedule, $schedulingFields, $scheduler, $planner): WorkItem {
            $lockedItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);

            $plainData = Arr::except($data, $schedulingFields);
            if (! empty($plainData)) {
                $lockedItem->update($plainData);
            }

            if ($wantsReschedule) {
                if (config('scheduling.admin_only_scheduling', true)) {
                    $this->applyRequestChanges($lockedItem, $user, $data);
                } else {
                    $this->rescheduleProject($lockedItem, $agency, $user, $data, $scheduler, $planner);
                }
            }

            return $lockedItem;
        });

        return response()->json([
            'project' => $this->payload($item->fresh()->load(['agency', 'client', 'employee', 'assignment', 'allocations']), withAccess: true),
        ]);
    }

    /**
     * Admin-only scheduling: the agency may change its estimate and its preferred employee, never the days. The
     * confirmed schedule is not touched; a new estimate on a planned project sends it back to the admin for review.
     * The employee can only be switched while nothing is planned yet.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyRequestChanges(WorkItem $item, User $user, array $data): void
    {
        if (array_key_exists('estimated_hours', $data) && abs((float) $data['estimated_hours'] - (float) $item->estimated_hours) >= 0.005) {
            $item->update(['estimated_hours' => WorkItemScheduler::unitsToHours((int) round((float) $data['estimated_hours'] * 100))]);
            if (! $item->isAwaitingSchedule()) {
                app(TimelineReview::class)->reopen($item->fresh(), $user, 'The agency changed the estimated hours.');
            }
        }

        if (array_key_exists('assignment_id', $data) && (int) $data['assignment_id'] !== (int) $item->assignment_id) {
            if (! $item->isAwaitingSchedule()) {
                throw ValidationException::withMessages([
                    'assignment_id' => 'The days are already planned. Ask our team if the employee needs to change.',
                ]);
            }
            $assignment = EmployeeAssignment::query()->lockForUpdate()->find($data['assignment_id']);
            if ($assignment === null || $assignment->agency_id !== $item->agency_id || ! $assignment->usableBy($user) || $assignment->status !== 'active') {
                throw ValidationException::withMessages(['assignment_id' => 'Choose an active employee assignment held by your account.']);
            }
            $employee = User::query()->lockForUpdate()->findOrFail($assignment->employee_id);
            if (! $employee->is_active || $employee->role !== Role::Employee) {
                throw ValidationException::withMessages(['assignment_id' => 'The assigned employee is no longer active.']);
            }
            $item->update([
                'assignment_id' => $assignment->id,
                'employee_id' => $employee->id,
                'queue_position' => app(EmployeeQueuePlanner::class)->nextPosition($employee),
            ]);
        }
    }

    /**
     * Applies an hours/dates/employee change to an open project: keeps allocations already in the
     * past untouched, replaces everything from today onward with a fresh plan for the (possibly
     * new) employee, and re-plans ticket schedules against the resulting pool.
     */
    private function rescheduleProject(WorkItem $lockedItem, Agency $agency, User $user, array $data, WorkItemScheduler $scheduler, TicketPlanner $planner): void
    {
        $start = CarbonImmutable::parse($data['start_date'] ?? $lockedItem->start_date->toDateString(), config('app.timezone'));
        $due = CarbonImmutable::parse($data['due_date'] ?? $lockedItem->due_date->toDateString(), config('app.timezone'));
        $this->validateDateRange($start, $due);

        $assignment = $lockedItem->assignment;
        if (array_key_exists('assignment_id', $data) && $data['assignment_id'] != $lockedItem->assignment_id) {
            $assignment = EmployeeAssignment::query()->lockForUpdate()->find($data['assignment_id']);
            if (
                $assignment === null
                || ! $assignment->usableBy($user)
                || $assignment->status !== 'active'
            ) {
                throw ValidationException::withMessages([
                    'assignment_id' => 'Choose an active employee assignment held by your account.',
                ]);
            }
        }
        $employee = User::query()->lockForUpdate()->findOrFail($assignment->employee_id);
        if (! $employee->is_active || $employee->role !== Role::Employee) {
            throw ValidationException::withMessages([
                'assignment_id' => 'The assigned employee is no longer active.',
            ]);
        }

        $totalUnits = (int) round((float) ($data['estimated_hours'] ?? $lockedItem->estimated_hours) * 100);
        $today = now(config('app.timezone'))->toDateString();
        $pastUnits = (int) round(
            (float) $lockedItem->allocations()->whereDate('work_date', '<', $today)->sum('hours') * 100
        );
        $remainingUnits = max(0, $totalUnits - $pastUnits);

        // Free this project's own future hours first so they do not count as "booked" against the employee.
        // If the new plan does not fit, the exception rolls the whole transaction back and nothing changes.
        $lockedItem->allocations()->whereDate('work_date', '>=', $today)->delete();
        $plan = $remainingUnits === 0 ? [] : $scheduler->plan($employee, CarbonImmutable::parse(max($start->toDateString(), $today), config('app.timezone')), $due, $remainingUnits);
        if ($plan === null) {
            throw ValidationException::withMessages([
                'assignment_id' => 'This employee does not have enough capacity in the selected date range. Extend the dates.',
            ]);
        }

        foreach ($plan as $allocation) {
            WorkItemAllocation::create([
                'work_item_id' => $lockedItem->id,
                'employee_id' => $employee->id,
                'work_date' => $allocation['work_date'],
                'hours' => WorkItemScheduler::unitsToHours($allocation['units']),
            ]);
        }

        $lockedItem->update([
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'estimated_hours' => WorkItemScheduler::unitsToHours($totalUnits),
            // A new estimate from the agency replaces any earlier admin plan; the schedule above was built on it.
            'planned_hours' => null,
            'planned_note' => null,
            'start_date' => $start->toDateString(),
            'due_date' => $due->toDateString(),
        ]);

        $planner->plan($lockedItem->fresh());
        // New hours, dates or employee on a confirmed project: the new schedule needs a review again.
        app(TimelineReview::class)->reopen($lockedItem->fresh(), $user, 'Hours, dates or the employee were changed by the agency.');
    }

    public function cancel(Request $request, WorkItem $workItem, TicketEventRecorder $recorder): JsonResponse
    {
        $user = $request->user();
        $agency = $user->agency;

        abort_unless(
            $workItem->agency_id === $agency->id
                && in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true),
            404
        );

        $closed = 'Completed or cancelled projects cannot be cancelled.';
        abort_unless(! in_array($workItem->status, ['completed', 'cancelled'], true), 422, $closed);

        DB::transaction(function () use (&$workItem, $user, $recorder, $closed): void {
            // Lock the row and check again: two cancels (or a cancel racing a completion) must not both run.
            $workItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            abort_unless(! in_array($workItem->status, ['completed', 'cancelled'], true), 422, $closed);
            $workItem->update(['status' => 'cancelled']);
            $today = now(config('app.timezone'))->toDateString();
            DB::table('work_item_allocations')
                ->where('work_item_id', $workItem->id)
                ->whereDate('work_date', '>=', $today)
                ->delete();

            $openTickets = $workItem->tickets()
                ->whereNotIn('status', ['done', 'cancelled'])
                ->get();
            $ticketIds = $openTickets->pluck('id');
            DB::table('ticket_allocations')
                ->whereIn('ticket_id', $ticketIds)
                ->whereDate('work_date', '>=', $today)
                ->delete();

            app(TimeTracker::class)->stopForTickets($ticketIds, 'project_cancelled');
            app(TimeTracker::class)->stopForProject($workItem->id, 'project_cancelled');
            foreach ($openTickets as $openTicket) {
                $openTicket->update(['status' => 'cancelled']);
                $recorder->record($openTicket, 'cancelled', ['cause' => 'project'], $user);
            }
        });

        app(WorkflowNotificationService::class)->projectCancelled($workItem->fresh()->load(['employee', 'client']), $user);

        return response()->json([
            'project' => $this->payload($workItem->load(['agency', 'client', 'employee', 'assignment', 'allocations'])),
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $items = WorkItem::query()
            ->with(['agency', 'client', 'employee', 'assignment', 'allocations'])
            ->withTicketProgress()
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', '%'.$search.'%')
                        ->orWhereHas('agency', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('client', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('employee', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'projects' => collect($items->items())->map(fn (WorkItem $item): array => $this->payload($item)),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Everything the admin dashboard needs in one call: status counts, ticket total,
     * scheduled hours per day for the next 7 days (aggregated in SQL) and the latest projects.
     */
    public function adminDashboard(AdminDashboardRequest $request, AdminDashboardService $dashboard): JsonResponse
    {
        $filters = $request->validated();
        $timezone = config('app.timezone');
        $week = CarbonImmutable::parse($filters['week'] ?? 'now', $timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $counts = WorkItem::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $bookedByDate = DB::table('work_item_allocations')
            ->join('work_items', 'work_items.id', '=', 'work_item_allocations.work_item_id')
            ->whereNotIn('work_items.status', ['cancelled', 'completed'])
            ->whereDate('work_item_allocations.work_date', '>=', $today->toDateString())
            ->whereDate('work_item_allocations.work_date', '<=', $today->addDays(6)->toDateString())
            ->selectRaw('DATE(work_item_allocations.work_date) AS work_date, SUM(work_item_allocations.hours) AS hours')
            ->groupByRaw('DATE(work_item_allocations.work_date)')
            ->pluck('hours', 'work_date');
        $scheduledHours = collect(range(0, 6))->map(function (int $offset) use ($today, $bookedByDate): array {
            $date = $today->addDays($offset)->toDateString();

            return ['date' => $date, 'hours' => number_format((float) ($bookedByDate[$date] ?? 0), 2, '.', '')];
        })->values();

        // Open projects past due, or due within the at-risk window (2 days, matching the frontend).
        $openProjects = fn () => WorkItem::query()->whereIn('status', ['scheduled', 'in_progress']);
        $overdueCount = $openProjects()->whereDate('due_date', '<', $today->toDateString())->count();
        $dueSoonCount = $openProjects()
            ->whereDate('due_date', '>=', $today->toDateString())
            ->whereDate('due_date', '<=', $today->addDays(2)->toDateString())
            ->count();
        $attention = $openProjects()
            ->with(['agency', 'client', 'employee', 'assignment', 'allocations'])
            ->withTicketProgress()
            ->whereDate('due_date', '<=', $today->addDays(2)->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (WorkItem $item): array => $this->payload($item))
            ->values();

        $recent = WorkItem::query()
            ->with(['agency', 'client', 'employee', 'assignment', 'allocations'])
            ->withTicketProgress()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(4)
            ->get()
            ->map(fn (WorkItem $item): array => $this->payload($item))
            ->values();

        return response()->json([
            // Employee- and agency-first payload (see AdminDashboardService). The keys below it are the
            // earlier project-centric summary, kept so existing consumers keep working.
            ...$dashboard->snapshot($week, $filters['employee_filter'] ?? 'all'),
            'today' => $today->toDateString(),
            'projects' => [
                'total' => (int) $counts->sum(),
                'scheduled' => (int) ($counts['scheduled'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'completed' => (int) ($counts['completed'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
                'overdue' => $overdueCount,
                'due_soon' => $dueSoonCount,
            ],
            'tickets' => ['total' => Ticket::query()->count()],
            'attention_projects' => $attention,
            'scheduled_hours' => $scheduledHours,
            'recent_projects' => $recent,
        ]);
    }

    public function adminShow(WorkItem $workItem): JsonResponse
    {
        $workItem->load(['agency', 'client', 'employee', 'assignment', 'allocations']);

        $tickets = $workItem->tickets()
            ->with(['agency', 'allocations', 'creator'])
            ->withCount('comments')
            ->withSum(['timeEntries as logged_minutes' => fn ($query) => $query->whereNotNull('minutes')], 'minutes')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Ticket $ticket): array => [
                'id' => $ticket->id,
                'key' => $ticket->key,
                'number' => $ticket->number,
                'work_item_id' => $ticket->work_item_id,
                'created_by' => $ticket->created_by,
                'creator' => $ticket->creator ? ['id' => $ticket->creator->id, 'name' => $ticket->creator->name] : null,
                'logged_hours' => round(((int) $ticket->logged_minutes) / 60, 2),
                'over_estimate' => (float) $ticket->estimated_hours > 0 && ((int) $ticket->logged_minutes) / 60 > (float) $ticket->estimated_hours,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'estimated_hours' => $ticket->estimated_hours,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'position' => $ticket->position,
                'start_date' => $ticket->start_date?->toDateString(),
                'due_date' => $ticket->due_date?->toDateString(),
                'comments_count' => (int) $ticket->comments_count,
                'schedule' => $ticket->allocations
                    ->sortBy('work_date')
                    ->map(fn ($allocation): array => [
                        'work_date' => $allocation->work_date->toDateString(),
                        'hours' => $allocation->hours,
                    ])->values(),
            ])->values();

        return response()->json([
            'project' => $this->payload($workItem, withAccess: true),
            'tickets' => $tickets,
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $this->ensureActiveEmployeeAgency($request->user());

        $items = WorkItem::query()
            ->with(['agency', 'client', 'employee', 'assignment', 'allocations'])
            ->withTicketProgress()
            ->where('employee_id', $request->user()->id)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkItem $item): array => $this->payload($item, withAccess: true));

        return response()->json(['projects' => $items, 'capacity' => WorkItemScheduler::capacity(), 'clock' => WorkItemScheduler::clock()]);
    }

    /**
     * The assigned employee starts their project: no ticket is needed. Only a project whose schedule is confirmed can be
     * started, and an employee has a limited number in progress at once.
     */
    public function start(Request $request, WorkItem $workItem): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveEmployeeAgency($user);

        $item = DB::transaction(function () use ($user, $workItem): WorkItem {
            $locked = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            abort_unless($user->role === Role::Employee && $locked->employee_id === $user->id, 404);
            abort_unless($locked->status === 'scheduled', 422, 'Only a scheduled project can be started.');
            if ($locked->isAwaitingSchedule() || (TimelineReview::required() && $locked->timeline_status !== 'confirmed')) {
                throw ValidationException::withMessages(['status' => 'This project can be started once its schedule is confirmed.']);
            }
            $limit = (int) config('scheduling.max_projects_in_progress', 1);
            $active = WorkItem::query()->where('employee_id', $user->id)->where('status', 'in_progress')->where('id', '<>', $locked->id)->count();
            if ($limit > 0 && $active >= $limit) {
                throw ValidationException::withMessages(['status' => 'Finish the project you are working on before starting another.']);
            }

            $locked->forceFill(['status' => 'in_progress', 'started_at' => now()])->save();
            app(\App\Services\ActivityLogger::class)->projectStatusChanged($user, $locked, 'scheduled', 'in_progress');

            return $locked;
        });

        $this->announceProjectStatus($item, 'scheduled', $user);

        return response()->json(['project' => $this->payload($item->fresh()->load(['agency', 'client', 'employee', 'assignment', 'allocations']), withAccess: true)]);
    }

    /** The assigned employee marks the project done. A project with open tickets finishes when its tickets do. */
    public function finish(Request $request, WorkItem $workItem): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveEmployeeAgency($user);

        $item = DB::transaction(function () use ($user, $workItem): WorkItem {
            $locked = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            abort_unless($user->role === Role::Employee && $locked->employee_id === $user->id, 404);
            abort_unless($locked->status === 'in_progress', 422, 'Only a project in progress can be finished.');
            if ($locked->tickets()->whereNotIn('status', ['done', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['status' => 'Finish or cancel its open tickets first. The project completes with them.']);
            }

            $locked->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            app(\App\Services\TimeTracker::class)->stopForProject($locked->id, 'project_finished');
            app(\App\Services\ActivityLogger::class)->projectStatusChanged($user, $locked, 'in_progress', 'completed');

            return $locked;
        });

        $this->announceProjectStatus($item, 'in_progress', $user);

        return response()->json(['project' => $this->payload($item->fresh()->load(['agency', 'client', 'employee', 'assignment', 'allocations']), withAccess: true)]);
    }

    private function announceProjectStatus(WorkItem $item, string $from, User $actor): void
    {
        app(WorkflowNotificationService::class)->projectStatusChanged($item->fresh()->load(['client']), $from, $item->status, $actor);
    }

    private function ensureActiveEmployeeAgency(User $user): void
    {
        if ($user->agency_id !== null) {
            abort_unless(
                $user->agency?->status === 'active',
                403,
                'An active agency is required to access employee work.'
            );
        }
    }

    private function validateDateRange(CarbonImmutable $start, CarbonImmutable $due, string $field = 'due_date'): void
    {
        if ($start->diffInDays($due) > 366) {
            throw ValidationException::withMessages([
                $field => 'The date range may not exceed one year.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The optional project brief. Access details are decrypted only for single-project reads, never in lists.
     *
     * @return array<string, mixed>
     */
    private function briefPayload(WorkItem $item, bool $withAccess): array
    {
        // Read attributes one by one: toArray() would decrypt access_details for every project in a list.
        $brief = [];
        foreach (array_diff(WorkItem::BRIEF_FIELDS, ['access_details']) as $field) {
            $brief[$field] = $item->getAttribute($field);
        }
        $brief['has_access_details'] = $item->getRawOriginal('access_details') !== null;
        if ($withAccess) {
            $brief['access_details'] = $item->access_details;
        }

        return $brief;
    }

    private function payload(WorkItem $item, bool $withAccess = false): array
    {
        // List endpoints preload progress via withTicketProgress(); single-item
        // endpoints fall back to one extra set of queries here.
        if (! $item->hasTicketProgress()) {
            $item->loadTicketProgress();
        }
        $nextTicket = $item->nextTicket;

        return [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'agency_id' => $item->agency_id,
            'assignment_id' => $item->assignment_id,
            'timeline_status' => $item->timeline_status,
            'started_at' => $item->started_at?->toISOString(),
            'completed_at' => $item->completed_at?->toISOString(),
            'schedule_state' => $item->scheduleState(),
            'planned_hours' => $item->scheduledHours(),
            'assignment_status' => $item->assignment?->status,
            'agency_name' => $item->agency && auth()->user()?->role->isAgencyRole() ? \App\Services\AgencyBrandingPresenter::displayName($item->agency) : $item->agency?->name,
            'client' => $item->client ? [
                'id' => $item->client->id,
                'name' => $item->client->name,
            ] : null,
            'employee' => $item->employee ? [
                'id' => $item->employee->id,
                'name' => $item->employee->name,
                'role' => $item->employee->role->value,
            ] : null,
            'service_type' => $item->service_type,
            'details' => $item->details,
            'brief' => $this->briefPayload($item, $withAccess),
            'estimated_hours' => $item->estimated_hours,
            'start_date' => $item->start_date->toDateString(),
            'due_date' => $item->due_date->toDateString(),
            'status' => $item->status,
            'tickets_total' => (int) $item->tickets_total,
            'tickets_done' => (int) $item->tickets_done,
            'tickets_open' => (int) $item->tickets_open,
            'next_ticket' => $nextTicket ? [
                'id' => $nextTicket->id,
                'key' => $nextTicket->key,
                'title' => $nextTicket->title,
                'status' => $nextTicket->status,
            ] : null,
            'schedule' => $item->allocations->map(fn (WorkItemAllocation $allocation): array => [
                'work_date' => $allocation->work_date->toDateString(),
                'hours' => $allocation->hours,
            ])->values(),
        ];
    }
}
