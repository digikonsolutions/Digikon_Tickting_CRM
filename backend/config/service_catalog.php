<?php

/*
| The GoHighLevel (GHL) services an agency can order, in one place. The create-project and create-ticket forms are
| drawn from this list, and the same list validates what they send (App\Support\ServiceCatalog), so adding a service
| or a field here is all it takes.
|
| A type has a label and a list of fields. A field is:
|   key       stored under `details.<key>` (letters, digits and underscores)
|   label     what the person sees
|   type      select | checkbox (several of the options) | url | text (short, one line)
|   options   [value => label] for select and checkbox
|   required  must be answered when a project is created (tickets never require anything)
|   ticket    also asked on the lighter ticket form
| Every type also gets an optional "anything else" box (`details.notes`).
*/

return [
    'types' => [
        'workflow' => [
            'label' => 'Workflow / automation',
            'fields' => [
                ['key' => 'trigger', 'label' => 'What starts it', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'form_submitted' => 'A form is submitted', 'tag_added' => 'A tag is added', 'stage_changed' => 'A pipeline stage changes',
                    'appointment_booked' => 'An appointment is booked', 'missed_call' => 'A call is missed', 'manual' => 'Added manually', 'other' => 'Something else',
                ]],
                ['key' => 'actions', 'label' => 'What it should do', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => [
                    'send_email' => 'Send an email', 'send_sms' => 'Send a text message', 'add_tag' => 'Add or remove tags', 'update_opportunity' => 'Move or update an opportunity',
                    'notify_team' => 'Notify the team', 'wait_step' => 'Wait or follow up later', 'webhook' => 'Call another system',
                ]],
                ['key' => 'goal', 'label' => 'Goal in a few words', 'type' => 'text', 'max' => 200, 'ticket' => false],
                ['key' => 'reference_url', 'label' => 'Example or reference link', 'type' => 'url', 'ticket' => false],
            ],
        ],
        'funnel' => [
            'label' => 'Funnel / website',
            'fields' => [
                ['key' => 'funnel_type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'lead_gen' => 'Lead generation', 'booking' => 'Booking', 'webinar' => 'Webinar', 'sales' => 'Sales page', 'ecommerce' => 'Online store', 'membership' => 'Membership', 'website' => 'Website',
                ]],
                ['key' => 'pages', 'label' => 'Pages (how many, which)', 'type' => 'text', 'max' => 200, 'ticket' => false],
                ['key' => 'copy', 'label' => 'Copy and images', 'type' => 'select', 'ticket' => false, 'options' => ['provided' => 'We will provide them', 'needed' => 'Please create them']],
                ['key' => 'connects', 'label' => 'Needs to connect to', 'type' => 'checkbox', 'ticket' => true, 'options' => [
                    'payments' => 'Payments', 'calendar' => 'A calendar', 'email_sms' => 'Email / text follow-up', 'tracking' => 'Tracking pixels',
                ]],
                ['key' => 'live_url', 'label' => 'Current or live page', 'type' => 'url', 'ticket' => true],
                ['key' => 'brand_assets_url', 'label' => 'Brand assets link', 'type' => 'url', 'ticket' => false],
            ],
        ],
        'crm' => [
            'label' => 'CRM / pipelines',
            'fields' => [
                ['key' => 'pipeline_type', 'label' => 'Pipeline for', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'sales' => 'Sales', 'onboarding' => 'Client onboarding', 'support' => 'Support', 'recruitment' => 'Recruitment', 'custom' => 'Something custom',
                ]],
                ['key' => 'stages', 'label' => 'Stages (comma separated)', 'type' => 'text', 'max' => 300, 'ticket' => false],
                ['key' => 'needs', 'label' => 'Also needs', 'type' => 'checkbox', 'ticket' => true, 'options' => [
                    'custom_fields' => 'Custom fields', 'tags' => 'Tags', 'smart_lists' => 'Smart lists', 'import_contacts' => 'Contact import', 'lead_scoring' => 'Lead scoring',
                ]],
            ],
        ],
        'calendar' => [
            'label' => 'Calendar / booking',
            'fields' => [
                ['key' => 'calendar_type', 'label' => 'Calendar type', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'service' => 'One service, one person', 'round_robin' => 'Round robin', 'collective' => 'Team (collective)', 'event' => 'Class or event',
                ]],
                ['key' => 'reminders', 'label' => 'Reminders', 'type' => 'checkbox', 'ticket' => true, 'options' => ['email' => 'Email', 'sms' => 'Text message']],
                ['key' => 'payment', 'label' => 'Take payment when booking', 'type' => 'select', 'ticket' => false, 'options' => ['no' => 'No', 'yes' => 'Yes']],
                ['key' => 'booking_url', 'label' => 'Where it will be embedded', 'type' => 'url', 'ticket' => false],
            ],
        ],
        'campaign' => [
            'label' => 'Email / SMS campaign',
            'fields' => [
                ['key' => 'campaign_type', 'label' => 'Campaign', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'email' => 'Email', 'sms' => 'Text message', 'email_sms' => 'Email and text', 'nurture' => 'Nurture sequence', 'reactivation' => 'Reactivation', 'newsletter' => 'Newsletter',
                ]],
                ['key' => 'audience', 'label' => 'Who it goes to', 'type' => 'text', 'max' => 200, 'ticket' => true],
                ['key' => 'launch', 'label' => 'Launch', 'type' => 'select', 'ticket' => false, 'options' => ['asap' => 'As soon as ready', 'scheduled' => 'On a set date']],
                ['key' => 'reference_url', 'label' => 'Example or reference link', 'type' => 'url', 'ticket' => false],
            ],
        ],
        'integration' => [
            'label' => 'Integration',
            'fields' => [
                ['key' => 'system', 'label' => 'Connect to which tool', 'type' => 'text', 'required' => true, 'max' => 120, 'ticket' => true],
                ['key' => 'method', 'label' => 'How', 'type' => 'select', 'ticket' => true, 'options' => [
                    'native' => 'Built-in connection', 'zapier' => 'Zapier', 'make' => 'Make', 'webhook' => 'Webhook', 'api' => 'API', 'unsure' => 'Not sure',
                ]],
                ['key' => 'direction', 'label' => 'Data flows', 'type' => 'select', 'ticket' => false, 'options' => ['in' => 'Into GHL', 'out' => 'Out of GHL', 'both' => 'Both ways']],
                ['key' => 'docs_url', 'label' => 'Documentation link', 'type' => 'url', 'ticket' => false],
            ],
        ],
        'reporting' => [
            'label' => 'Reporting / dashboards',
            'fields' => [
                ['key' => 'reports', 'label' => 'What to report on', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => [
                    'leads' => 'Leads', 'appointments' => 'Appointments', 'revenue' => 'Revenue', 'ad_spend' => 'Ad spend', 'pipeline' => 'Pipeline',
                ]],
                ['key' => 'destination', 'label' => 'Where to see it', 'type' => 'select', 'ticket' => false, 'options' => ['ghl' => 'GHL dashboard', 'sheet' => 'Google Sheet', 'looker' => 'Looker Studio']],
                ['key' => 'frequency', 'label' => 'How often', 'type' => 'select', 'ticket' => false, 'options' => ['live' => 'Live', 'weekly' => 'Weekly', 'monthly' => 'Monthly']],
            ],
        ],
        'snapshot' => [
            'label' => 'Snapshot / sub-account',
            'fields' => [
                ['key' => 'action', 'label' => 'What to do', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'create_subaccount' => 'Set up a sub-account', 'build_snapshot' => 'Build a snapshot', 'deploy_snapshot' => 'Deploy a snapshot', 'update_snapshot' => 'Update a snapshot',
                ]],
                ['key' => 'industry', 'label' => 'Industry', 'type' => 'text', 'max' => 120, 'ticket' => false],
                ['key' => 'includes', 'label' => 'Includes', 'type' => 'checkbox', 'ticket' => true, 'options' => [
                    'workflows' => 'Workflows', 'funnels' => 'Funnels', 'pipelines' => 'Pipelines', 'calendars' => 'Calendars', 'forms' => 'Forms', 'custom_values' => 'Custom values',
                ]],
                ['key' => 'subaccount_url', 'label' => 'Sub-account link', 'type' => 'url', 'ticket' => true],
            ],
        ],
        'other' => [
            'label' => 'Something else',
            'fields' => [],
        ],
    ],

    // The "request an employee" form: a role, hours per week and how long.
    'employee_request' => [
        'roles' => [
            'ghl_builder' => 'GHL builder',
            'automation_specialist' => 'Automation specialist',
            'designer' => 'Designer',
            'copywriter' => 'Copywriter',
            'reporting_analyst' => 'Reporting analyst',
        ],
        'max_hours_per_week' => 36,
        'max_duration_weeks' => 52,
    ],
];
