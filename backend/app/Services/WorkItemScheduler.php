<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkItemScheduler
{
    private const DAILY_CAPACITY_UNITS = 600;

    public function assignableEmployees(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where('role', Role::Employee->value)
            ->whereNull('agency_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{work_date: string, units: int}>
     */
    public function plan(User $employee, CarbonImmutable $start, CarbonImmutable $due, int $requestedUnits): ?array
    {
        $busyByDate = DB::table('work_item_allocations')
            ->join('work_items', 'work_items.id', '=', 'work_item_allocations.work_item_id')
            ->where('work_item_allocations.employee_id', $employee->id)
            ->whereDate('work_item_allocations.work_date', '>=', $start->toDateString())
            ->whereDate('work_item_allocations.work_date', '<=', $due->toDateString())
            ->whereNotIn('work_items.status', ['completed', 'cancelled'])
            ->selectRaw('DATE(work_item_allocations.work_date) AS work_date, SUM(work_item_allocations.hours) AS booked_hours')
            ->groupByRaw('DATE(work_item_allocations.work_date)')
            ->pluck('booked_hours', 'work_date');

        $days = [];

        for ($date = $start; $date->lessThanOrEqualTo($due); $date = $date->addDay()) {
            if ($date->dayOfWeek === CarbonImmutable::SUNDAY) {
                continue;
            }

            $bookedUnits = (int) round(((float) ($busyByDate[$date->toDateString()] ?? 0)) * 100);
            $days[$date->toDateString()] = [
                'load' => min(self::DAILY_CAPACITY_UNITS, $bookedUnits),
                'allocated' => 0,
            ];
        }

        while ($requestedUnits > 0) {
            $availableDates = array_keys(array_filter(
                $days,
                fn (array $day): bool => $day['load'] < self::DAILY_CAPACITY_UNITS
            ));

            if ($availableDates === []) {
                return null;
            }

            usort($availableDates, fn (string $left, string $right): int => (
                $days[$left]['load'] <=> $days[$right]['load']
                ?: $left <=> $right
            ));

            $lowestLoad = $days[$availableDates[0]]['load'];
            $lowestDates = array_values(array_filter(
                $availableDates,
                fn (string $date): bool => $days[$date]['load'] === $lowestLoad
            ));
            $nextLoad = self::DAILY_CAPACITY_UNITS;
            foreach ($availableDates as $date) {
                if ($days[$date]['load'] > $lowestLoad) {
                    $nextLoad = $days[$date]['load'];
                    break;
                }
            }

            $levelUnits = $nextLoad - $lowestLoad;
            $unitsToLevel = $levelUnits * count($lowestDates);

            if ($requestedUnits >= $unitsToLevel) {
                foreach ($lowestDates as $date) {
                    $days[$date]['load'] += $levelUnits;
                    $days[$date]['allocated'] += $levelUnits;
                }
                $requestedUnits -= $unitsToLevel;

                continue;
            }

            $share = intdiv($requestedUnits, count($lowestDates));
            $remainder = $requestedUnits % count($lowestDates);
            foreach ($lowestDates as $index => $date) {
                $allocation = $share + ($index < $remainder ? 1 : 0);
                $days[$date]['allocated'] += $allocation;
            }
            $requestedUnits = 0;
        }

        $plan = [];
        foreach ($days as $workDate => $day) {
            if ($day['allocated'] > 0) {
                $plan[] = ['work_date' => $workDate, 'units' => $day['allocated']];
            }
        }

        return $plan;
    }

    public function availableUnits(User $employee, CarbonImmutable $start, CarbonImmutable $due): int
    {
        $bookedByDate = DB::table('work_item_allocations')
            ->join('work_items', 'work_items.id', '=', 'work_item_allocations.work_item_id')
            ->where('work_item_allocations.employee_id', $employee->id)
            ->whereDate('work_item_allocations.work_date', '>=', $start->toDateString())
            ->whereDate('work_item_allocations.work_date', '<=', $due->toDateString())
            ->whereNotIn('work_items.status', ['completed', 'cancelled'])
            ->selectRaw('DATE(work_item_allocations.work_date) AS work_date, SUM(work_item_allocations.hours) AS booked_hours')
            ->groupByRaw('DATE(work_item_allocations.work_date)')
            ->pluck('booked_hours', 'work_date');

        $availableUnits = 0;
        for ($date = $start; $date->lessThanOrEqualTo($due); $date = $date->addDay()) {
            if ($date->dayOfWeek === CarbonImmutable::SUNDAY) {
                continue;
            }

            $bookedUnits = (int) round(((float) ($bookedByDate[$date->toDateString()] ?? 0)) * 100);
            $availableUnits += max(0, self::DAILY_CAPACITY_UNITS - $bookedUnits);
        }

        return $availableUnits;
    }

    public static function unitsToHours(int $units): string
    {
        return number_format($units / 100, 2, '.', '');
    }
}
