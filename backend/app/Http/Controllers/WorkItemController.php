<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreWorkItemRequest;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemAllocation;
use App\Services\WorkItemScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'search' => ['sometimes', 'string', 'max:120'],
        ]);

        $items = WorkItem::query()
            ->with(['agency', 'client', 'employee', 'assignment', 'allocations'])
            ->withTicketProgress()
            ->where('agency_id', $agency->id)
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
            ->where('holder_id', $request->user()->id)
            ->first();
        abort_unless($assignment !== null, 404);

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
            'project' => $this->payload($workItem->load(['agency', 'client', 'employee', 'assignment', 'allocations'])),
        ]);
    }

    public function store(StoreWorkItemRequest $request, WorkItemScheduler $scheduler): JsonResponse
    {
        $creator = $request->user();
        $agency = $creator->agency;
        $data = $request->validated();
        $assignment = EmployeeAssignment::query()
            ->whereKey($data['assignment_id'])
            ->where('holder_id', $creator->id)
            ->where('agency_id', $agency->id)
            ->where('status', 'active')
            ->first();

        if (! $assignment) {
            throw ValidationException::withMessages([
                'assignment_id' => 'Choose an active employee assignment held by your account.',
            ]);
        }
        $start = CarbonImmutable::parse($data['start_date'], config('app.timezone'));
        $due = CarbonImmutable::parse($data['due_date'], config('app.timezone'));
        $this->validateDateRange($start, $due);
        $requestedUnits = (int) round((float) $data['estimated_hours'] * 100);

        $item = DB::transaction(function () use ($creator, $agency, $data, $assignment, $start, $due, $requestedUnits, $scheduler): WorkItem {
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
                || $lockedAssignment->holder_id !== $creator->id
                || $lockedAssignment->agency_id !== $agency->id
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

            $plan = $scheduler->plan($lockedEmployee, $start, $due, $requestedUnits);

            if ($plan === null) {
                throw ValidationException::withMessages([
                    'assignment_id' => 'This employee assignment does not have enough capacity in the selected date range. Extend the dates.',
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
            ]);

            foreach ($plan as $allocation) {
                WorkItemAllocation::create([
                    'work_item_id' => $item->id,
                    'employee_id' => $lockedEmployee->id,
                    'work_date' => $allocation['work_date'],
                    'hours' => WorkItemScheduler::unitsToHours($allocation['units']),
                ]);
            }

            return $item;
        });

        return response()->json([
            'project' => $this->payload($item->load(['agency', 'client', 'employee', 'assignment', 'allocations'])),
        ], 201);
    }

    public function cancel(Request $request, WorkItem $workItem): JsonResponse
    {
        $user = $request->user();
        $agency = $user->agency;

        abort_unless(
            $workItem->agency_id === $agency->id
                && in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true),
            404
        );

        abort_unless(
            ! in_array($workItem->status, ['completed', 'cancelled'], true),
            422,
            'Completed or cancelled projects cannot be cancelled.'
        );

        DB::transaction(function () use ($workItem): void {
            $workItem->update(['status' => 'cancelled']);
            $today = now(config('app.timezone'))->toDateString();
            DB::table('work_item_allocations')
                ->where('work_item_id', $workItem->id)
                ->whereDate('work_date', '>=', $today)
                ->delete();

            $openTickets = $workItem->tickets()->whereNotIn('status', ['done', 'cancelled']);
            $ticketIds = (clone $openTickets)->pluck('id');
            DB::table('ticket_allocations')
                ->whereIn('ticket_id', $ticketIds)
                ->whereDate('work_date', '>=', $today)
                ->delete();
            $openTickets->update(['status' => 'cancelled']);
        });

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
    public function adminDashboard(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:scheduled,in_progress,completed,cancelled'],
        ]);
        $timezone = config('app.timezone');
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
            'today' => $today->toDateString(),
            'projects' => [
                'total' => (int) $counts->sum(),
                'scheduled' => (int) ($counts['scheduled'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'completed' => (int) ($counts['completed'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
            ],
            'tickets' => ['total' => Ticket::query()->count()],
            'scheduled_hours' => $scheduledHours,
            'recent_projects' => $recent,
        ]);
    }
    public function adminShow(WorkItem $workItem): JsonResponse
    {
        $workItem->load(['agency', 'client', 'employee', 'assignment', 'allocations']);

        $tickets = $workItem->tickets()
            ->with(['allocations', 'creator'])
            ->withCount('comments')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Ticket $ticket): array => [
                'id' => $ticket->id,
                'work_item_id' => $ticket->work_item_id,
                'created_by' => $ticket->created_by,
                'creator' => $ticket->creator ? ['id' => $ticket->creator->id, 'name' => $ticket->creator->name] : null,
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
            'project' => $this->payload($workItem),
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
            ->map(fn (WorkItem $item): array => $this->payload($item));

        return response()->json(['projects' => $items, 'capacity' => WorkItemScheduler::capacity()]);
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
    private function payload(WorkItem $item): array
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
            'assignment_status' => $item->assignment?->status,
            'agency_name' => $item->agency?->name,
            'client' => $item->client ? [
                'id' => $item->client->id,
                'name' => $item->client->name,
            ] : null,
            'employee' => $item->employee ? [
                'id' => $item->employee->id,
                'name' => $item->employee->name,
                'role' => $item->employee->role->value,
            ] : null,
            'estimated_hours' => $item->estimated_hours,
            'start_date' => $item->start_date->toDateString(),
            'due_date' => $item->due_date->toDateString(),
            'status' => $item->status,
            'tickets_total' => (int) $item->tickets_total,
            'tickets_done' => (int) $item->tickets_done,
            'tickets_open' => (int) $item->tickets_open,
            'next_ticket' => $nextTicket ? [
                'id' => $nextTicket->id,
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
