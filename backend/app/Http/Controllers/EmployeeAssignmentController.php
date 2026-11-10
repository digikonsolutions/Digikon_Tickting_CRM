<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;

class EmployeeAssignmentController extends Controller
{
    public function available(Request $request): JsonResponse
    {
        $agency = $this->activeAgency($request->user());
        $employees = User::query()
            ->where('is_active', true)
            ->where('role', Role::Employee->value)
            ->where(function ($query) use ($agency): void {
                $query->where('agency_id', $agency->id)->orWhereNull('agency_id');
            })
            ->whereDoesntHave('employeeAssignments', fn ($query) => $query->where('status', 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'role', 'agency_id']);

        return response()->json(['employees' => $employees]);
    }

    public function store(Request $request): JsonResponse
    {
        $holder = $request->user();
        $agency = $this->activeAgency($holder);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            $assignment = DB::transaction(function () use ($holder, $agency, $data): EmployeeAssignment {
                $employee = User::query()->lockForUpdate()->findOrFail($data['employee_id']);

                if (
                    ! $employee->is_active
                    || $employee->role !== Role::Employee
                    || ! in_array($employee->agency_id, [null, $agency->id], true)
                ) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'Choose an active employee assigned to your agency or a shared employee.',
                    ]);
                }

                if (EmployeeAssignment::query()->where('active_employee_id', $employee->id)->exists()) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'This employee is already held by another agency client or owner.',
                    ]);
                }

                return EmployeeAssignment::create([
                    'holder_id' => $holder->id,
                    'agency_id' => $agency->id,
                    'employee_id' => $employee->id,
                    'status' => 'active',
                    'active_employee_id' => $employee->id,
                    'started_at' => now(config('app.timezone')),
                ]);
            });
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'active_employee_id')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'employee_id' => 'This employee is already held by another agency client or owner.',
            ]);
        }

        return response()->json([
            'assignment' => $this->payload($assignment->load(['employee', 'agency', 'holder'])),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $agency = $this->activeAgency($viewer);
        $filters = $request->validate([
            'scope' => ['sometimes', 'in:agency'],
            'week' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $scopeAgency = ($filters['scope'] ?? null) === 'agency';
        abort_if($scopeAgency && $viewer->role !== Role::AgencyOwner, 403);

        $assignments = EmployeeAssignment::query()
            ->with(['employee', 'agency', 'holder'])
            ->where('status', 'active')
            ->where('agency_id', $agency->id)
            ->when(! $scopeAgency, fn ($query) => $query->where('holder_id', $viewer->id))
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (EmployeeAssignment $assignment): array => $this->payload(
                $assignment,
                $filters['week'] ?? CarbonImmutable::now(config('app.timezone'))->toDateString()
            ));

        return response()->json(['assignments' => $assignments]);
    }

    public function usage(Request $request, EmployeeAssignment $assignment): JsonResponse
    {
        $this->authorizeAssignment($request->user(), $assignment);
        $filters = $request->validate(['week' => ['sometimes', 'date_format:Y-m-d']]);
        $week = CarbonImmutable::parse(
            $filters['week'] ?? CarbonImmutable::now(config('app.timezone'))->toDateString(),
            config('app.timezone')
        )->startOfWeek(CarbonImmutable::MONDAY);

        return response()->json([
            'assignment_id' => $assignment->id,
            ...$this->weeklyUsage($assignment, $week),
        ]);
    }

    public function destroy(Request $request, EmployeeAssignment $assignment): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $assignment->agency_id === $user->agency_id
                && ($assignment->holder_id === $user->id || $user->role === Role::AgencyOwner),
            404
        );
        $this->endAssignment($assignment);

        return response()->json(['message' => 'The employee assignment has ended.']);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'in:active,ended'],
        ]);
        $assignments = EmployeeAssignment::query()
            ->with(['employee', 'agency', 'holder'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'assignments' => collect($assignments->items())
                ->map(fn (EmployeeAssignment $assignment): array => $this->payload($assignment)),
            'pagination' => [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
            ],
        ]);
    }

    public function adminDestroy(EmployeeAssignment $assignment): JsonResponse
    {
        $this->endAssignment($assignment);

        return response()->json(['message' => 'The employee assignment has ended.']);
    }

    private function endAssignment(EmployeeAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment): void {
            $locked = EmployeeAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($locked->status === 'ended') {
                return;
            }

            $workItems = $locked->workItems()->whereIn('status', ['scheduled', 'in_progress']);
            $workItemIds = $workItems->pluck('id');
            $workItems->update(['status' => 'cancelled', 'updated_at' => now()]);
            $today = now(config('app.timezone'))->toDateString();
            DB::table('work_item_allocations')
                ->whereIn('work_item_id', $workItemIds)
                ->whereDate('work_date', '>=', $today)
                ->delete();

            $ticketIds = DB::table('tickets')->whereIn('work_item_id', $workItemIds)->pluck('id');
            DB::table('ticket_allocations')
                ->whereIn('ticket_id', $ticketIds)
                ->whereDate('work_date', '>=', $today)
                ->delete();
            DB::table('tickets')
                ->whereIn('id', $ticketIds)
                ->whereNotIn('status', ['done', 'cancelled'])
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            $locked->update([
                'status' => 'ended',
                'active_employee_id' => null,
                'ended_at' => now(config('app.timezone')),
            ]);
        });
    }

    private function authorizeAssignment(User $viewer, EmployeeAssignment $assignment): void
    {
        abort_unless(
            $assignment->agency_id === $viewer->agency_id
                && ($assignment->holder_id === $viewer->id || $viewer->role === Role::AgencyOwner),
            404
        );
    }

    private function activeAgency(User $user): Agency
    {
        abort_unless(
            in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)
                && $user->agency_id !== null
                && $user->agency?->status === 'active',
            403,
            'An active agency account is required to manage employee assignments.'
        );

        return $user->agency;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EmployeeAssignment $assignment, ?string $weekDate = null): array
    {
        $assignment->loadMissing(['employee', 'agency', 'holder']);
        $payload = [
            'id' => $assignment->id,
            'holder' => ['id' => $assignment->holder->id, 'name' => $assignment->holder->name],
            'employee' => [
                'id' => $assignment->employee->id,
                'name' => $assignment->employee->name,
                'email' => $assignment->employee->email,
            ],
            'agency_id' => $assignment->agency_id,
            'agency_name' => $assignment->agency->name,
            'status' => $assignment->status,
            'started_at' => $assignment->started_at?->toISOString(),
            'ended_at' => $assignment->ended_at?->toISOString(),
        ];

        if ($weekDate !== null) {
            $week = CarbonImmutable::parse($weekDate, config('app.timezone'))
                ->startOfWeek(CarbonImmutable::MONDAY);
            $payload['weekly_usage'] = $this->weeklyUsage($assignment, $week);
        }

        return $payload;
    }

    /**
     * @return array{week_start: string, week_end: string, used: string, remaining: string, days: list<array{date: string, used: string}>}
     */
    private function weeklyUsage(EmployeeAssignment $assignment, CarbonImmutable $week): array
    {
        $weekEnd = $week->addDays(5);
        $usedByDay = DB::table('work_item_allocations')
            ->join('work_items', 'work_items.id', '=', 'work_item_allocations.work_item_id')
            ->where('work_items.assignment_id', $assignment->id)
            ->where('work_items.status', '<>', 'cancelled')
            ->whereDate('work_item_allocations.work_date', '>=', $week->toDateString())
            ->whereDate('work_item_allocations.work_date', '<=', $weekEnd->toDateString())
            ->selectRaw('DATE(work_item_allocations.work_date) AS work_date, SUM(work_item_allocations.hours) AS used')
            ->groupByRaw('DATE(work_item_allocations.work_date)')
            ->pluck('used', 'work_date');
        $days = [];
        $used = 0.0;

        for ($offset = 0; $offset < 6; $offset++) {
            $date = $week->addDays($offset)->toDateString();
            $hours = (float) ($usedByDay[$date] ?? 0);
            $used += $hours;
            $days[] = ['date' => $date, 'used' => number_format($hours, 2, '.', '')];
        }

        return [
            'week_start' => $week->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'used' => number_format($used, 2, '.', ''),
            'remaining' => number_format(36 - $used, 2, '.', ''),
            'days' => $days,
        ];
    }
}
