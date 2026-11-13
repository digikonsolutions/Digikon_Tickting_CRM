<?php

return [
    // Statuses in which a timer may run (and be started).
    'timer_statuses' => ['in_progress', 'changes_requested'],

    // A running timer is closed at this cap by the hourly `time:stop-stale-timers` command.
    'max_timer_hours' => 12,

    // Manual entries.
    'min_entry_minutes' => 1,
    'max_entry_minutes' => 720,
    'max_days_back' => 14,
    'max_daily_hours' => 16,

    // The specialist may edit or delete their own entry for this long; after that only an admin can.
    'edit_window_hours' => 48,

    // When false, agency users see logged totals only, not the individual entries.
    'agency_sees_entries' => (bool) env('TIME_AGENCY_SEES_ENTRIES', true),
];
