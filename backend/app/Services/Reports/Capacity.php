<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/** Working-day capacity of one employee over a date range, from config/scheduling.php (6 h x Mon-Sat = 36 h a week). */
final class Capacity
{
    public static function workingDays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $days = (array) config('scheduling.working_days', [1, 2, 3, 4, 5, 6]);
        $off = array_flip((array) config('scheduling.non_working_dates', []));
        $count = 0;
        for ($day = $from->startOfDay(); $day <= $to; $day = $day->addDay()) {
            if (in_array($day->dayOfWeekIso, $days, true) && ! isset($off[$day->toDateString()])) {
                $count++;
            }
        }

        return $count;
    }

    public static function hours(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return round(self::workingDays($from, $to) * (float) config('scheduling.daily_capacity_hours', 6), 2);
    }

    /** under 50% = low, over 90% = high, otherwise healthy. Null when there is no capacity. */
    public static function band(?float $utilization): ?string
    {
        if ($utilization === null) {
            return null;
        }

        return $utilization < 50 ? 'low' : ($utilization > 90 ? 'high' : 'healthy');
    }

    public static function utilization(float $scheduled, float $capacity): ?float
    {
        return $capacity > 0 ? round($scheduled / $capacity * 100, 1) : null;
    }

    /** ISO week label such as 2026-W46. */
    public static function weekLabel(CarbonImmutable $date): string
    {
        return $date->format('o-\WW');
    }

    /**
     * Zero-filled ISO-week buckets covering the range.
     *
     * @return array<string, int|float>
     */
    public static function weekBuckets(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $buckets = [];
        for ($week = $from->startOfWeek(); $week <= $to; $week = $week->addWeek()) {
            $buckets[self::weekLabel($week)] = 0;
        }

        return $buckets;
    }
}
