<?php

return [
    // The browser reports "no UI interaction" after this many minutes. Only a yes/no flag is sent:
    // no keystrokes, no screenshots, no page content.
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

    // The extension counts as connected when it checked in within this many minutes.
    'extension_connected_minutes' => 3,
];
