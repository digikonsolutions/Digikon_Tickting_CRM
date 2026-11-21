<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Per-agency numbers shared by the admin agency page and the agency owner/client dashboards. Every query takes
 * the agency id (and, for a client, their own id) as a hard filter, so one agency's data cannot reach another.
 *
 * "Hours" are the hours booked on non-cancelled projects (work_item_allocations), the same rule as the
 * scheduler and the assignment usage endpoint.
 */
class AgencyInsights
{
    public const OPEN = ['scheduled', 'in_progress'];

    public const BURN_DAYS = 14;

    public function __construct(private EmployeeProfileService $profiles) {}

    private function booked(int $agencyId, ?int $clientId): Builder
    {
        return DB::table('work_item_allocations as al')
            ->join('work_items as w', 'w.id', '=', 'al.work_item_id')
            ->where('w.agency_id', $agencyId)
            ->where('w.status', '<>', 'cancelled')
            ->when($clientId !== null, fn ($query) => $query->where('w.client_id', $clientId));
    }

    private function between(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->whereDate('al.work_date', '>=', $from->toDateString())->whereDate('al.work_date', '<=', $to->toDateString());
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    /** @return array{week_start: string, week_end: string} Monday to Saturday */
    public static function weekBounds(?CarbonImmutable $today = null): array
    {
        $monday = ($today ?? self::today())->startOfWeek(CarbonImmutable::MONDAY);

        return [
            'week_start' => $monday->toDateString(),
            'week_end' => $monday->addDays(max(config('scheduling.working_days', [1, 2, 3, 4, 5, 6])) - 1)->toDateString(),
        ];
    }

    /**
     * @return array{week_hours: float, month_hours: float, period_start: string, period_end: string, burn_rate_per_day: float, last_14_days: list<array{date: string, hours: float}>}
     */
    public function usage(int $agencyId, ?int $clientId = null): array
    {
        $today = self::today();
        $week = self::weekBounds($today);
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth()->startOfDay();
        $windowStart = $today->subDays(self::BURN_DAYS - 1);

        $weekHours = (float) $this->between($this->booked($agencyId, $clientId), CarbonImmutable::parse($week['week_start']), CarbonImmutable::parse($week['week_end']))->sum('al.hours');
        $monthHours = (float) $this->between($this->booked($agencyId, $clientId), $monthStart, $monthEnd)->sum('al.hours');
        $byDay = $this->between($this->booked($agencyId, $clientId), $windowStart, $today)
            ->selectRaw('DATE(al.work_date) AS d, SUM(al.hours) AS hours')->groupByRaw('DATE(al.work_date)')->pluck('hours', 'd');

        $days = [];
        $total = 0.0;
        for ($offset = 0; $offset < self::BURN_DAYS; $offset++) {
            $date = $windowStart->addDays($offset)->toDateString();
            $hours = round((float) ($byDay[$date] ?? 0), 2);
            $total += $hours;
            $days[] = ['date' => $date, 'hours' => $hours];
        }

        return [
            'week_hours' => round($weekHours, 2),
            'month_hours' => round($monthHours, 2),
            'period_start' => $monthStart->toDateString(),
            'period_end' => $monthEnd->toDateString(),
            'burn_rate_per_day' => round($total / self::BURN_DAYS, 2),
            'last_14_days' => $days,
        ];
    }

    /** @return array{employees_held: int, open_projects: int, client_accounts: int, projects_total: int} */
    public function counts(int $agencyId, ?int $clientId = null): array
    {
        $open = "'".implode("','", self::OPEN)."'";
        $projects = DB::table('work_items')->where('agency_id', $agencyId)
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status IN ({$open}) THEN 1 ELSE 0 END) AS open")->first();

        return [
            'employees_held' => (int) DB::table('employee_assignments')->where('agency_id', $agencyId)->where('status', 'active')
                ->when($clientId !== null, fn ($query) => $query->where('holder_id', $clientId))->count(),
            'open_projects' => (int) $projects->open,
            'client_accounts' => (int) User::query()->where('agency_id', $agencyId)->where('role', Role::AgencyClient->value)->where('is_active', true)->count(),
            'projects_total' => (int) $projects->total,
        ];
    }

