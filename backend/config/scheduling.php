<?php

return [
    // Capacity rules shared by the scheduler, usage reports and the API payloads.
    'daily_capacity_hours' => 6,
    'weekly_capacity_hours' => 36,
    // ISO weekdays that can hold work (1 = Monday ... 6 = Saturday); Sunday is never scheduled.
    'working_days' => [1, 2, 3, 4, 5, 6],
    'non_working_dates' => [],
    'today_cutoff_hour' => 12,
    'max_project_hours' => 180,
    'max_open_projects_per_client' => 3,
    'require_client_approval' => true,

    // Timeline review: new projects and tickets are scheduled provisionally and an admin (or the assigned employee)
    // confirms the dates before work can start. Turn off for local setups and tests.
    'require_timeline_review' => env('SCHEDULING_REQUIRE_TIMELINE_REVIEW', true),
    // Only admins (and the assigned employee, in their own review) set days. Agency owners and clients submit a request
    // with its details and an optional estimate; nothing is scheduled for them. Off = the older behaviour where an
    // agency's own start and due dates are auto-scheduled on save.
    'admin_only_scheduling' => env('SCHEDULING_ADMIN_ONLY', true),
    // How many projects one employee may have in progress at once (they start projects themselves; tickets are optional).
    'max_projects_in_progress' => 1,
    // Placeholder window (days) given to a request that is waiting for its schedule; the admin sets the real due date.
    'provisional_window_days' => 14,
    // Hours before an unconfirmed timeline is reminded about (each reminder is sent once), and before it counts as overdue.
    'review_reminder_hours' => [24, 48],
    'review_overdue_hours' => 24,
];
