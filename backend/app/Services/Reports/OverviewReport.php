<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard of the reports. Entity counts (agencies, users, employees) are a snapshot of now;
 * projects, tickets, overdue, completion rate and the series use the date range (projects whose window
 * overlaps it). The `status` filter is ignored here. Completion rate = completed / (open + completed).
 */
class OverviewReport extends Report
{
    public function type(): string
    {
        return 'overview';
    }

    public function run(ReportFilters $filters, ReportScope $scope): array
    {
        $scope = $scope->narrow($filters);
        $filters = $filters->withStatus(null);
        $today = $this->today();

        $projects = $this->projectCounts($filters, $scope);
        $open = $projects['scheduled'] + $projects['in_progress'];
        $denominator = $open + $projects['completed'];

        return [
            'type' => 'overview',
            'range' => $filters->range(),
            'agencies' => $this->agencies($scope),
            'users' => $this->users($scope),
            'employees' => $this->employees($scope),
            'projects' => $projects,
            'tickets' => $this->ticketCounts($filters, $scope),
            'overdue_projects' => $this->overdueCount($filters, $scope, $today),
            'completion_rate' => $denominator > 0 ? round($projects['completed'] / $denominator * 100, 1) : null,
            'series' => [
                'projects_completed_per_week' => $this->completedPerWeek($filters, $scope),
                'scheduled_hours_per_day' => $this->scheduledPerDay($filters, $scope),
            ],
            'attention' => $this->attention($filters, $scope, $today),
        ];
    }

    public function csv(ReportFilters $filters, ReportScope $scope): array
    {
        $data = $this->run($filters, $scope);
        $rows = [];
        foreach ($data['projects'] as $status => $n) {
            $rows[] = ["Projects {$status}", $n];
        }
        foreach ($data['tickets'] as $status => $n) {
            $rows[] = ["Tickets {$status}", $n];
        }
        $rows[] = ['Overdue projects', $data['overdue_projects']];
        $rows[] = ['Completion rate %', $data['completion_rate']];
        foreach (['active', 'inactive', 'held', 'available'] as $key) {
            if ($data['employees'][$key] !== null) {
                $rows[] = ["Employees {$key}", $data['employees'][$key]];
            }
        }

        return $this->capped(['Metric', 'Value'], $rows);
    }

    /** @return array{active: int, suspended: int}|null admins only, and only when not narrowed */
    private function agencies(ReportScope $scope): ?array
    {
        if (! $scope->isAdmin() || $scope->agencyId !== null || $scope->clientId !== null) {
            return null;
        }
        $counts = DB::table('agencies')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return ['active' => (int) ($counts['active'] ?? 0), 'suspended' => (int) ($counts['suspended'] ?? 0)];
    }

