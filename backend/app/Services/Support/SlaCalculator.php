<?php

namespace App\Services\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Business-time arithmetic: support hours on chosen weekdays, in one timezone, skipping holidays. Every day is built from
 * its own wall-clock start and end in that timezone, so a daylight-saving change never shifts the working day.
 * Pure: it knows nothing about tickets.
 */
class SlaCalculator
{
    /**
     * @param  list<int>  $days  ISO weekdays that are working days (1 = Monday)
     * @param  list<string>  $holidays  'Y-m-d' dates
     */
    public function __construct(
        private string $timezone = 'Asia/Karachi',
        private array $days = [1, 2, 3, 4, 5],
        private string $start = '09:00',
        private string $end = '18:00',
        private array $holidays = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('support.timezone', 'Asia/Karachi'),
            (array) config('support.hours.days', [1, 2, 3, 4, 5]),
            (string) config('support.hours.start', '09:00'),
            (string) config('support.hours.end', '18:00'),
            (array) config('support.holidays', []),
        );
    }

    public function businessMinutesPerDay(): int
    {
        return (int) $this->window(CarbonImmutable::now($this->timezone))[0]->diffInMinutes($this->window(CarbonImmutable::now($this->timezone))[1]);
    }

    private function isWorkingDay(CarbonInterface $day): bool
    {
        return in_array($day->dayOfWeekIso, $this->days, true) && ! in_array($day->format('Y-m-d'), $this->holidays, true);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the working window of the calendar day of `$day` */
    private function window(CarbonInterface $day): array
    {
        $base = CarbonImmutable::parse($day->format('Y-m-d'), $this->timezone);
        [$sh, $sm] = array_map('intval', explode(':', $this->start));
        [$eh, $em] = array_map('intval', explode(':', $this->end));

        return [$base->setTime($sh, $sm), $base->setTime($eh, $em)];
    }

    public function isBusinessTime(CarbonInterface $at): bool
    {
        $local = CarbonImmutable::instance($at)->setTimezone($this->timezone);
        if (! $this->isWorkingDay($local)) {
            return false;
        }
        [$from, $to] = $this->window($local);

        return $local->greaterThanOrEqualTo($from) && $local->lessThan($to);
    }

    /** The moment `$minutes` of business time after `$from` (zero minutes returns the next moment business time is on). */
    public function addBusinessMinutes(CarbonInterface $from, int $minutes): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $left = max(0, $minutes);
        for ($guard = 0; $guard < 800; $guard++) {
            if ($this->isWorkingDay($cursor)) {
                [$open, $close] = $this->window($cursor);
                $position = $cursor->lessThan($open) ? $open : $cursor;
                if ($position->lessThan($close)) {
                    $available = (int) $position->diffInMinutes($close);
                    if ($left <= $available) {
                        return $position->addMinutes($left)->setTimezone(date_default_timezone_get());
                    }
                    $left -= $available;
                }
            }
            $cursor = $cursor->addDay()->startOfDay();
        }

        return $cursor->setTimezone(date_default_timezone_get());
    }

    /** Whole business minutes between two moments; negative when `$to` is earlier. */
    public function businessMinutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        if ($to->lessThan($from)) {
            return -$this->businessMinutesBetween($to, $from);
        }
        $a = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $b = CarbonImmutable::instance($to)->setTimezone($this->timezone);
        $total = 0;
        $day = $a->startOfDay();
        for ($guard = 0; $guard < 800 && $day->lessThanOrEqualTo($b); $guard++) {
            if ($this->isWorkingDay($day)) {
                [$open, $close] = $this->window($day);
                $lo = $a->greaterThan($open) ? $a : $open;
                $hi = $b->lessThan($close) ? $b : $close;
                if ($hi->greaterThan($lo)) {
                    $total += (int) $lo->diffInMinutes($hi);
                }
            }
            $day = $day->addDay()->startOfDay();
        }

        return $total;
    }
}
