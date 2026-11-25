<?php

namespace App\Console\Commands;

use App\Services\Support\SlaSweeper;
use Illuminate\Console\Command;

class SweepTicketSla extends Command
{
    protected $signature = 'tickets:sla-sweep';

    protected $description = 'Flag at-risk and breached SLA clocks, escalate unassigned tickets, remind waiting agencies and auto-close resolved tickets';

    public function handle(SlaSweeper $sweeper): int
    {
        $done = $sweeper->sweep();
        $this->info(collect($done)->map(fn (int $n, string $key): string => "{$key}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
