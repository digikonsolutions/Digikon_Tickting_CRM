<?php

namespace App\Console\Commands;

use App\Services\TimeTracker;
use Illuminate\Console\Command;

class StopStaleTimers extends Command
{
    protected $signature = 'time:stop-stale-timers';

    protected $description = 'Close time-tracking timers that have run past the configured cap';

    public function handle(TimeTracker $time): int
    {
        $count = $time->stopStale();
        $this->info("Stopped {$count} stale timer(s).");

        return self::SUCCESS;
    }
}