    /** @return array<string, int>|null */
    private function users(ReportScope $scope): ?array
    {
        if ($scope->kind === ReportScope::CLIENT || $scope->clientId !== null) {
            return null;
        }

        return DB::table('users')
            ->when($scope->agencyId !== null, fn (Builder $q) => $q->where('agency_id', $scope->agencyId))
            ->selectRaw('role, COUNT(*) AS n')
            ->groupBy('role')
            ->orderBy('role')
            ->pluck('n', 'role')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /** @return array{active: ?int, inactive: ?int, held: int, available: ?int} */
    private function employees(ReportScope $scope): array
    {
        [$sql, $bind] = $scope->assignmentSql('ea');
        $held = (int) DB::table('employee_assignments as ea')
            ->whereRaw("ea.status = 'active'".$sql, $bind)
            ->distinct()
            ->count('ea.employee_id');

        if (! $scope->isUnrestricted()) {
            return ['active' => null, 'inactive' => null, 'held' => $held, 'available' => null];
        }

        $counts = DB::table('users')->where('role', 'employee')->selectRaw('is_active, COUNT(*) AS n')->groupBy('is_active')->pluck('n', 'is_active');
        $active = (int) ($counts[1] ?? 0);
        $available = (int) DB::table('users as u')
            ->where('u.role', 'employee')->where('u.is_active', true)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_assignments as ea')->whereColumn('ea.employee_id', 'u.id')->where('ea.status', 'active'))
            ->count();

        return ['active' => $active, 'inactive' => (int) ($counts[0] ?? 0), 'held' => $held, 'available' => $available];
    }

    /** @return array{scheduled: int, in_progress: int, completed: int, cancelled: int} */
    private function projectCounts(ReportFilters $filters, ReportScope $scope): array
    {
        $counts = ProjectReport::filtered($filters, $scope)->selectRaw('wi.status AS status, COUNT(*) AS n')->groupBy('wi.status')->pluck('n', 'status');

        return array_merge(
            array_fill_keys(ProjectReport::STATUSES, 0),
            $counts->map(fn ($n): int => (int) $n)->all()
        );
    }

    /** @return array<string, int> */
    private function ticketCounts(ReportFilters $filters, ReportScope $scope): array
    {
        $counts = ProjectReport::filtered($filters, $scope)
            ->join('tickets as t', 't.work_item_id', '=', 'wi.id')
            ->selectRaw('t.status AS status, COUNT(*) AS n')
            ->groupBy('t.status')
            ->pluck('n', 'status');

        return array_merge(
            array_fill_keys(EmployeeUtilizationReport::TICKET_STATUSES, 0),
            $counts->map(fn ($n): int => (int) $n)->all()
        );
    }

    private function overdueCount(ReportFilters $filters, ReportScope $scope, CarbonImmutable $today): int
    {
        return (int) ProjectReport::filtered($filters, $scope)
            ->whereIn('wi.status', ProjectReport::OPEN)
            ->whereRaw('DATE(wi.due_date) < ?', [$today->toDateString()])
            ->count();
    }

    /** @return array{labels: list<string>, values: list<int|float>} */
    private function completedPerWeek(ReportFilters $filters, ReportScope $scope): array
    {
        [$sql, $bind] = $scope->projectSql('wi');
        $rows = DB::table('work_items as wi')
            ->where('wi.status', 'completed')
            ->whereRaw('DATE(wi.updated_at) >= ? AND DATE(wi.updated_at) <= ?'.$sql, [$filters->fromDate(), $filters->toDate(), ...$bind])
            ->selectRaw('DATE(wi.updated_at) AS day, COUNT(*) AS n')
            ->groupByRaw('DATE(wi.updated_at)')
            ->get();

        $weeks = Capacity::weekBuckets($filters->from, $filters->to);
        foreach ($rows as $row) {
            $label = Capacity::weekLabel(CarbonImmutable::parse($row->day));
            $weeks[$label] = ($weeks[$label] ?? 0) + (int) $row->n;
        }

        return ['labels' => array_keys($weeks), 'values' => array_values($weeks)];
    }

    /** @return array{labels: list<string>, values: list<int|float>} */
    private function scheduledPerDay(ReportFilters $filters, ReportScope $scope): array
    {
        [$sql, $bind] = $scope->projectSql('wi');
        $rows = DB::table('work_item_allocations as a')
            ->join('work_items as wi', 'wi.id', '=', 'a.work_item_id')
            ->where('wi.status', '<>', 'cancelled')
            ->whereRaw('DATE(a.work_date) >= ? AND DATE(a.work_date) <= ?'.$sql, [$filters->fromDate(), $filters->toDate(), ...$bind])
            ->selectRaw('DATE(a.work_date) AS day, SUM(a.hours) AS hours')
            ->groupByRaw('DATE(a.work_date)')
            ->pluck('hours', 'day');

        $labels = [];
        $values = [];
        for ($day = $filters->from; $day <= $filters->to; $day = $day->addDay()) {
            $labels[] = $day->toDateString();
            $values[] = round((float) ($rows[$day->toDateString()] ?? 0), 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** @return array<string, mixed> */
    private function attention(ReportFilters $filters, ReportScope $scope, CarbonImmutable $today): array
    {
        $overdue = ProjectReport::filtered($filters, $scope)
            ->leftJoin('agencies as ag', 'ag.id', '=', 'wi.agency_id')
            ->whereIn('wi.status', ProjectReport::OPEN)
            ->whereRaw('DATE(wi.due_date) < ?', [$today->toDateString()])
            ->orderBy('wi.due_date')->orderBy('wi.id')
            ->limit(5)
            ->get(['wi.id', 'wi.title', 'ag.name as agency_name', 'wi.due_date'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'title' => $row->title,
                'agency' => $row->agency_name,
                'due_date' => substr((string) $row->due_date, 0, 10),
                'days_late' => (int) CarbonImmutable::parse($row->due_date, config('app.timezone'))->startOfDay()->diffInDays($today),
            ])->all();

        $unassigned = ['count' => null, 'employees' => []];
        if ($scope->isUnrestricted()) {
            $report = (new AssignmentReport())->unassigned();
            $unassigned = ['count' => $report['unassigned_count'], 'employees' => array_slice($report['unassigned_employees'], 0, 5)];
        }

        return [
            'overdue_projects' => $overdue,
            'unassigned_employees' => $unassigned,
            'over_capacity' => $this->overCapacity($filters, $scope),
        ];
    }

    /**
     * Employee-weeks whose scheduled hours exceed the weekly capacity (36 h), worst first.
     *
     * @return list<array{employee_id: int, employee: string, week: string, hours: float}>
     */
    private function overCapacity(ReportFilters $filters, ReportScope $scope): array
    {
        [$sql, $bind] = $scope->projectSql('wi');
        $rows = DB::table('work_item_allocations as a')
            ->join('work_items as wi', 'wi.id', '=', 'a.work_item_id')
            ->join('users as u', 'u.id', '=', 'a.employee_id')
            ->where('wi.status', '<>', 'cancelled')
            ->whereRaw('DATE(a.work_date) >= ? AND DATE(a.work_date) <= ?'.$sql, [$filters->fromDate(), $filters->toDate(), ...$bind])
            ->selectRaw('u.id AS employee_id, u.name AS employee, DATE(a.work_date) AS day, SUM(a.hours) AS hours')
            ->groupBy('u.id', 'u.name')->groupByRaw('DATE(a.work_date)')
            ->get();

        $weekly = [];
        foreach ($rows as $row) {
            $key = $row->employee_id.'|'.Capacity::weekLabel(CarbonImmutable::parse($row->day));
            $weekly[$key] ??= ['employee_id' => (int) $row->employee_id, 'employee' => $row->employee, 'week' => explode('|', $key)[1], 'hours' => 0.0];
            $weekly[$key]['hours'] += (float) $row->hours;
        }
        $limit = (float) config('scheduling.weekly_capacity_hours', 36);
        $over = array_values(array_filter($weekly, fn (array $w): bool => $w['hours'] > $limit));
        usort($over, fn (array $a, array $b): int => $b['hours'] <=> $a['hours']);

        return array_map(fn (array $w): array => [...$w, 'hours' => round($w['hours'], 2)], array_slice($over, 0, 5));
    }
}
