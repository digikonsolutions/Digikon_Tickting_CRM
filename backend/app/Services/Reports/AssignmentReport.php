<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin only. Employee assignments that were active at any time inside the range (started on or before
 * the range end, not ended before its start), with the hold duration in days, plus the active employees
 * that are not held by anyone right now.
 */
class AssignmentReport extends TabularReport
{
    public function type(): string
    {
        return 'assignments';
    }

    public function statuses(): array
    {
        return ['active', 'ended'];
    }

    protected function sortColumns(): array
    {
        return [
            'employee' => 'LOWER(employee_name)',
            'holder' => 'LOWER(holder_name)',
            'agency' => 'LOWER(agency_name)',
            'status' => 'status',
            'started_at' => 'started_at',
            'ended_at' => 'COALESCE(ended_at, "9999-12-31")',
        ];
    }

    protected function defaultSort(): array
    {
        return ['started_at', 'desc'];
    }

    protected function base(ReportFilters $filters, ReportScope $scope): Builder
    {
        [$sql, $bind] = $scope->assignmentSql('ea');

        return DB::table('employee_assignments as ea')
            ->join('users as em', 'em.id', '=', 'ea.employee_id')
            ->join('users as h', 'h.id', '=', 'ea.holder_id')
            ->join('agencies as ag', 'ag.id', '=', 'ea.agency_id')
            ->whereRaw('DATE(ea.started_at) <= ? AND (ea.ended_at IS NULL OR DATE(ea.ended_at) >= ?)'.$sql, [$filters->toDate(), $filters->fromDate(), ...$bind])
            ->when($filters->status !== null, fn (Builder $q) => $q->where('ea.status', $filters->status))
            ->when($filters->employeeId !== null, fn (Builder $q) => $q->where('ea.employee_id', $filters->employeeId))
            ->selectRaw('ea.id AS id, em.id AS employee_id, em.name AS employee_name, h.name AS holder_name, ag.name AS agency_name, ea.status AS status, ea.started_at AS started_at, ea.ended_at AS ended_at');
    }

    protected function present(Collection $rows, ReportFilters $filters, ReportScope $scope): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));

        return $rows->map(function (object $row) use ($now): array {
            $start = CarbonImmutable::parse($row->started_at, config('app.timezone'));
            $end = $row->ended_at ? CarbonImmutable::parse($row->ended_at, config('app.timezone')) : $now;

            return [
                'id' => (int) $row->id,
                'employee_id' => (int) $row->employee_id,
                'employee' => $row->employee_name,
                'holder' => $row->holder_name,
                'agency' => $row->agency_name,
                'status' => $row->status,
                'started_at' => $start->toDateString(),
                'ended_at' => $row->ended_at ? $end->toDateString() : null,
                'hold_days' => round(max(0, $start->diffInSeconds($end)) / 86400, 1),
            ];
        })->values()->all();
    }

    protected function totals(Builder $base, ReportFilters $filters, ReportScope $scope): ?array
    {
        $row = DB::query()->fromSub($base, 'x')
            ->selectRaw("COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active, COALESCE(SUM(CASE WHEN status = 'ended' THEN 1 ELSE 0 END), 0) AS ended")
            ->first();

        return ['assignments' => (int) $row->total, 'active' => (int) $row->active, 'ended' => (int) $row->ended];
    }

    protected function summary(ReportFilters $filters, ReportScope $scope): array
    {
        return $this->unassigned();
    }

    /** Active employees that no one holds right now (a snapshot, not limited by the range). */
    public function unassigned(): array
    {
        $query = DB::table('users as u')
            ->where('u.role', 'employee')
            ->where('u.is_active', true)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_assignments as ea')->whereColumn('ea.employee_id', 'u.id')->where('ea.status', 'active'));

        return [
            'unassigned_count' => (clone $query)->count(),
            'unassigned_employees' => (clone $query)->orderBy('u.name')->limit(50)->get(['u.id', 'u.name'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => $row->name])->all(),
        ];
    }

    protected function csvHeaders(): array
    {
        return ['Employee', 'Holder', 'Agency', 'Status', 'Started', 'Ended', 'Hold days'];
    }

    protected function csvRow(array $row): array
    {
        return [$row['employee'], $row['holder'], $row['agency'], $row['status'], $row['started_at'], $row['ended_at'], $row['hold_days']];
    }
}
