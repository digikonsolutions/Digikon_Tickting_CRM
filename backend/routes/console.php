<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Closes timers that ran past the cap. Needs `php artisan schedule:work` (or a cron entry) to run.
Schedule::command('time:stop-stale-timers')->hourly();

// Closes work sessions whose heartbeats stopped and prunes old activity rows.
Schedule::command('activity:maintain')->everyFiveMinutes();

// Emails admins about timelines that have waited 24 h and 48 h for review. Needs `php artisan schedule:work` (or a cron entry).
Schedule::command('timeline:remind')->hourly();

// Urgent-request quotes past their expiry are marked expired. Needs `php artisan schedule:work` (or a cron entry).
Schedule::command('rush:expire-quotes')->everyFiveMinutes();

// Temporary employee assignments (extra team member on a rush request) end at their expiry.
Schedule::command('assignments:expire-temporary')->everyFiveMinutes();

// Work starting today and deadlines (3 days, 1 day, today, overdue) reach employees as notifications, once each.
Schedule::command('app:send-work-reminders')->everyThirtyMinutes()->withoutOverlapping()->timezone(config('app.timezone'));

// Support module: SLA clocks, unassigned escalation, waiting-for-agency reminders and auto-close. Needs `php artisan schedule:work` (or a cron entry).
Schedule::command('tickets:sla-sweep')->everyMinute()->withoutOverlapping();
Schedule::command('tickets:run-recurring')->dailyAt('06:00')->timezone(config('support.timezone'))->withoutOverlapping();
