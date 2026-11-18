<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per employee: holder, agency, projects, tickets by status, scheduled hours, capacity and utilization.
 * "Scheduled" means hours booked by the scheduler (work_item_allocations), NOT hours worked; logged time
 * lives in the time summary. Capacity is working days in the range x 6 h (36 h a week, Mon-Sat), minus
 * config('scheduling.non_working_dates'). Non-admin scopes only count hours on their own projects.
 */
class EmployeeUtilizationReport extends TabularReport
{
    public const TICKET_STATUSES = ['todo', 'in_progress', 'in_review', 'blocked', 'waiting_on_client', 'changes_requested', 'done', 'cancelled'];

    public function type(): string
    {
        return 'employees';
    }

    public function statuses(): array
    {
        return ProjectReport::STATUSES;
    }

    protected function sortColumns(): array
    {
        return [
            'name' => 'LOWER(name)',
            'holder' => 'LOWER(COALESCE(holder_name, ""))',
            'agency' => 'LOWER(COALESCE(agency_name, ""))',
            'projects' => 'projects',
            'scheduled_hours' => 'scheduled_hours',
            'utilization' => 'scheduled_hours',
        ];
    }

    protected function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    protected function base(ReportFilters $filters, ReportScope $scope): Builder
    {
        [$projectSql, $projectBind] = $scope->projectSql('wi');
        [$assignSql, $assignBind] = $scope->assignmentSql('ea');
        $statusSql = $filters->status !== null ? ' AND wi.status = ?' : '';
        $statusBind = $filters->status !== null ? [$filters->status] : [];
        $overlap = ' AND DATE(wi.start_date) <= ? AND DATE(wi.due_date) >= ?';
        $overlapBind = [$filters->toDate(), $filters->fromDate()];

        $query = DB::table('users as u')
            ->where('u.role', 'employee')
            ->when($filters->employeeId !== null, fn (Builder $q) => $q->where('u.id', $filters->employeeId))
            ->selectRaw(
                'u.id AS id, u.name AS name, u.is_active AS is_active, '
                ."(SELECT h.name FROM employee_assignments ea JOIN users h ON h.id = ea.holder_id WHERE ea.employee_id = u.id AND ea.status = 'active'{$assignSql} ORDER BY ea.id LIMIT 1) AS holder_name, "
                ."(SELECT ag.name FROM employee_assignments ea JOIN agencies ag ON ag.id = ea.agency_id WHERE ea.employee_id = u.id AND ea.status = 'active'{$assignSql} ORDER BY ea.id LIMIT 1) AS agency_name, "
                ."(SELECT COUNT(*) FROM work_items wi WHERE wi.employee_id = u.id{$overlap}{$statusSql}{$projectSql}) AS projects, "
                .'COALESCE((SELECT SUM(a.hours) FROM work_item_allocations a JOIN work_items wi ON wi.id = a.work_item_id '
                ."WHERE a.employee_id = u.id AND wi.status <> 'cancelled' AND DATE(a.work_date) >= ? AND DATE(a.work_date) <= ?{$statusSql}{$projectSql}), 0) AS scheduled_hours",
                [
                    ...$assignBind,
                    ...$assignBind,
                    ...$overlapBind, ...$statusBind, ...$projectBind,
                    $filters->fromDate(), $filters->toDate(), ...$statusBind, ...$projectBind,
                ]
            );

        // Narrowed scopes only list employees that are held by, or work for, that scope.
        if (! $scope->isUnrestricted()) {
            $query->whereRaw(
                "(EXISTS (SELECT 1 FROM employee_assignments ea WHERE ea.employee_id = u.id AND ea.status = 'active'{$assignSql}) "
                ."OR EXISTS (SELECT 1 FROM work_items wi WHERE wi.employee_id = u.id{$projectSql}))",
                [...$assignBind, ...$projectBind]
            );
        }

        return $query;
    }

