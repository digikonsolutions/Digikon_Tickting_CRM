<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreWorkItemRequest;
use App\Models\Agency;
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
        $agency = $this->activeAgency($viewer);

        $items = WorkItem::query()
            ->with(['agency', 'client', 'employee', 'assignment', 'allocations'])
            ->where('agency_id', $agency->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (WorkItem $item): array => $this->payload($item));

        return response()->json(['projects' => $items]);
    }

    public function store(StoreWorkItemRequest $request, WorkItemScheduler $scheduler): JsonResponse
    {
        $creator = $request->user();
        $agency = $this->activeAgency($creator);
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
        $agency = $this->activeAgency($user);

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

    public function adminShow(WorkItem $workItem): JsonResponse
    {
        $workItem->load(['agency', 'client', 'employee', 'assignment', 'allocations']);

        $tickets = $workItem->tickets()
            ->with('allocations')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Ticket $ticket): array => [
                'id' => $ticket->id,
                'work_item_id' => $ticket->work_item_id,
                'created_by' => $ticket->created_by,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'estimated_hours' => $ticket->estimated_hours,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'position' => $ticket->position,
                'start_date' => $ticket->start_date?->toDateString(),
                'due_date' => $ticket->due_date?->toDateString(),
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
            ->where('employee_id', $request->user()->id)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkItem $item): array => $this->payload($item));

        return response()->json(['projects' => $items]);
    }

    private function activeAgency(User $user): Agency
    {
        abort_unless(
            in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)
                && $user->agency_id !== null
                && $user->agency?->status === 'active',
            403,
            'An active agency account is required to manage projects.'
        );

        return $user->agency;
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

    private function validateDateRange(CarbonImmutable $start, CarbonImmutable $due): void
    {
        if ($start->diffInDays($due) > 366) {
            throw ValidationException::withMessages([
                'due_date' => 'The date range may not exceed one year.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WorkItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'agency_id' => $item->agency_id,
            'assignment_id' => $item->assignment_id,
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
            'schedule' => $item->allocations->map(fn (WorkItemAllocation $allocation): array => [
                'work_date' => $allocation->work_date->toDateString(),
                'hours' => $allocation->hours,
            ])->values(),
        ];
    }
}