    /**
     * Employees the agency holds (all of them, or only those one client holds), with the public profile summary
     * and the hours booked on THIS agency's projects this week. Hours from other agencies never appear.
     *
     * @return list<array<string, mixed>>
     */
    public function heldEmployees(int $agencyId, ?int $holderId = null, int $limit = 24): array
    {
        $week = self::weekBounds();
        $budget = (float) config('scheduling.weekly_capacity_hours', 36);

        $rows = DB::table('employee_assignments as ea')
            ->join('users as u', 'u.id', '=', 'ea.employee_id')
            ->where('ea.agency_id', $agencyId)
            ->where('ea.status', 'active')
            ->when($holderId !== null, fn ($query) => $query->where('ea.holder_id', $holderId))
            ->select(['ea.id as assignment_id', 'ea.started_at', 'u.id', 'u.name'])
            ->selectSub(
                fn ($query) => $query->from('work_item_allocations as al')
                    ->join('work_items as w', 'w.id', '=', 'al.work_item_id')
                    ->selectRaw('COALESCE(SUM(al.hours), 0)')
                    ->whereColumn('w.assignment_id', 'ea.id')
                    ->where('w.agency_id', $agencyId)
                    ->where('w.status', '<>', 'cancelled')
                    ->whereDate('al.work_date', '>=', $week['week_start'])
                    ->whereDate('al.work_date', '<=', $week['week_end']),
                'used_hours'
            )
            ->orderBy('u.name')->orderBy('u.id')->limit($limit)->get();

        $users = User::query()->with(['employeeProfile', 'skills'])->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');

        return $rows->map(function (object $row) use ($users, $budget): array {
            $used = round((float) $row->used_hours, 2);

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'assignment_id' => (int) $row->assignment_id,
                'since' => $row->started_at ? CarbonImmutable::parse($row->started_at)->toDateString() : null,
                'used_hours' => $used,
                'budget_hours' => $budget,
                'utilization_percent' => $budget > 0 ? (int) round($used / $budget * 100) : 0,
                ...$this->profiles->publicSummary($users[$row->id]),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function recentProjects(int $agencyId, ?int $clientId = null, int $limit = 5): array
    {
        return DB::table('work_items as w')
            ->leftJoin('users as e', 'e.id', '=', 'w.employee_id')
            ->where('w.agency_id', $agencyId)
            ->when($clientId !== null, fn ($query) => $query->where('w.client_id', $clientId))
            ->orderByDesc('w.created_at')->orderByDesc('w.id')->limit($limit)
            ->get(['w.id', 'w.title', 'w.status', 'w.due_date', 'w.estimated_hours', 'e.name as employee_name'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'title' => $row->title,
                'status' => $row->status,
                'due_date' => $row->due_date ? CarbonImmutable::parse($row->due_date)->toDateString() : null,
                'estimated_hours' => (float) $row->estimated_hours,
                'employee_name' => $row->employee_name,
            ])->values()->all();
    }

    /**
     * Items for the "Needs attention" card. Project items always apply; the contract items (hours at 80% and
     * 100%, contract ending in 30 or 7 days) appear once an agency has a contract.
     *
     * @param  array<string, mixed>|null  $contract
     * @return list<array{type: string, severity: string, count: int, label: string, link: string}>
     */
    public function attention(int $agencyId, ?int $clientId, string $projectsLink, ?array $contract = null, float $hoursUsed = 0.0): array
    {
        $today = self::today();
        $open = "'".implode("','", self::OPEN)."'";
        $row = DB::table('work_items')->where('agency_id', $agencyId)
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->selectRaw(
                "SUM(CASE WHEN status IN ({$open}) AND DATE(due_date) < ? THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN status IN ({$open}) AND DATE(due_date) >= ? AND DATE(due_date) <= ? THEN 1 ELSE 0 END) AS due_soon",
                [$today->toDateString(), $today->toDateString(), $today->addDays(2)->toDateString()]
            )->first();

        $items = self::contractAttention($contract, $hoursUsed);
        $plural = fn (int $n, string $one, string $many): string => $n.' '.($n === 1 ? $one : $many);
        if ((int) $row->overdue > 0) {
            $items[] = ['type' => 'overdue_projects', 'severity' => 'danger', 'count' => (int) $row->overdue, 'label' => $plural((int) $row->overdue, 'project is', 'projects are').' overdue', 'link' => $projectsLink];
        }
        if ((int) $row->due_soon > 0) {
            $items[] = ['type' => 'due_soon_projects', 'severity' => 'warning', 'count' => (int) $row->due_soon, 'label' => $plural((int) $row->due_soon, 'project is', 'projects are').' due within 48 hours', 'link' => $projectsLink];
        }
        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn (array $a, array $b): int => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return $items;
    }

    /**
     * Hours at 80% and 100% of the included hours, and a contract ending within 30 or 7 days.
     *
     * @param  array<string, mixed>|null  $contract
     * @return list<array{type: string, severity: string, count: int, label: string, link: string}>
     */
    public static function contractAttention(?array $contract, float $hoursUsed, ?CarbonImmutable $today = null): array
    {
        if ($contract === null) {
            return [];
        }
        $items = [];
        $included = AgencyContracts::limits($contract)['hours'];
        if ($included !== null && $included > 0) {
            $share = $hoursUsed / $included;
            if ($share >= 1) {
                $items[] = ['type' => 'hours_used_up', 'severity' => 'danger', 'count' => 1, 'label' => 'All included hours are used', 'link' => '/agency/dashboard'];
            } elseif ($share >= 0.8) {
                $items[] = ['type' => 'hours_nearly_used', 'severity' => 'warning', 'count' => 1, 'label' => 'Over 80% of the included hours are used', 'link' => '/agency/dashboard'];
            }
        }
        $days = AgencyContracts::daysRemaining($contract, $today);
        if ($days !== null && $days >= 0 && $days <= 7) {
            $items[] = ['type' => 'contract_ends_soon', 'severity' => 'danger', 'count' => 1, 'label' => 'Your contract ends in '.$days.' day'.($days === 1 ? '' : 's'), 'link' => '/agency/dashboard'];
        } elseif ($days !== null && $days > 7 && $days <= 30) {
            $items[] = ['type' => 'contract_ends_soon', 'severity' => 'warning', 'count' => 1, 'label' => 'Your contract ends in '.$days.' days', 'link' => '/agency/dashboard'];
        }

        return $items;
    }
}
