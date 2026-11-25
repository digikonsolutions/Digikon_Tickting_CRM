<?php

return [
    // The browser reports "no UI interaction" after this many minutes. Only a yes/no flag is sent:
    // no keystrokes, no page content. (Screenshots only exist with the opt-in snapshots setting below.)
    'idle_after_minutes' => (int) env('ACTIVITY_IDLE_AFTER_MINUTES', 5),

    // A session without a heartbeat for this long is closed at its last heartbeat.
    'session_timeout_minutes' => (int) env('ACTIVITY_SESSION_TIMEOUT_MINUTES', 10),

    // One heartbeat can add at most this many seconds, so a stalled tab cannot inflate the totals.
    'max_heartbeat_gap_seconds' => 120,

    // Used to flag late and early first logins (application timezone).
    'workday_start' => env('ACTIVITY_WORKDAY_START', '09:00'),
    'login_grace_minutes' => 15,

    'retention_days' => (int) env('ACTIVITY_RETENTION_DAYS', 365),

    // Browser history is kept only while the employee has a ticket (or a ticket-less project) in progress, and each
    // record is linked to that work. Anything the extension sends from outside working time is dropped.
    'browser_history_work_only' => (bool) env('ACTIVITY_BROWSER_HISTORY_WORK_ONLY', true),

    // Work snapshots: a small screenshot of the browser tab in front, taken now and then during working hours, on any website.
    // OFF until switched on (SNAPSHOTS_ENABLED=true), because it changes what employees agree to: the
    // notice version moves to 3.0 and everyone accepts it again before the first picture is taken.
    'snapshots' => [
        'enabled' => filter_var(env('SNAPSHOTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // A private disk. Use an S3-compatible disk in production so pictures never sit on the web server.
        'disk' => env('SNAPSHOTS_DISK', 'local'),
        'min_minutes' => (int) env('SNAPSHOTS_MIN_MINUTES', 5),
        'max_minutes' => (int) env('SNAPSHOTS_MAX_MINUTES', 15),
        'retention_days' => (int) env('SNAPSHOTS_RETENTION_DAYS', 30),
        'max_kb' => 400,
        // Empty means any website. Set it (comma separated) to keep pictures to these sites and their subdomains only.
        'allowed_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('SNAPSHOTS_ALLOWED_DOMAINS', ''))))),
        // Never photographed, whatever the list above says (for example a bank or a password manager).
        'blocked_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('SNAPSHOTS_BLOCKED_DOMAINS', ''))))),
        // Pictures are only taken in working hours, in the application timezone. Days are ISO numbers, 1 = Monday.
        'hours_start' => env('SNAPSHOTS_HOURS_START', '09:00'),
        'hours_end' => env('SNAPSHOTS_HOURS_END', '18:00'),
        'days' => array_map('intval', array_filter(explode(',', (string) env('SNAPSHOTS_DAYS', '1,2,3,4,5,6')))),
    ],

    // The extension counts as connected when it checked in within this many minutes.
    'extension_connected_minutes' => 3,
];
