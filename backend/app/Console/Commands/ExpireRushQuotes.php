<?php

namespace App\Console\Commands;

use App\Services\RushRequestService;
use Illuminate\Console\Command;

/** Every 5 minutes: quotes past their expiry become "expired" and the admins are told. */
class ExpireRushQuotes extends Command
{
    protected $signature = 'rush:expire-quotes';

    protected $description = 'Mark urgent-request quotes past their expiry as expired';

    public function handle(RushRequestService $rush): int
    {
        $this->info('Expired '.$rush->expireQuotes().' quote(s).');

        return self::SUCCESS;
    }
}
