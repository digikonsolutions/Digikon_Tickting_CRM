<?php

namespace Tests\Unit;

use App\Services\Support\SlaCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Business-time arithmetic: hours, weekends, holidays, timezones and daylight saving. */
class SlaCalculatorTest extends TestCase
{
    private function karachi(array $holidays = []): SlaCalculator
    {
        return new SlaCalculator('Asia/Karachi', [1, 2, 3, 4, 5], '09:00', '18:00', $holidays);
    }

    private function at(string $time, string $tz = 'Asia/Karachi'): CarbonImmutable
    {
        return CarbonImmutable::parse($time, $tz);
    }

    public function test_minutes_inside_one_day(): void
    {
        $due = $this->karachi()->addBusinessMinutes($this->at('2026-11-30 10:00'), 60); // a Monday
        $this->assertSame('2026-11-30 11:00', $due->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    public function test_the_end_of_the_day_rolls_to_the_next_working_day(): void
    {
        $due = $this->karachi()->addBusinessMinutes($this->at('2026-11-30 17:30'), 60);
        $this->assertSame('2026-12-01 09:30', $due->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    public function test_time_before_opening_starts_at_opening(): void
    {
        $due = $this->karachi()->addBusinessMinutes($this->at('2026-11-30 06:00'), 30);
        $this->assertSame('2026-11-30 09:30', $due->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    public function test_weekends_are_skipped(): void
    {
        $due = $this->karachi()->addBusinessMinutes($this->at('2026-12-04 17:00'), 120); // Friday
        $this->assertSame('2026-12-07 10:00', $due->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    public function test_holidays_are_skipped(): void
    {
        $due = $this->karachi(['2026-12-01'])->addBusinessMinutes($this->at('2026-11-30 17:00'), 120);
        $this->assertSame('2026-12-02 10:00', $due->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    public function test_a_full_day_of_minutes_is_one_working_day(): void
    {
        $calendar = $this->karachi();
        $this->assertSame(540, $calendar->businessMinutesPerDay());
        $due = $calendar->addBusinessMinutes($this->at('2026-11-30 09:00'), 540);
        $this->assertSame('2026-11-30 18:00', $due->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    public function test_between_counts_only_business_time_and_is_signed(): void
    {
        $calendar = $this->karachi();
        $friday = $this->at('2026-12-04 17:00');
        $monday = $this->at('2026-12-07 10:00');
        $this->assertSame(120, $calendar->businessMinutesBetween($friday, $monday));
        $this->assertSame(-120, $calendar->businessMinutesBetween($monday, $friday));
        $this->assertSame(0, $calendar->businessMinutesBetween($this->at('2026-12-05 10:00'), $this->at('2026-12-06 15:00'))); // a weekend
    }

    public function test_is_business_time(): void
    {
        $calendar = $this->karachi();
        $this->assertTrue($calendar->isBusinessTime($this->at('2026-11-30 12:00')));
        $this->assertFalse($calendar->isBusinessTime($this->at('2026-11-30 18:00')));
        $this->assertFalse($calendar->isBusinessTime($this->at('2026-12-05 12:00')));
    }

    public function test_the_working_day_does_not_move_when_clocks_change(): void
    {
        $calendar = new SlaCalculator('America/New_York', [1, 2, 3, 4, 5], '09:00', '18:00', []);
        // Friday 6 March 2026 is on winter time, Monday 9 March is on summer time (the change was on Sunday).
        $due = $calendar->addBusinessMinutes($this->at('2026-03-06 17:00', 'America/New_York'), 61);
        $local = $due->setTimezone('America/New_York');
        $this->assertSame('2026-03-09 09:01', $local->format('Y-m-d H:i'));
        $this->assertSame(-4 * 3600, $local->getOffset());
        $this->assertSame(61, $calendar->businessMinutesBetween($this->at('2026-03-06 17:00', 'America/New_York'), $due));
    }
}
