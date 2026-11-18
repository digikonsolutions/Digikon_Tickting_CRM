<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One report. Controllers stay thin: they resolve a Report, validate with ReportRequest, then call
 * run() (JSON) or csv() (export) with the filters and the scope derived from the signed-in user.
 */
abstract class Report
{
    /** Name used in CSV file names, e.g. "employees". */
    abstract public function type(): string;

    /** @return list<string> sort keys the request may use */
    public function sortable(): array
    {
        return [];
    }

    /** @return list<string> values the `status` filter accepts for this report */
    public function statuses(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    abstract public function run(ReportFilters $filters, ReportScope $scope): array;

    /** @return array{headers: list<string>, rows: list<list<mixed>>, truncated: bool} */
    abstract public function csv(ReportFilters $filters, ReportScope $scope): array;

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows  at most MAX_ROWS + 1 rows
     * @return array{headers: list<string>, rows: list<list<mixed>>, truncated: bool}
     */
    protected function capped(array $headers, array $rows): array
    {
        $truncated = count($rows) > CsvExport::MAX_ROWS;

        return ['headers' => $headers, 'rows' => array_slice($rows, 0, CsvExport::MAX_ROWS), 'truncated' => $truncated];
    }

    /** @return array{current_page: int, last_page: int, per_page: int, total: int} */
    protected function pagination(ReportFilters $filters, int $total): array
    {
        return [
            'current_page' => $filters->page,
            'last_page' => max(1, (int) ceil($total / $filters->perPage)),
            'per_page' => $filters->perPage,
            'total' => $total,
        ];
    }

    protected function today(): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    /** SQL expression for a DATE() of a column, portable enough for SQLite and MySQL. */
    protected function day(string $column): string
    {
        return "DATE({$column})";
    }

    protected function count(Builder $query): int
    {
        return (int) DB::query()->fromSub($query, 'x')->count();
    }
}
