<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreWorkItemRequest;
use App\Models\Agency;
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
    public function agencyEmployees(Request $request, WorkItemScheduler $scheduler): JsonResponse
    {
        $user = $request->user();
        $agency = $this->activeAgency($user);
        $filters = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'hours' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1890'],
        ]);
        $start = CarbonImmutable::parse($filters['start_date']);
        $due = CarbonImmutable::parse($filters['due_date']);
        $this->validateDateRange($start, $due);
        $requestedUnits = (int) round((float) $filters['hours'] * 100);

        return response()->json([
            'employees' => $scheduler->assignableEmployees()
                ->map(function (User $employee) use ($scheduler, $start, $due, $requestedUnits): array {
                    $plan = $scheduler->plan($employee, $start, $due, $requestedUnits);

                    return [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'role' => $employee->role->value,
                        'available' => $plan !== null,
                        'available_hours' => WorkItemScheduler::unitsToHours(
                            $scheduler->availableUnits($employee, $start, $due)
                        ),
                        'schedule' => $plan === null ? [] : array_map(
                            fn (array $allocation): array => [
                                'work_date' => $allocation['work_date'],
                                'hours' => WorkItemScheduler::unitsToHours($allocation['units']),
                            ],
                            $plan
                        ),
                    ];
                })
                ->values(),
        ]);
    }

    public function agencyIndex(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $agency = $this->activeAgency($viewer);

        $items = WorkItem::query()
            ->with(['agency', 'client', 'employee', 'allocations'])
            ->where('agency_id', $agency->id)
            ->when(
                $viewer->role === Role::AgencyClient,
                fn ($query) => $query->where('client_id', $viewer->id)
            )
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
        $employee = $this->findAssignableEmployee($scheduler, (int) $data['employee_id']);
        $start = CarbonImmutable::parse($data['start_date']);
        $due = CarbonImmutable::parse($data['due_date']);
        $this->validateDateRange($start, $due);
        $requestedUnits = (int) round((float) $data['estimated_hours'] * 100);

        $item = DB::transaction(function () use ($creator, $agency, $data, $employee, $start, $due, $requestedUnits, $scheduler): WorkItem {
            $lockedEmployee = User::query()->lockForUpdate()->findOrFail($employee->id);
            $this->assertAssignableEmployee($lockedEmployee);
            $plan = $scheduler->plan($lockedEmployee, $start, $due, $requestedUnits);

            if ($plan === null) {
                throw ValidationException::withMessages([
                    'employee_id' => 'This employee does not have enough capacity in the selected date range. Choose another employee or extend the dates.',
                ]);
            }

            $item = WorkItem::create([
                'agency_id' => $agency->id,
                'client_id' => $creator->id,
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
            'project' => $this->payload($item->load(['agency', 'client', 'employee', 'allocations'])),
        ], 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $items = WorkItem::query()
            ->with(['agency', 'client', 'employee', 'allocations'])
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

    public function mine(Request $request): JsonResponse
    {
        $items = WorkItem::query()
            ->with(['agency', 'client', 'employee', 'allocations'])
            ->where('employee_id', $request->user()->id)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkItem $item): array => $this->payload($item));

        return response()->json(['projects' => $items]);
    }

    public function updateStatus(Request $request, WorkItem $workItem): JsonResponse
    {
        abort_unless($workItem->employee_id === $request->user()->id, 404);
        abort_unless(in_array($workItem->status, ['scheduled', 'in_progress'], true), 422, 'This project is already closed.');

        $data = $request->validate([
            'status' => ['required', 'in:in_progress,completed'],
        ]);
        $workItem->update(['status' => $data['status']]);

        return response()->json([
            'project' => $this->payload($workItem->load(['agency', 'client', 'employee', 'allocations'])),
        ]);
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

    private function findAssignableEmployee(WorkItemScheduler $scheduler, int $employeeId): User
    {
        $employee = $scheduler->assignableEmployees()->firstWhere('id', $employeeId);
        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'Choose an active employee assigned to this agency or a shared employee.',
            ]);
        }

        return $employee;
    }

    private function assertAssignableEmployee(User $employee): void
    {
        $isSharedEmployee = $employee->role === Role::Employee && $employee->agency_id === null;

        if (! $employee->is_active || ! $isSharedEmployee) {
            throw ValidationException::withMessages([
                'employee_id' => 'Choose an active employee assigned to this agency or a shared employee.',
            ]);
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
