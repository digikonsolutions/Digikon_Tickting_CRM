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
];
