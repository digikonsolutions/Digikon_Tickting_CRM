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
     * Per-day availability for an employee, using exactly the same rules as plan():
     * Sundays, configured non-working dates and today-after-cutoff are skipped, each
     * working day holds at most 6 h, and bookings from ALL agencies count.
     *
     * @return list<array{date: string, available_hours: string, booked_hours: string, working_day: bool, reason: string|null}>
     */
    public function dailyAvailability(User $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $bookedByDate = $this->bookedUnitsByDate($employee, $from, $to);
        $context = $this->dateContext();
        $days = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $workDate = $date->toDateString();
            $reason = $this->skipReason($date, $context);
            $bookedUnits = $bookedByDate[$workDate] ?? 0;
            $availableUnits = $reason === null ? max(0, self::DAILY_CAPACITY_UNITS - $bookedUnits) : 0;

            $days[] = [
                'date' => $workDate,
                'available_hours' => self::unitsToHours($availableUnits),
                'booked_hours' => self::unitsToHours($bookedUnits),
                'working_day' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $days;
    }

    /**
     * @return list<string>
     */
    private function eligibleDates(CarbonImmutable $start, CarbonImmutable $due): array
    {
        $context = $this->dateContext();
        $dates = [];

        for ($date = $start; $date->lessThanOrEqualTo($due); $date = $date->addDay()) {
            if ($this->skipReason($date, $context) === null) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    /**
     * @return array{non_working_dates: list<string>, today: string, before_cutoff: bool}
     */
    private function dateContext(): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $cutoff = (int) config('scheduling.today_cutoff_hour', 12);

        return [
            'non_working_dates' => config('scheduling.non_working_dates', []),
            'today' => $now->toDateString(),
            'before_cutoff' => $now->lessThan($now->startOfDay()->addHours($cutoff)),
        ];
    }

    /**
     * @param array{non_working_dates: list<string>, today: string, before_cutoff: bool} $context
     */
    private function skipReason(CarbonImmutable $date, array $context): ?string
    {
        $workDate = $date->toDateString();

        if ($date->dayOfWeek === CarbonImmutable::SUNDAY) {
            return 'sunday';
        }
        if (in_array($workDate, $context['non_working_dates'], true)) {
            return 'non_working_day';
        }
        if ($workDate === $context['today'] && ! $context['before_cutoff']) {
            return 'past_cutoff';
        }

        return null;
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
