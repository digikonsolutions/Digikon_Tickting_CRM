<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin only. Per agency: status, owners, clients, employees currently held, open / completed / cancelled
 * projects (overlapping the range), scheduled hours in the range and completion rate
 * (completed / (open + completed); cancelled projects are left out of the rate).
 */
class AgencySummaryReport extends TabularReport
{
    public function type(): string
    {
        return 'agencies';
    }

    public function statuses(): array
    {
        return ['active', 'suspended'];
    }

    protected function sortColumns(): array
    {
        return [
            'name' => 'LOWER(name)',
            'status' => 'status',
            'owners' => 'owners',
            'clients' => 'clients',
            'employees_held' => 'employees_held',
            'open' => 'open_projects',
            'completed' => 'completed_projects',
            'cancelled' => 'cancelled_projects',
            'scheduled_hours' => 'scheduled_hours',
        ];
    }

    protected function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    protected function base(ReportFilters $filters, ReportScope $scope): Builder
    {
        [$projectSql, $projectBind] = $scope->projectSql('wi');
        $overlap = ' AND DATE(wi.start_date) <= ? AND DATE(wi.due_date) >= ?';
        $overlapBind = [$filters->toDate(), $filters->fromDate()];
        $count = fn (string $statuses): string => "(SELECT COUNT(*) FROM work_items wi WHERE wi.agency_id = a.id AND wi.status IN ({$statuses}){$overlap}{$projectSql})";

        return DB::table('agencies as a')
            ->when($scope->agencyId !== null, fn (Builder $q) => $q->where('a.id', $scope->agencyId))
            ->when($filters->status !== null, fn (Builder $q) => $q->where('a.status', $filters->status))
            ->selectRaw(
                'a.id AS id, a.name AS name, a.status AS status, '
                ."(SELECT COUNT(*) FROM users u WHERE u.agency_id = a.id AND u.role = 'agency_owner') AS owners, "
                ."(SELECT COUNT(*) FROM users u WHERE u.agency_id = a.id AND u.role = 'agency_client') AS clients, "
                ."(SELECT COUNT(*) FROM employee_assignments ea WHERE ea.agency_id = a.id AND ea.status = 'active') AS employees_held, "
                .$count("'scheduled', 'in_progress'").' AS open_projects, '
                .$count("'completed'").' AS completed_projects, '
                .$count("'cancelled'").' AS cancelled_projects, '
                .'COALESCE((SELECT SUM(al.hours) FROM work_item_allocations al JOIN work_items wi ON wi.id = al.work_item_id '
                ."WHERE wi.agency_id = a.id AND wi.status <> 'cancelled' AND DATE(al.work_date) >= ? AND DATE(al.work_date) <= ?{$projectSql}), 0) AS scheduled_hours",
                [
                    ...$overlapBind, ...$projectBind,
                    ...$overlapBind, ...$projectBind,
                    ...$overlapBind, ...$projectBind,
                    $filters->fromDate(), $filters->toDate(), ...$projectBind,
                ]
            );
    }

    protected function present(Collection $rows, ReportFilters $filters, ReportScope $scope): array
    {
        return $rows->map(function (object $row): array {
            $open = (int) $row->open_projects;
            $completed = (int) $row->completed_projects;

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'status' => $row->status,
                'owners' => (int) $row->owners,
                'clients' => (int) $row->clients,
                'employees_held' => (int) $row->employees_held,
                'open_projects' => $open,
                'completed_projects' => $completed,
                'cancelled_projects' => (int) $row->cancelled_projects,
                'scheduled_hours' => round((float) $row->scheduled_hours, 2),
                'completion_rate' => ($open + $completed) > 0 ? round($completed / ($open + $completed) * 100, 1) : null,
            ];
        })->values()->all();
    }

    protected function totals(Builder $base, ReportFilters $filters, ReportScope $scope): ?array
    {
        $row = DB::query()->fromSub($base, 'x')
            ->selectRaw('COUNT(*) AS agencies, COALESCE(SUM(employees_held), 0) AS held, COALESCE(SUM(open_projects), 0) AS open_p, COALESCE(SUM(completed_projects), 0) AS done_p, COALESCE(SUM(cancelled_projects), 0) AS cancelled_p, COALESCE(SUM(scheduled_hours), 0) AS hours')
            ->first();
        $denominator = (int) $row->open_p + (int) $row->done_p;

        return [
            'agencies' => (int) $row->agencies,
            'employees_held' => (int) $row->held,
            'open_projects' => (int) $row->open_p,
            'completed_projects' => (int) $row->done_p,
            'cancelled_projects' => (int) $row->cancelled_p,
            'scheduled_hours' => round((float) $row->hours, 2),
            'completion_rate' => $denominator > 0 ? round((int) $row->done_p / $denominator * 100, 1) : null,
        ];
    }

    protected function csvHeaders(): array
    {
        return ['Agency', 'Status', 'Owners', 'Clients', 'Employees held', 'Open projects', 'Completed projects', 'Cancelled projects', 'Scheduled hours', 'Completion rate %'];
    }

    protected function csvRow(array $row): array
    {
        return [$row['name'], $row['status'], $row['owners'], $row['clients'], $row['employees_held'], $row['open_projects'], $row['completed_projects'], $row['cancelled_projects'], $row['scheduled_hours'], $row['completion_rate']];
    }
}
