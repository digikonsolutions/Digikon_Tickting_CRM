<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Closes timers that ran past the cap. Needs `php artisan schedule:work` (or a cron entry) to run.
Schedule::command('time:stop-stale-timers')->hourly();