    protected function present(Collection $rows, ReportFilters $filters, ReportScope $scope): array
    {
        $capacity = Capacity::hours($filters->from, $filters->to);
        $tickets = $this->ticketsByEmployee($rows->pluck('id')->all(), $filters, $scope);

        return $rows->map(function (object $row) use ($capacity, $tickets): array {
            $scheduled = round((float) $row->scheduled_hours, 2);
            $utilization = Capacity::utilization($scheduled, $capacity);

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'is_active' => (bool) $row->is_active,
                'holder' => $row->holder_name,
                'agency' => $row->agency_name,
                'projects' => (int) $row->projects,
                'tickets' => $tickets[$row->id] ?? array_fill_keys(self::TICKET_STATUSES, 0),
                'scheduled_hours' => $scheduled,
                'capacity_hours' => $capacity,
                'utilization' => $utilization,
                'band' => Capacity::band($utilization),
            ];
        })->values()->all();
    }

    /**
     * One grouped query for the whole page (no per-row queries).
     *
     * @param  list<int|string>  $ids
     * @return array<int, array<string, int>>
     */
    private function ticketsByEmployee(array $ids, ReportFilters $filters, ReportScope $scope): array
    {
        if ($ids === []) {
            return [];
        }
        [$projectSql, $projectBind] = $scope->projectSql('wi');

        $query = DB::table('tickets as t')
            ->join('work_items as wi', 'wi.id', '=', 't.work_item_id')
            ->whereIn('wi.employee_id', $ids)
            ->whereRaw('DATE(wi.start_date) <= ? AND DATE(wi.due_date) >= ?'.$projectSql, [$filters->toDate(), $filters->fromDate(), ...$projectBind])
            ->when($filters->status !== null, fn (Builder $q) => $q->where('wi.status', $filters->status))
            ->selectRaw('wi.employee_id AS employee_id, t.status AS status, COUNT(*) AS n')
            ->groupBy('wi.employee_id', 't.status')
            ->get();

        $out = [];
        foreach ($query as $row) {
            $out[(int) $row->employee_id] ??= array_fill_keys(self::TICKET_STATUSES, 0);
            $out[(int) $row->employee_id][$row->status] = ($out[(int) $row->employee_id][$row->status] ?? 0) + (int) $row->n;
        }

        return $out;
    }

    protected function totals(Builder $base, ReportFilters $filters, ReportScope $scope): ?array
    {
        $row = DB::query()->fromSub($base, 'x')
            ->selectRaw('COUNT(*) AS employees, COALESCE(SUM(projects), 0) AS projects, COALESCE(SUM(scheduled_hours), 0) AS scheduled_hours')
            ->first();
        $capacity = Capacity::hours($filters->from, $filters->to);
        $scheduled = round((float) $row->scheduled_hours, 2);
        $capacityTotal = round($capacity * (int) $row->employees, 2);

        return [
            'employees' => (int) $row->employees,
            'projects' => (int) $row->projects,
            'scheduled_hours' => $scheduled,
            'capacity_hours' => $capacityTotal,
            'utilization' => Capacity::utilization($scheduled, $capacityTotal),
            'capacity_per_employee' => $capacity,
            'working_days' => Capacity::workingDays($filters->from, $filters->to),
        ];
    }

    protected function csvHeaders(): array
    {
        return ['Employee', 'Active', 'Holder', 'Agency', 'Projects', 'Tickets to do', 'Tickets in progress', 'Tickets in review', 'Tickets done', 'Scheduled hours', 'Capacity hours', 'Utilization % (scheduled, not worked)'];
    }

    protected function csvRow(array $row): array
    {
        $t = $row['tickets'];

        return [$row['name'], $row['is_active'], $row['holder'], $row['agency'], $row['projects'], $t['todo'], $t['in_progress'], $t['in_review'], $t['done'], $row['scheduled_hours'], $row['capacity_hours'], $row['utilization']];
    }
}
