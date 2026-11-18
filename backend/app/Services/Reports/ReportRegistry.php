<?php

namespace App\Services\Reports;

/** Which reports exist for which audience. Unknown or not-allowed types resolve to null (the caller 404s). */
final class ReportRegistry
{
    /** @var array<string, class-string<Report>> */
    private const ADMIN = [
        'overview' => OverviewReport::class,
        'employees' => EmployeeUtilizationReport::class,
        'agencies' => AgencySummaryReport::class,
        'projects' => ProjectReport::class,
        'tickets' => TicketReport::class,
        'assignments' => AssignmentReport::class,
    ];

    /** @var array<string, class-string<Report>> */
    private const AGENCY = [
        'overview' => OverviewReport::class,
        'employees' => EmployeeUtilizationReport::class,
        'projects' => ProjectReport::class,
        'tickets' => TicketReport::class,
    ];

    public static function resolve(string $type, bool $admin): ?Report
    {
        $class = ($admin ? self::ADMIN : self::AGENCY)[$type] ?? null;

        return $class ? new $class() : null;
    }

    /** @return list<string> */
    public static function types(bool $admin): array
    {
        return array_keys($admin ? self::ADMIN : self::AGENCY);
    }
}
