<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * A report that is a sortable, paginated table (plus optional totals and a summary block).
 * Subclasses supply the SQL (one grouped query, subqueries for per-row counts) and the row shape.
 */
abstract class TabularReport extends Report
{
    /** Every column carries an `id` alias used as the stable tie-breaker. */
    abstract protected function base(ReportFilters $filters, ReportScope $scope): Builder;

    /** @return array<string, string> sort key => SQL expression (or alias) */
    abstract protected function sortColumns(): array;

    /** @return array{0: string, 1: string} default sort key and direction */
    abstract protected function defaultSort(): array;

    /**
     * Turns database rows into the JSON rows (computed flags, grouped follow-up queries for the page).
     *
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    abstract protected function present(Collection $rows, ReportFilters $filters, ReportScope $scope): array;

    /** @return list<string> */
    abstract protected function csvHeaders(): array;

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    abstract protected function csvRow(array $row): array;

    /** @return array<string, mixed>|null */
    protected function totals(Builder $base, ReportFilters $filters, ReportScope $scope): ?array
    {
        return null;
    }

    /** @return array<string, mixed> */
    protected function summary(ReportFilters $filters, ReportScope $scope): array
    {
        return [];
    }

    public function sortable(): array
    {
        return array_keys($this->sortColumns());
    }

    public function run(ReportFilters $filters, ReportScope $scope): array
    {
        $scope = $scope->narrow($filters);
        $base = $this->base($filters, $scope);
        $total = $this->count($base);

        $rows = $this->ordered(clone $base, $filters)
            ->forPage($filters->page, $filters->perPage)
            ->get();

        return [
            'type' => $this->type(),
            'range' => $filters->range(),
            'rows' => $this->present($rows, $filters, $scope),
            'pagination' => $this->pagination($filters, $total),
            'totals' => $this->totals($base, $filters, $scope),
            'sort' => $this->sortKey($filters).':'.$this->sortDirection($filters),
            'summary' => $this->summary($filters, $scope),
        ];
    }

    public function csv(ReportFilters $filters, ReportScope $scope): array
    {
        $scope = $scope->narrow($filters);
        $rows = $this->ordered($this->base($filters, $scope), $filters)->limit(CsvExport::MAX_ROWS + 1)->get();

        return $this->capped(
            $this->csvHeaders(),
            array_map(fn (array $row): array => $this->csvRow($row), $this->present($rows, $filters, $scope))
        );
    }

    private function sortKey(ReportFilters $filters): string
    {
        return $filters->sort !== null && isset($this->sortColumns()[$filters->sort]) ? $filters->sort : $this->defaultSort()[0];
    }

    private function sortDirection(ReportFilters $filters): string
    {
        return $filters->sort !== null ? $filters->direction : $this->defaultSort()[1];
    }

    private function ordered(Builder $query, ReportFilters $filters): Builder
    {
        $expression = $this->sortColumns()[$this->sortKey($filters)];

        // Both parts come from whitelists, never from request text.
        return $query->orderByRaw($expression.' '.($this->sortDirection($filters) === 'desc' ? 'DESC' : 'ASC').', id ASC');
    }
}
