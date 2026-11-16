<?php

namespace App\Console\Commands;

use App\Services\ActivityLogger;
use Illuminate\Console\Command;

class MaintainActivity extends Command
{
    protected $signature = 'activity:maintain';

    protected $description = 'Close work sessions that stopped sending heartbeats and prune activity past the retention period';

    public function handle(ActivityLogger $activity): int
    {
        $closed = $activity->closeStaleSessions();
        $pruned = $activity->prune();
        $this->info("Closed {$closed} stale session(s), pruned {$pruned} old log row(s).");

        return self::SUCCESS;
    }
}
