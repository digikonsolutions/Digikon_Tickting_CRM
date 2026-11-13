<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\WorkItemScheduler;
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
        $agency = $request->user()->agency;
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
        $agency = $holder->agency;
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
        $agency = $viewer->agency;
        // Agency users only ever see the assignments they hold; there is no agency-wide view.
        $filters = $request->validate([
            'scope' => ['prohibited'],
            'week' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $assignments = EmployeeAssignment::query()
            ->with(['employee', 'agency', 'holder'])
            ->where('status', 'active')
            ->where('agency_id', $agency->id)
            ->where('holder_id', $viewer->id)
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
            'capacity' => WorkItemScheduler::capacity(),
            ...$this->weeklyUsage($assignment, $week),
        ]);
    }

    public function destroy(Request $request, EmployeeAssignment $assignment, AssignmentService $assignments): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAssignment($user, $assignment);
        $assignments->end($assignment);

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

    public function adminDestroy(EmployeeAssignment $assignment, AssignmentService $assignments): JsonResponse
    {
        $assignments->end($assignment);

        return response()->json(['message' => 'The employee assignment has ended.']);
    }

    private function authorizeAssignment(User $viewer, EmployeeAssignment $assignment): void
    {
        // Only the holder may read or end an assignment (owners and clients alike).
        abort_unless(
            $assignment->agency_id === $viewer->agency_id && $assignment->holder_id === $viewer->id,
            404
        );
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
            'capacity' => WorkItemScheduler::capacity(),
        ];

        if ($weekDate !== null) {
            $week = CarbonImmutable::parse($weekDate, config('app.timezone'))
                ->startOfWeek(CarbonImmutable::MONDAY);
            $payload['weekly_usage'] = $this->weeklyUsage($assignment, $week);
        }

        return $payload;
    }

    /**
     * `used` is every non-cancelled hour booked on this assignment that week (hours consumed).
     * `remaining` is weekly capacity minus `used`. `bookable_remaining` is what the scheduler would
     * still accept that week for the employee: it ignores completed/cancelled bookings, Sundays,
     * non-working dates, today after the cutoff and days already past, and counts every agency's bookings.
     *
     * @return array<string, mixed>
     */
    private function weeklyUsage(EmployeeAssignment $assignment, CarbonImmutable $week): array
    {
        $workingDays = config('scheduling.working_days', [1, 2, 3, 4, 5, 6]);
        $weekEnd = $week->addDays(max($workingDays) - 1);
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

        foreach ($workingDays as $isoDay) {
            $date = $week->addDays($isoDay - 1)->toDateString();
            $hours = (float) ($usedByDay[$date] ?? 0);
            $used += $hours;
            $days[] = ['date' => $date, 'used' => number_format($hours, 2, '.', '')];
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $bookableUnits = 0;
        if ($assignment->employee) {
            foreach (app(WorkItemScheduler::class)->dailyAvailability($assignment->employee, $week, $weekEnd) as $day) {
                if ($day['date'] >= $today) {
                    $bookableUnits += (int) round((float) $day['available_hours'] * 100);
                }
            }
        }

        return [
            'week_start' => $week->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'used' => number_format($used, 2, '.', ''),
            'remaining' => number_format(config('scheduling.weekly_capacity_hours', 36) - $used, 2, '.', ''),
            'bookable_remaining' => WorkItemScheduler::unitsToHours($bookableUnits),
            'days' => $days,
        ];
    }
}