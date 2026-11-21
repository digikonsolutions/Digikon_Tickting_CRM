<?php

namespace App\Services;

use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The employee- and agency-first numbers on the admin dashboard. Everything is a handful of SQL aggregates
 * (one pass over users, one over agencies, one over projects), never a loop over models, so the query count
 * stays the same however many employees or agencies exist.
 *
 * Hours use the same rules as the scheduler and the assignment usage endpoint: allocations of work items that
 * are not cancelled, over the Monday to Saturday week, against the weekly capacity (36 h by default).
 */
class AdminDashboardService
{
    public const LIST_LIMIT = 8;

    public const EMPLOYEE_FILTERS = ['all', 'available', 'held', 'overloaded'];

    /** An employee is "overloaded" above this share of the weekly budget. */
    private const OVERLOAD_RATIO = 0.9;

    private const IDLE_DAYS = 7;

    private const EXPIRY_DAYS = 30;

    private const OPEN_STATUSES = ['scheduled', 'in_progress'];

    private const SEVERITY_RANK = ['danger' => 0, 'warning' => 1, 'info' => 2];

    /** @return array<string, mixed> */
    public function snapshot(CarbonImmutable $weekStart, string $employeeFilter = 'all'): array
    {
        $weekStart = $weekStart->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $weekEnd = $weekStart->addDays(max(config('scheduling.working_days', [1, 2, 3, 4, 5, 6])) - 1);
        $budget = (float) config('scheduling.weekly_capacity_hours', 36);

        $employees = $this->employeeStats($weekStart, $weekEnd, $budget);
        $agencies = $this->agencyStats();
        $projects = $this->projectStats();
        $documents = $this->documentStats();
        $idleAgencies = $this->idleAgencyCount();

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'generated_at' => now()->toISOString(),
            'weekly_budget_hours' => $budget,
            'employees' => [
                'total' => $employees['total'],
                'active' => $employees['active'],
                'inactive' => $employees['total'] - $employees['active'],
                'available' => $employees['available'],
                'held' => $employees['held'],
                'overloaded' => $employees['overloaded'],
                'avg_utilization_percent' => $employees['active'] > 0
                    ? (int) round($employees['active_used'] / ($budget * $employees['active']) * 100)
                    : 0,
            ],
            'agencies' => $agencies,
            'employee_capacity' => $this->employeeRows($weekStart, $weekEnd, $budget, $employeeFilter),
            'agency_rows' => $this->agencyRows($weekStart, $weekEnd),
            'attention' => $this->attention($employees, $projects, $documents, $idleAgencies),
            'work_summary' => [
                'projects' => [
                    'total' => $projects['total'],
                    'scheduled' => $projects['scheduled'],
                    'in_progress' => $projects['in_progress'],
                    'completed' => $projects['completed'],
                    'cancelled' => $projects['cancelled'],
                ],
                'tickets' => ['total' => (int) DB::table('tickets')->count()],
            ],
        ];
    }

    /**
     * The hours above which an employee counts as overloaded, written into the SQL as a literal number. A bound
     * float reaches SQLite as text and would compare as larger than any number; the value comes from config,
     * never from the request.
     */
    private function overloadThreshold(float $budget): string
    {
        return number_format($budget * self::OVERLOAD_RATIO, 4, '.', '');
    }

    /** Hours booked per employee in the week, as a subquery to join onto users. */
    private function usedHoursByEmployee(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('work_item_allocations as al')
            ->join('work_items as w', 'w.id', '=', 'al.work_item_id')
            ->where('w.status', '<>', 'cancelled')
            ->whereNotNull('al.employee_id')
            ->whereDate('al.work_date', '>=', $from->toDateString())
            ->whereDate('al.work_date', '<=', $to->toDateString())
            ->selectRaw('al.employee_id AS employee_id, SUM(al.hours) AS used_hours')
            ->groupBy('al.employee_id');
    }

    /** @return array<string, int|float> */
    private function employeeStats(CarbonImmutable $from, CarbonImmutable $to, float $budget): array
    {
        $lastEnded = DB::table('employee_assignments')
            ->selectRaw('employee_id, MAX(ended_at) AS last_ended')
            ->groupBy('employee_id');
        $idleCutoff = now(config('app.timezone'))->subDays(self::IDLE_DAYS)->toDateTimeString();
        $threshold = $this->overloadThreshold($budget);

        $row = DB::table('users as u')
            ->leftJoin('employee_assignments as ea', 'ea.active_employee_id', '=', 'u.id')
            ->leftJoinSub($this->usedHoursByEmployee($from, $to), 'used', 'used.employee_id', '=', 'u.id')
            ->leftJoinSub($lastEnded, 'le', 'le.employee_id', '=', 'u.id')
            ->where('u.role', Role::Employee->value)
            ->selectRaw(
                "COUNT(*) AS total,
                SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN u.is_active = 1 AND ea.id IS NULL THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN u.is_active = 1 AND ea.id IS NOT NULL THEN 1 ELSE 0 END) AS held,
                SUM(CASE WHEN u.is_active = 1 THEN COALESCE(used.used_hours, 0) ELSE 0 END) AS active_used,
                SUM(CASE WHEN u.is_active = 1 AND COALESCE(used.used_hours, 0) > {$threshold} THEN 1 ELSE 0 END) AS overloaded,
                SUM(CASE WHEN u.is_active = 1 AND ea.id IS NULL AND COALESCE(le.last_ended, u.created_at) <= ? THEN 1 ELSE 0 END) AS idle",
                [$idleCutoff]
            )
            ->first();

        return [
            'total' => (int) $row->total,
            'active' => (int) $row->active,
            'available' => (int) $row->available,
            'held' => (int) $row->held,
            'active_used' => (float) $row->active_used,
            'overloaded' => (int) $row->overloaded,
            'idle' => (int) $row->idle,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function employeeRows(CarbonImmutable $from, CarbonImmutable $to, float $budget, string $filter): array
    {
        $rows = DB::table('users as u')
            ->leftJoinSub($this->usedHoursByEmployee($from, $to), 'used', 'used.employee_id', '=', 'u.id')
            ->leftJoin('employee_profiles as p', 'p.user_id', '=', 'u.id')
            ->leftJoin('employee_assignments as ea', 'ea.active_employee_id', '=', 'u.id')
            ->leftJoin('users as h', 'h.id', '=', 'ea.holder_id')
            ->leftJoin('agencies as ag', 'ag.id', '=', 'ea.agency_id')
            ->where('u.role', Role::Employee->value)
            ->where('u.is_active', true)
            ->when($filter === 'available', fn ($query) => $query->whereNull('ea.id'))
            ->when($filter === 'held', fn ($query) => $query->whereNotNull('ea.id'))
            ->when($filter === 'overloaded', fn ($query) => $query->whereRaw('COALESCE(used.used_hours, 0) > '.$this->overloadThreshold($budget)))
            ->orderByRaw('COALESCE(used.used_hours, 0) DESC')
            ->orderBy('u.name')
            ->orderBy('u.id')
            ->limit(self::LIST_LIMIT)
            ->get([
                'u.id', 'u.name', 'u.avatar_url as photo_url', 'p.department', 'ea.id as assignment_id',
                'h.name as holder_name', 'ag.id as agency_id', 'ag.name as agency_name',
                DB::raw('COALESCE(used.used_hours, 0) AS used_hours'),
            ]);

        return $rows->map(function (object $row) use ($budget): array {
            $used = round((float) $row->used_hours, 2);

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'photo_url' => $row->photo_url,
                'department' => $row->department,
                'status' => $row->assignment_id === null ? 'available' : 'held',
                'holder' => $row->assignment_id === null ? null : [
                    'name' => $row->holder_name,
                    'agency_id' => (int) $row->agency_id,
                    'agency_name' => $row->agency_name,
                ],
                'used_hours' => $used,
                'budget_hours' => $budget,
                'utilization_percent' => $budget > 0 ? (int) round($used / $budget * 100) : 0,
            ];
        })->values()->all();
    }

    /** @return array{total: int, active: int, suspended: int} */
    private function agencyStats(): array
    {
        $counts = DB::table('agencies')->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts['active'] ?? 0),
            'suspended' => (int) ($counts['suspended'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function agencyRows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('agencies as ag')
            ->select(['ag.id', 'ag.name', 'ag.status'])
            ->selectSub(
                fn ($query) => $query->from('users as o')->select('o.name')
                    ->whereColumn('o.agency_id', 'ag.id')->where('o.role', Role::AgencyOwner->value)
                    ->orderBy('o.id')->limit(1),
                'owner_name'
            )
            ->selectSub(
                fn ($query) => $query->from('employee_assignments as ea')->selectRaw('COUNT(*)')
                    ->whereColumn('ea.agency_id', 'ag.id')->where('ea.status', 'active'),
                'employees_held'
            )
            ->selectSub(
                fn ($query) => $query->from('work_items as w')->selectRaw('COUNT(*)')
                    ->whereColumn('w.agency_id', 'ag.id')->whereIn('w.status', self::OPEN_STATUSES),
                'open_projects'
            )
            ->selectSub(
                fn ($query) => $query->from('work_item_allocations as al')
                    ->join('work_items as w2', 'w2.id', '=', 'al.work_item_id')
                    ->selectRaw('COALESCE(SUM(al.hours), 0)')
                    ->whereColumn('w2.agency_id', 'ag.id')->where('w2.status', '<>', 'cancelled')
                    ->whereDate('al.work_date', '>=', $from->toDateString())
                    ->whereDate('al.work_date', '<=', $to->toDateString()),
                'hours_booked'
            )
            ->orderByDesc('hours_booked')
            ->orderByDesc('employees_held')
            ->orderBy('ag.name')
            ->orderBy('ag.id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'owner_name' => $row->owner_name,
            'status' => $row->status,
            'employees_held' => (int) $row->employees_held,
            'open_projects' => (int) $row->open_projects,
            'hours_booked_this_week' => round((float) $row->hours_booked, 2),
        ])->values()->all();
    }

    /** @return array<string, int> */
    private function projectStats(): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $open = "'".implode("','", self::OPEN_STATUSES)."'";
        $row = DB::table('work_items')->selectRaw(
            "COUNT(*) AS total,
            SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN status IN ({$open}) AND DATE(due_date) < ? THEN 1 ELSE 0 END) AS overdue,
            SUM(CASE WHEN status IN ({$open}) AND DATE(due_date) >= ? AND DATE(due_date) <= ? THEN 1 ELSE 0 END) AS due_soon",
            [$today->toDateString(), $today->toDateString(), $today->addDays(2)->toDateString()]
        )->first();

        return array_map('intval', (array) $row);
    }

    /** @return array{expired: int, expiring: int} */
    private function documentStats(): array
    {
        // The documents table arrives with the employee profile migration; stay safe where it has not run.
        if (! Schema::hasTable('employee_documents')) {
            return ['expired' => 0, 'expiring' => 0];
        }
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $row = DB::table('employee_documents')->selectRaw(
            'SUM(CASE WHEN DATE(expires_at) < ? THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN DATE(expires_at) >= ? AND DATE(expires_at) <= ? THEN 1 ELSE 0 END) AS expiring',
            [$today->toDateString(), $today->toDateString(), $today->addDays(self::EXPIRY_DAYS)->toDateString()]
        )->whereNotNull('expires_at')->first();

        return ['expired' => (int) $row->expired, 'expiring' => (int) $row->expiring];
    }

    /** Active agencies that hold no employee and have no open project. */
    private function idleAgencyCount(): int
    {
        return DB::table('agencies as ag')
            ->where('ag.status', 'active')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('employee_assignments as ea')
                ->whereColumn('ea.agency_id', 'ag.id')->where('ea.status', 'active'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('work_items as w')
                ->whereColumn('w.agency_id', 'ag.id')->whereIn('w.status', self::OPEN_STATUSES))
            ->count();
    }

    /**
     * One grouped item per issue, most severe first.
     *
     * @param  array<string, int|float>  $employees
     * @param  array<string, int>  $projects
     * @param  array{expired: int, expiring: int}  $documents
     * @return list<array{type: string, severity: string, count: int, label: string, link: string}>
     */
    private function attention(array $employees, array $projects, array $documents, int $idleAgencies): array
    {
        $plural = fn (int $n, string $one, string $many): string => $n.' '.($n === 1 ? $one : $many);
        $candidates = [
            ['overdue_projects', 'danger', $projects['overdue'], fn (int $n) => $plural($n, 'project is', 'projects are').' overdue', '/admin/projects'],
            ['expired_documents', 'danger', $documents['expired'], fn (int $n) => $plural($n, 'employee document has', 'employee documents have').' expired', '/admin/employees'],
            ['overloaded_employees', 'warning', $employees['overloaded'], fn (int $n) => $plural($n, 'employee is', 'employees are').' over 90% of weekly capacity', '/admin/employees'],
            ['due_soon_projects', 'warning', $projects['due_soon'], fn (int $n) => $plural($n, 'project is', 'projects are').' due within 48 hours', '/admin/projects'],
            ['expiring_documents', 'warning', $documents['expiring'], fn (int $n) => $plural($n, 'employee document expires', 'employee documents expire').' within 30 days', '/admin/employees'],
            ['idle_employees', 'info', $employees['idle'], fn (int $n) => $plural($n, 'employee has', 'employees have').' been available for 7+ days', '/admin/employees'],
            ['idle_agencies', 'info', $idleAgencies, fn (int $n) => $plural($n, 'agency holds', 'agencies hold').' no employees and '.($n === 1 ? 'has' : 'have').' no open projects', '/admin/agency-owners'],
        ];

        $items = [];
        foreach ($candidates as [$type, $severity, $count, $label, $link]) {
            if ((int) $count > 0) {
                $items[] = ['type' => $type, 'severity' => $severity, 'count' => (int) $count, 'label' => $label((int) $count), 'link' => $link];
            }
        }
        // Stable sort: severity first, the order above breaks ties.
        usort($items, fn (array $a, array $b): int => self::SEVERITY_RANK[$a['severity']] <=> self::SEVERITY_RANK[$b['severity']]);

        return $items;
    }
}
