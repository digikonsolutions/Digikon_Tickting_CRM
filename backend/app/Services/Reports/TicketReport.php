<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tickets created inside the range (the table, and the status x priority matrix), plus weekly throughput
 * (tickets that reached "done", by ISO week of their last update) and the average days from creation to
 * done. A done ticket is terminal, so its last update is its completion time.
 */
class TicketReport extends TabularReport
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function type(): string
    {
        return 'tickets';
    }

    public function statuses(): array
    {
        return EmployeeUtilizationReport::TICKET_STATUSES;
    }

    protected function sortColumns(): array
    {
        return [
            'title' => 'LOWER(title)',
            'project' => 'LOWER(project_title)',
            'employee' => 'LOWER(COALESCE(employee_name, ""))',
            'status' => 'status',
            'priority' => "CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END",
            'estimated_hours' => 'estimated_hours',
            'created_at' => 'created_at',
            'due_date' => 'due_date',
        ];
    }

    protected function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    /** Tickets of in-scope projects (no date or status condition). */
    private function scoped(ReportFilters $filters, ReportScope $scope): Builder
    {
        [$sql, $bind] = $scope->projectSql('wi');

        return DB::table('tickets as t')
            ->join('work_items as wi', 'wi.id', '=', 't.work_item_id')
            ->whereRaw('1 = 1'.$sql, $bind)
            ->when($filters->employeeId !== null, fn (Builder $q) => $q->where('wi.employee_id', $filters->employeeId));
    }

    private function createdInRange(Builder $query, ReportFilters $filters): Builder
    {
        return $query->whereRaw('DATE(t.created_at) >= ? AND DATE(t.created_at) <= ?', [$filters->fromDate(), $filters->toDate()])
            ->when($filters->status !== null, fn (Builder $q) => $q->where('t.status', $filters->status));
    }

    protected function base(ReportFilters $filters, ReportScope $scope): Builder
    {
        return $this->createdInRange($this->scoped($filters, $scope), $filters)
            ->leftJoin('agencies as ag', 'ag.id', '=', 'wi.agency_id')
            ->leftJoin('users as em', 'em.id', '=', 'wi.employee_id')
            ->selectRaw(
                't.id AS id, ag.ticket_prefix AS prefix, t.number AS number, t.title AS title, wi.title AS project_title, em.name AS employee_name, '
                .'t.status AS status, t.priority AS priority, t.estimated_hours AS estimated_hours, t.created_at AS created_at, t.due_date AS due_date, t.updated_at AS updated_at'
            );
    }

    protected function present(Collection $rows, ReportFilters $filters, ReportScope $scope): array
    {
        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'key' => ($row->prefix && $row->number) ? "{$row->prefix}-{$row->number}" : null,
            'title' => $row->title,
            'project' => $row->project_title,
            'employee' => $row->employee_name,
            'status' => $row->status,
            'priority' => $row->priority,
            'estimated_hours' => round((float) $row->estimated_hours, 2),
            'created_at' => substr((string) $row->created_at, 0, 10),
            'due_date' => $row->due_date ? substr((string) $row->due_date, 0, 10) : null,
            'done_at' => $row->status === 'done' ? substr((string) $row->updated_at, 0, 10) : null,
        ])->values()->all();
    }

    protected function totals(Builder $base, ReportFilters $filters, ReportScope $scope): ?array
    {
        $row = DB::query()->fromSub($base, 'x')
            ->selectRaw("COUNT(*) AS tickets, COALESCE(SUM(estimated_hours), 0) AS hours, COALESCE(SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END), 0) AS done")
            ->first();

        return ['tickets' => (int) $row->tickets, 'estimated_hours' => round((float) $row->hours, 2), 'done' => (int) $row->done];
    }

    protected function summary(ReportFilters $filters, ReportScope $scope): array
    {
        $statuses = $this->statuses();

        // status x priority over the same tickets as the table
        $cells = $this->createdInRange($this->scoped($filters, $scope), $filters)
            ->selectRaw('t.status AS status, t.priority AS priority, COUNT(*) AS n')
            ->groupBy('t.status', 't.priority')
            ->get();
        $matrix = array_fill_keys($statuses, array_fill_keys(self::PRIORITIES, 0));
        foreach ($cells as $cell) {
            if (isset($matrix[$cell->status][$cell->priority])) {
                $matrix[$cell->status][$cell->priority] = (int) $cell->n;
            }
        }

        // done tickets by completion day: per ISO week, and creation -> done for the average
        $done = $this->scoped($filters, $scope)
            ->where('t.status', 'done')
            ->whereRaw('DATE(t.updated_at) >= ? AND DATE(t.updated_at) <= ?', [$filters->fromDate(), $filters->toDate()])
            ->selectRaw('DATE(t.created_at) AS created_day, DATE(t.updated_at) AS done_day, COUNT(*) AS n')
            ->groupByRaw('DATE(t.created_at), DATE(t.updated_at)')
            ->get();

        $weeks = Capacity::weekBuckets($filters->from, $filters->to);
        $tickets = 0;
        $days = 0;
        foreach ($done as $row) {
            $doneDay = CarbonImmutable::parse($row->done_day);
            $weeks[Capacity::weekLabel($doneDay)] = ($weeks[Capacity::weekLabel($doneDay)] ?? 0) + (int) $row->n;
            $tickets += (int) $row->n;
            $days += max(0, (int) CarbonImmutable::parse($row->created_day)->diffInDays($doneDay)) * (int) $row->n;
        }

        return [
            'statuses' => $statuses,
            'priorities' => self::PRIORITIES,
            'matrix' => $matrix,
            'throughput' => ['labels' => array_keys($weeks), 'values' => array_values($weeks)],
            'done_in_range' => $tickets,
            'avg_days_to_done' => $tickets > 0 ? round($days / $tickets, 1) : null,
        ];
    }

    protected function csvHeaders(): array
    {
        return ['Key', 'Title', 'Project', 'Employee', 'Status', 'Priority', 'Estimated hours', 'Created', 'Due', 'Done'];
    }

    protected function csvRow(array $row): array
    {
        return [$row['key'], $row['title'], $row['project'], $row['employee'], $row['status'], $row['priority'], $row['estimated_hours'], $row['created_at'], $row['due_date'], $row['done_at']];
    }
}
