<?php

/*
 * The single source of truth for employee work-activity monitoring. The consent notice, the invite
 * email and ActivityLogger all read this file: a category that is not listed here is never recorded.
 *
 * Change `version` whenever the text or the categories change. Employees are then asked to accept
 * the new notice before anything more is recorded about them.
 *
 * `:idle_minutes` and `:retention_days` are filled in from config/activity.php.
 */
$snapshots = filter_var(env('SNAPSHOTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN);

return [
    'version' => $snapshots ? '3.0' : '2.1',

    'title' => 'Work activity notice',

    'summary' => 'While you are signed in, ClientHub records the following about your work so that attendance and progress can be reviewed.',

    'categories' => [
        'sign_ins' => [
            'label' => 'Sign-ins and sign-outs',
            'description' => 'When you sign in to and out of ClientHub.',
        ],
        'work_sessions' => [
            'label' => 'Work sessions and active time',
            'description' => 'When a work session starts and ends, and how long the app was open and in use.',
        ],
        'idle_status' => [
            'label' => 'Idle periods',
            'description' => 'That the open app saw no mouse, keyboard, scroll or touch interaction for :idle_minutes minutes. Only a yes or no is sent, never which key was pressed or where you clicked.',
        ],
        'ticket_activity' => [
            'label' => 'Ticket updates',
            'description' => 'Ticket status changes, and the fact that you added a comment (never the comment text).',
        ],
        'project_activity' => [
            'label' => 'Project status changes',
            'description' => 'Project status changes caused by your ticket updates.',
        ],
        'connection' => [
            'label' => 'Connection details',
            'description' => 'The IP address and browser name recorded with each event above.',
        ],
        'browser_history' => [
            'label' => 'Browser tab history',
            'description' => 'If you install the ClientHub browser extension, and only while you have a ticket or project in progress: when browser tabs are opened, closed, switched or navigated, with the website address (without anything after the "?" or "#"), page title and time spent, linked to that ticket. Nothing is kept outside that time or from private windows. The app shows when recording is on. This is visible to ClientHub administrators only, who can read it but not change it. The extension has an on/off switch, and nothing is sent until you accept this notice.',
        ],
        // Only present when snapshots are switched on, so the notice always matches what is recorded.
        ...($snapshots ? ['work_snapshots' => [
            'label' => 'Work snapshots',
            'description' => 'If you install the ClientHub browser extension, and only while you have a ticket or project in progress: a small picture of the browser tab in front, on any website, taken every few minutes at a random time during working hours (:snapshot_hours). Nothing is taken when you are idle, in a private window, or outside working hours. The toolbar icon shows when a picture is taken. Pictures show what is on the page, so avoid opening personal accounts while you work. They are visible to ClientHub administrators only, are linked to your ticket when one is in progress, and are deleted after :snapshot_days days.',
        ]] : []),
    ],

    'not_collected' => [
        'Keystrokes or anything you type',
        $snapshots ? 'Pictures of other applications or of your whole screen, or any recording outside working hours' : 'Screenshots or screen recordings',
        'Page content, passwords, form inputs, or the text of your comments',
        'Camera, microphone or location',
    ],

    'viewers' => 'ClientHub administrators, and the owner of the agency you are currently assigned to (only for the time you work for that agency, and not including browser tab history, which is for administrators only). You cannot see this data in your own account.',

    'retention' => 'Records are kept for :retention_days days and then deleted.',
];
