<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/** Validated report filters (built by ReportRequest). Dates are inclusive calendar days. */
final class ReportFilters
{
    public const MAX_RANGE_DAYS = 366;

    public const DEFAULT_RANGE_DAYS = 30;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $status = null,
        public readonly ?int $agencyId = null,
        public readonly ?int $employeeId = null,
        public readonly ?int $clientId = null,
        public readonly int $page = 1,
        public readonly int $perPage = 25,
        public readonly ?string $sort = null,
        public readonly string $direction = 'asc',
        public readonly bool $csv = false,
    ) {
    }

    /** @param array<string, mixed> $data validated input */
    public static function fromArray(array $data): self
    {
        $timezone = config('app.timezone');
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $timezone) : CarbonImmutable::now($timezone);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $timezone) : $to->subDays(self::DEFAULT_RANGE_DAYS - 1);

        return new self(
            from: $from->startOfDay(),
            to: $to->startOfDay(),
            status: $data['status'] ?? null,
            agencyId: isset($data['agency_id']) ? (int) $data['agency_id'] : null,
            employeeId: isset($data['employee_id']) ? (int) $data['employee_id'] : null,
            clientId: isset($data['client_id']) ? (int) $data['client_id'] : null,
            page: max(1, (int) ($data['page'] ?? 1)),
            perPage: min(100, max(1, (int) ($data['per_page'] ?? 25))),
            sort: $data['sort'] ?? null,
            direction: ($data['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            csv: ($data['format'] ?? 'json') === 'csv',
        );
    }

    /** A copy with another status filter (the overview ignores the status filter). */
    public function withStatus(?string $status): self
    {
        return new self($this->from, $this->to, $status, $this->agencyId, $this->employeeId, $this->clientId, $this->page, $this->perPage, $this->sort, $this->direction, $this->csv);
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** @return array{from: string, to: string} */
    public function range(): array
    {
        return ['from' => $this->fromDate(), 'to' => $this->toDate()];
    }
}
