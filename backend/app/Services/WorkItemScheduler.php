<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WorkItemScheduler
{
    private const DAILY_CAPACITY_UNITS = 600;

    /**
     * @return list<array{work_date: string, units: int}>
     */
    public function plan(User $employee, CarbonImmutable $start, CarbonImmutable $due, int $requestedUnits): ?array
    {
        $bookedByDate = $this->bookedUnitsByDate($employee, $start, $due);
        $plan = [];
        foreach ($this->eligibleDates($start, $due) as $workDate) {
            $bookedUnits = $bookedByDate[$workDate] ?? 0;
            $availableUnits = max(0, self::DAILY_CAPACITY_UNITS - $bookedUnits);
            $allocatedUnits = min($availableUnits, $requestedUnits);

            if ($allocatedUnits > 0) {
                $plan[] = ['work_date' => $workDate, 'units' => $allocatedUnits];
                $requestedUnits -= $allocatedUnits;
            }

            if ($requestedUnits === 0) {
                return $plan;
            }
        }

        return null;
    }

    public function availableUnits(User $employee, CarbonImmutable $start, CarbonImmutable $due): int
    {
        $bookedByDate = $this->bookedUnitsByDate($employee, $start, $due);
        $availableUnits = 0;
        foreach ($this->eligibleDates($start, $due) as $workDate) {
            $bookedUnits = $bookedByDate[$workDate] ?? 0;
            $availableUnits += max(0, self::DAILY_CAPACITY_UNITS - $bookedUnits);
        }

        return $availableUnits;
    }

    /**
     * @return list<string>
     */
    private function eligibleDates(CarbonImmutable $start, CarbonImmutable $due): array
    {
        $nonWorkingDates = config('scheduling.non_working_dates', []);
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->toDateString();
        $cutoff = (int) config('scheduling.today_cutoff_hour', 12);
        $beforeCutoff = $now->lessThan($now->startOfDay()->addHours($cutoff));
        $dates = [];

        for ($date = $start; $date->lessThanOrEqualTo($due); $date = $date->addDay()) {
            $workDate = $date->toDateString();

            if (
                $date->dayOfWeek === CarbonImmutable::SUNDAY
                || in_array($workDate, $nonWorkingDates, true)
                || ($workDate === $today && ! $beforeCutoff)
            ) {
                continue;
            }

            $dates[] = $workDate;
        }

        return $dates;
    }

    /**
     * @return array<string, int>
     */
    private function bookedUnitsByDate(User $employee, CarbonImmutable $start, CarbonImmutable $due): array
    {
        return DB::table('work_item_allocations')
            ->join('work_items', 'work_items.id', '=', 'work_item_allocations.work_item_id')
            ->where('work_item_allocations.employee_id', $employee->id)
            ->whereDate('work_item_allocations.work_date', '>=', $start->toDateString())
            ->whereDate('work_item_allocations.work_date', '<=', $due->toDateString())
            ->whereNotIn('work_items.status', ['completed', 'cancelled'])
            ->selectRaw('DATE(work_item_allocations.work_date) AS work_date, SUM(work_item_allocations.hours) AS booked_hours')
            ->groupByRaw('DATE(work_item_allocations.work_date)')
            ->pluck('booked_hours', 'work_date')
            ->map(fn ($hours): int => (int) round((float) $hours * 100))
            ->all();
    }

    public static function unitsToHours(int $units): string
    {
        return number_format($units / 100, 2, '.', '');
    }
}
