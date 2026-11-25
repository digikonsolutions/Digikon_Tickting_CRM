<?php

// The questions an agency owner answers about an employee. Adding or renaming one is an edit here: the form, the
// validation and the averages all read this file.
return [
    // Days after saving that the owner may still edit. Afterwards the feedback is locked.
    'edit_days' => 7,

    'categories' => [
        'quality' => 'Quality of work',
        'communication' => 'Communication',
        'reliability' => 'Reliability and deadlines',
        'initiative' => 'Initiative and problem solving',
        'ghl_skill' => 'GoHighLevel skill',
    ],

    'would_again' => [
        'yes' => 'Yes, gladly',
        'maybe' => 'Maybe',
        'no' => 'No',
    ],

    'highlights' => [
        'fast_learner' => 'Fast learner',
        'proactive' => 'Proactive',
        'clear_updates' => 'Clear updates',
        'great_with_clients' => 'Great with clients',
        'strong_automations' => 'Strong automations',
        'detail_oriented' => 'Detail oriented',
    ],

    'concerns' => [
        'missed_deadlines' => 'Missed deadlines',
        'slow_replies' => 'Slow replies',
        'needed_guidance' => 'Needed a lot of guidance',
        'quality_issues' => 'Quality issues',
    ],

    // Admins are told about feedback at or below this rating, or with "would not work again".
    'alert_rating' => 2,
];
