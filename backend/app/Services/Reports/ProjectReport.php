<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Projects whose start-to-due window overlaps the range: estimated vs ticketed hours, schedule, and an
 * overdue / at-risk flag. Overdue = open and past its due date. At risk = open, due within two days and
 * still has open tickets. Days late for a completed project = completion date (last update) minus due date.
 */
class ProjectReport extends TabularReport
{
    public const STATUSES = ['scheduled', 'in_progress', 'completed', 'cancelled'];

    public const OPEN = ['scheduled', 'in_progress'];

    private const AT_RISK_DAYS = 2;

    public function type(): string
    {
        return 'projects';
    }

    public function statuses(): array
    {
        return self::STATUSES;
    }

    protected function sortColumns(): array
    {
        return [
            'title' => 'LOWER(title)',
            'agency' => 'LOWER(COALESCE(agency_name, ""))',
            'client' => 'LOWER(COALESCE(client_name, ""))',
            'employee' => 'LOWER(COALESCE(employee_name, ""))',
            'estimated_hours' => 'estimated_hours',
            'ticketed_hours' => 'ticketed_hours',
            'start_date' => 'start_date',
            'due_date' => 'due_date',
            'status' => 'status',
        ];
    }

    protected function defaultSort(): array
    {
        return ['due_date', 'asc'];
    }

    /** Projects in scope and in range; shared with the overview. */
    public static function filtered(ReportFilters $filters, ReportScope $scope, string $alias = 'wi'): Builder
    {
        [$sql, $bind] = $scope->projectSql($alias);

        return DB::table("work_items as {$alias}")
            ->whereRaw("DATE({$alias}.start_date) <= ? AND DATE({$alias}.due_date) >= ?{$sql}", [$filters->toDate(), $filters->fromDate(), ...$bind])
            ->when($filters->status !== null, fn (Builder $q) => $q->where("{$alias}.status", $filters->status))
            ->when($filters->employeeId !== null, fn (Builder $q) => $q->where("{$alias}.employee_id", $filters->employeeId));
    }

    protected function base(ReportFilters $filters, ReportScope $scope): Builder
    {
        return self::filtered($filters, $scope)
            ->leftJoin('agencies as ag', 'ag.id', '=', 'wi.agency_id')
            ->leftJoin('users as cl', 'cl.id', '=', 'wi.client_id')
            ->leftJoin('users as em', 'em.id', '=', 'wi.employee_id')
            ->selectRaw(
                'wi.id AS id, wi.title AS title, ag.name AS agency_name, cl.name AS client_name, em.name AS employee_name, '
                .'wi.estimated_hours AS estimated_hours, wi.start_date AS start_date, wi.due_date AS due_date, wi.status AS status, wi.updated_at AS updated_at, '
                ."COALESCE((SELECT SUM(t.estimated_hours) FROM tickets t WHERE t.work_item_id = wi.id AND t.status <> 'cancelled'), 0) AS ticketed_hours, "
                ."(SELECT COUNT(*) FROM tickets t WHERE t.work_item_id = wi.id AND t.status NOT IN ('done', 'cancelled')) AS tickets_open, "
                ."(SELECT COUNT(*) FROM tickets t WHERE t.work_item_id = wi.id AND t.status <> 'cancelled') AS tickets_total"
            );
    }

    protected function present(Collection $rows, ReportFilters $filters, ReportScope $scope): array
    {
        $today = $this->today();

        return $rows->map(function (object $row) use ($today): array {
            $due = \Carbon\CarbonImmutable::parse($row->due_date, config('app.timezone'))->startOfDay();
            $open = in_array($row->status, self::OPEN, true);
            $overdue = $open && $due < $today;
            $atRisk = $open && ! $overdue && $due <= $today->addDays(self::AT_RISK_DAYS) && (int) $row->tickets_open > 0;
            $daysLate = 0;
            if ($overdue) {
                $daysLate = (int) $due->diffInDays($today);
            } elseif ($row->status === 'completed') {
                $done = \Carbon\CarbonImmutable::parse($row->updated_at, config('app.timezone'))->startOfDay();
                $daysLate = $done > $due ? (int) $due->diffInDays($done) : 0;
            }

            return [
                'id' => (int) $row->id,
                'title' => $row->title,
                'agency' => $row->agency_name,
                'client' => $row->client_name,
                'employee' => $row->employee_name,
                'estimated_hours' => round((float) $row->estimated_hours, 2),
                'ticketed_hours' => round((float) $row->ticketed_hours, 2),
                'tickets_open' => (int) $row->tickets_open,
                'tickets_total' => (int) $row->tickets_total,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'due_date' => $due->toDateString(),
                'status' => $row->status,
                'flag' => $overdue ? 'overdue' : ($atRisk ? 'at_risk' : null),
                'days_late' => $daysLate,
            ];
        })->values()->all();
    }

    protected function totals(Builder $base, ReportFilters $filters, ReportScope $scope): ?array
    {
        $row = DB::query()->fromSub($base, 'x')
            ->selectRaw('COUNT(*) AS projects, COALESCE(SUM(estimated_hours), 0) AS estimated, COALESCE(SUM(ticketed_hours), 0) AS ticketed')
            ->first();
        $overdue = DB::query()->fromSub($base, 'x')
            ->whereIn('status', self::OPEN)
            ->whereRaw('DATE(due_date) < ?', [$this->today()->toDateString()])
            ->count();

        return [
            'projects' => (int) $row->projects,
            'estimated_hours' => round((float) $row->estimated, 2),
            'ticketed_hours' => round((float) $row->ticketed, 2),
            'overdue' => (int) $overdue,
        ];
    }

    protected function csvHeaders(): array
    {
        return ['Project', 'Agency', 'Client', 'Employee', 'Status', 'Estimated hours', 'Ticketed hours', 'Start', 'Due', 'Flag', 'Days late'];
    }

    protected function csvRow(array $row): array
    {
        return [$row['title'], $row['agency'], $row['client'], $row['employee'], $row['status'], $row['estimated_hours'], $row['ticketed_hours'], $row['start_date'], $row['due_date'], $row['flag'], $row['days_late']];
    }
}
