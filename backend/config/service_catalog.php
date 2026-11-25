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
        'ai_agent' => [
            'label' => 'AI agent (chat or voice)',
            'fields' => [
                ['key' => 'agent_type', 'label' => 'Kind of agent', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'chatbot' => 'Chatbot (Conversation AI)', 'voice' => 'Voice AI (answers or makes calls)', 'both' => 'Chat and voice', 'ai_employee' => 'AI employee / agent studio',
                ]],
                ['key' => 'purpose', 'label' => 'What it should do', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => [
                    'receptionist' => 'Answer questions like a receptionist', 'qualify_leads' => 'Qualify leads', 'book_appointments' => 'Book appointments', 'support' => 'Customer support',
                    'follow_up' => 'Follow up with leads', 'reactivate' => 'Win back old contacts',
                ]],
                ['key' => 'channels', 'label' => 'Where it talks to people', 'type' => 'checkbox', 'ticket' => true, 'options' => [
                    'web_chat' => 'Website chat', 'sms' => 'Text messages', 'facebook' => 'Facebook Messenger', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp', 'phone' => 'Phone calls',
                ]],
                ['key' => 'knowledge', 'label' => 'What it should know from', 'type' => 'checkbox', 'ticket' => false, 'options' => [
                    'website' => 'Our website', 'documents' => 'Documents we provide', 'faq' => 'A list of questions and answers', 'crm_data' => 'Contact and CRM data',
                ]],
                ['key' => 'handoff', 'label' => 'When it cannot help', 'type' => 'select', 'ticket' => false, 'options' => [
                    'transfer' => 'Pass to a person', 'task' => 'Create a task for the team', 'callback' => 'Offer a callback', 'stop' => 'Say it cannot help',
                ]],
                ['key' => 'languages', 'label' => 'Languages', 'type' => 'text', 'max' => 120, 'ticket' => false],
                ['key' => 'reference_url', 'label' => 'Example conversation or script link', 'type' => 'url', 'ticket' => false],
            ],
        ],
        'forms' => [
            'label' => 'Forms / surveys / quizzes',
            'fields' => [
                ['key' => 'form_type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'contact' => 'Contact form', 'application' => 'Application', 'survey' => 'Survey', 'quiz' => 'Quiz', 'order' => 'Order form', 'intake' => 'Client intake',
                ]],
                ['key' => 'fields', 'label' => 'What it asks (short list)', 'type' => 'text', 'max' => 300, 'ticket' => true],
                ['key' => 'after_submit', 'label' => 'After it is submitted', 'type' => 'checkbox', 'ticket' => true, 'options' => [
                    'thank_you' => 'Thank-you page or message', 'create_contact' => 'Create or update the contact', 'start_workflow' => 'Start a workflow', 'notify_team' => 'Notify the team', 'redirect' => 'Send them to another page',
                ]],
                ['key' => 'embed_url', 'label' => 'Where it will be embedded', 'type' => 'url', 'ticket' => false],
            ],
        ],
        'sms_compliance' => [
            'label' => 'SMS setup and A2P compliance',
            'fields' => [
                ['key' => 'registration', 'label' => 'Registration needed', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'a2p_10dlc' => 'A2P 10DLC (US long numbers)', 'toll_free' => 'Toll-free verification', 'short_code' => 'Short code', 'not_sure' => 'Not sure, please advise',
                ]],
                ['key' => 'use_case', 'label' => 'What the messages are for', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => [
                    'marketing' => 'Marketing', 'reminders' => 'Appointment reminders', 'support' => 'Customer service', 'alerts' => 'Account alerts',
                ]],
                ['key' => 'consent', 'label' => 'Consent wording', 'type' => 'select', 'ticket' => false, 'options' => ['have' => 'We have it', 'need' => 'Please write it for us']],
                ['key' => 'business_website', 'label' => 'Business website', 'type' => 'url', 'ticket' => false],
            ],
        ],
        'reputation' => [
            'label' => 'Reputation and reviews',
            'fields' => [
                ['key' => 'platforms', 'label' => 'Review sites', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => [
                    'google' => 'Google', 'facebook' => 'Facebook', 'yelp' => 'Yelp', 'trustpilot' => 'Trustpilot', 'other' => 'Other',
                ]],
                ['key' => 'ask_via', 'label' => 'Ask customers by', 'type' => 'checkbox', 'ticket' => true, 'options' => ['sms' => 'Text message', 'email' => 'Email']],
                ['key' => 'widget', 'label' => 'Show reviews on the website', 'type' => 'select', 'ticket' => false, 'options' => ['yes' => 'Yes', 'no' => 'No']],
                ['key' => 'auto_reply', 'label' => 'Reply to reviews', 'type' => 'select', 'ticket' => false, 'options' => ['we_reply' => 'Our team replies', 'ai_reply' => 'Suggest replies with AI', 'none' => 'No replies']],
            ],
        ],
        'phone' => [
            'label' => 'Phone and voice',
            'fields' => [
                ['key' => 'features', 'label' => 'What you need', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => [
                    'call_tracking' => 'Call tracking', 'missed_call_text_back' => 'Missed-call text-back', 'ivr' => 'Phone menu (IVR)', 'call_recording' => 'Call recording',
                    'call_routing' => 'Call routing to people', 'voicemail' => 'Voicemail handling',
                ]],
                ['key' => 'numbers', 'label' => 'Numbers', 'type' => 'select', 'ticket' => false, 'options' => ['existing' => 'Use our existing numbers', 'new' => 'Buy new numbers', 'port' => 'Port our numbers']],
                ['key' => 'hours', 'label' => 'Opening hours for routing', 'type' => 'text', 'max' => 200, 'ticket' => false],
            ],
        ],
        'payments' => [
            'label' => 'Payments, products and invoices',
            'fields' => [
                ['key' => 'processor', 'label' => 'Payment processor', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'stripe' => 'Stripe', 'paypal' => 'PayPal', 'authorize_net' => 'Authorize.net', 'other' => 'Other', 'not_sure' => 'Not sure',
                ]],
                ['key' => 'items', 'label' => 'What to set up', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => [
                    'products' => 'Products and prices', 'order_form' => 'Order forms', 'payment_links' => 'Payment links', 'invoices' => 'Invoices', 'subscriptions' => 'Subscriptions', 'coupons' => 'Coupons',
                ]],
                ['key' => 'how_many', 'label' => 'How many products or plans', 'type' => 'text', 'max' => 80, 'ticket' => false],
            ],
        ],
        'membership' => [
            'label' => 'Memberships and courses',
            'fields' => [
                ['key' => 'kind', 'label' => 'What it is', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => ['course' => 'Online course', 'membership' => 'Paid membership', 'free_access' => 'Free members area', 'community_access' => 'Community access']],
                ['key' => 'structure', 'label' => 'Structure (modules, lessons, levels)', 'type' => 'text', 'max' => 300, 'ticket' => true],
                ['key' => 'release', 'label' => 'Content release', 'type' => 'select', 'ticket' => false, 'options' => ['all_at_once' => 'All at once', 'drip' => 'Drip over time']],
                ['key' => 'payment', 'label' => 'Payment', 'type' => 'select', 'ticket' => false, 'options' => ['one_time' => 'One-time', 'subscription' => 'Subscription', 'free' => 'Free']],
            ],
        ],
        'community' => [
            'label' => 'Communities',
            'fields' => [
                ['key' => 'purpose', 'label' => 'What the community is for', 'type' => 'text', 'required' => true, 'max' => 300, 'ticket' => true],
                ['key' => 'groups', 'label' => 'Groups or channels', 'type' => 'text', 'max' => 300, 'ticket' => true],
                ['key' => 'moderation', 'label' => 'Moderation', 'type' => 'select', 'ticket' => false, 'options' => ['we_moderate' => 'We moderate', 'you_moderate' => 'You moderate', 'open' => 'Open']],
            ],
        ],
        'social' => [
            'label' => 'Social planner',
            'fields' => [
                ['key' => 'platforms', 'label' => 'Accounts', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => [
                    'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'google_business' => 'Google Business Profile', 'tiktok' => 'TikTok', 'x' => 'X',
                ]],
                ['key' => 'content', 'label' => 'Content', 'type' => 'select', 'ticket' => false, 'options' => ['we_create' => 'We create it', 'you_provide' => 'You provide it', 'both' => 'Both']],
                ['key' => 'frequency', 'label' => 'How often', 'type' => 'text', 'max' => 120, 'ticket' => false],
            ],
        ],
        'chat_widget' => [
            'label' => 'Website chat widget',
            'fields' => [
                ['key' => 'channels', 'label' => 'Channels in the widget', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => ['chat' => 'Live chat', 'sms' => 'Text us', 'form' => 'Contact form', 'booking' => 'Book a call']],
                ['key' => 'site_url', 'label' => 'Website to install it on', 'type' => 'url', 'ticket' => true],
                ['key' => 'hours', 'label' => 'When it is online', 'type' => 'text', 'max' => 200, 'ticket' => false],
            ],
        ],
        'migration' => [
            'label' => 'Migration to GoHighLevel',
            'fields' => [
                ['key' => 'from', 'label' => 'Moving from', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => [
                    'hubspot' => 'HubSpot', 'activecampaign' => 'ActiveCampaign', 'mailchimp' => 'Mailchimp', 'keap' => 'Keap / Infusionsoft', 'other_crm' => 'Another CRM', 'spreadsheets' => 'Spreadsheets',
                ]],
                ['key' => 'data', 'label' => 'What to move', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => [
                    'contacts' => 'Contacts', 'deals' => 'Deals and pipelines', 'tags' => 'Tags and fields', 'templates' => 'Email templates', 'automations' => 'Automations',
                ]],
                ['key' => 'records', 'label' => 'Roughly how many contacts', 'type' => 'text', 'max' => 60, 'ticket' => false],
                ['key' => 'file_link', 'label' => 'Export file link', 'type' => 'url', 'ticket' => true],
            ],
        ],
        'audit' => [
            'label' => 'Audit and cleanup',
            'fields' => [
                ['key' => 'areas', 'label' => 'What to look at', 'type' => 'checkbox', 'required' => true, 'ticket' => true, 'options' => [
                    'workflows' => 'Workflows', 'pipelines' => 'Pipelines', 'funnels' => 'Funnels and sites', 'forms' => 'Forms', 'calendars' => 'Calendars', 'integrations' => 'Integrations', 'deliverability' => 'Email and SMS deliverability',
                ]],
                ['key' => 'problem', 'label' => 'What is going wrong', 'type' => 'text', 'max' => 300, 'ticket' => true],
                ['key' => 'account_url', 'label' => 'Sub-account link', 'type' => 'url', 'ticket' => false],
            ],
        ],
        'training' => [
            'label' => 'Training and handover',
            'fields' => [
                ['key' => 'format', 'label' => 'Format', 'type' => 'select', 'required' => true, 'ticket' => true, 'options' => ['live' => 'Live session', 'loom' => 'Recorded videos', 'docs' => 'Written guide', 'mixed' => 'A mix']],
                ['key' => 'audience', 'label' => 'Who it is for', 'type' => 'text', 'max' => 200, 'ticket' => true],
                ['key' => 'topics', 'label' => 'Topics', 'type' => 'text', 'max' => 300, 'ticket' => true],
            ],
        ],
        'retainer' => [
            'label' => 'Ongoing support retainer',
            'fields' => [
                ['key' => 'hours_per_month', 'label' => 'Hours per month', 'type' => 'text', 'required' => true, 'max' => 40, 'ticket' => false],
                ['key' => 'focus', 'label' => 'What it covers', 'type' => 'checkbox', 'required' => true, 'ticket' => false, 'options' => [
                    'maintenance' => 'Keeping things working', 'new_builds' => 'New builds', 'support' => 'Answering questions', 'reporting' => 'Monthly reporting', 'optimisation' => 'Improving results',
                ]],
                ['key' => 'priority_support', 'label' => 'Priority response', 'type' => 'select', 'ticket' => false, 'options' => ['yes' => 'Yes', 'no' => 'No']],
            ],
        ],        'other' => [
            'label' => 'Something else',
            'fields' => [],
        ],
    ],

    /*
    | How the new-project screen groups and describes the services. `group` is a key of `groups`; `hours` is a rough
    | range of hours shown as a hint (never a price or a promise).
    */
    'groups' => [
        'foundation' => 'Foundation',
        'automation' => 'Automation and AI',
        'marketing' => 'Marketing',
        'booking_phone' => 'Booking and phone',
        'money_delivery' => 'Payments, courses and reporting',
        'people' => 'People',
        'other' => 'Other',
    ],

    'services_meta' => [
        'snapshot' => ['group' => 'foundation', 'description' => 'A new sub-account, a snapshot to reuse, or deploying one to a client.', 'hours' => [3, 15]],
        'crm' => ['group' => 'foundation', 'description' => 'Contacts, custom fields, tags, smart lists and pipelines with stages.', 'hours' => [3, 12]],
        'migration' => ['group' => 'foundation', 'description' => 'Move contacts, deals and templates from another platform.', 'hours' => [4, 30]],
        'audit' => ['group' => 'foundation', 'description' => 'Find and fix what is broken or messy in an existing account.', 'hours' => [3, 15]],
        'workflow' => ['group' => 'automation', 'description' => 'Automations that follow up, notify and move people through your process.', 'hours' => [2, 20]],
        'ai_agent' => ['group' => 'automation', 'description' => 'Conversation AI chatbots, Voice AI and AI employees that answer, qualify and book.', 'hours' => [6, 40]],
        'integration' => ['group' => 'automation', 'description' => 'Connect GoHighLevel to other tools with Zapier, Make, n8n, webhooks or the API.', 'hours' => [2, 20]],
        'funnel' => ['group' => 'marketing', 'description' => 'Funnels and websites: lead pages, booking pages, sales pages and full sites.', 'hours' => [4, 40]],
        'forms' => ['group' => 'marketing', 'description' => 'Forms, surveys and quizzes that feed your CRM.', 'hours' => [1, 8]],
        'campaign' => ['group' => 'marketing', 'description' => 'Email and SMS campaigns, newsletters and templates.', 'hours' => [2, 15]],
        'sms_compliance' => ['group' => 'marketing', 'description' => 'Number setup and A2P or toll-free registration so texts are delivered.', 'hours' => [1, 6]],
        'social' => ['group' => 'marketing', 'description' => 'Connect accounts and schedule posts with the social planner.', 'hours' => [2, 12]],
        'reputation' => ['group' => 'marketing', 'description' => 'Ask for reviews, collect them and show them.', 'hours' => [2, 10]],
        'calendar' => ['group' => 'booking_phone', 'description' => 'Booking calendars with availability, reminders and round-robin.', 'hours' => [1, 8]],
        'phone' => ['group' => 'booking_phone', 'description' => 'Call tracking, missed-call text-back, phone menus and routing.', 'hours' => [2, 12]],
        'chat_widget' => ['group' => 'booking_phone', 'description' => 'A chat and booking widget on your website.', 'hours' => [1, 5]],
        'payments' => ['group' => 'money_delivery', 'description' => 'Stripe or PayPal, products, order forms, invoices and subscriptions.', 'hours' => [2, 15]],
        'membership' => ['group' => 'money_delivery', 'description' => 'Courses and paid or free members areas.', 'hours' => [5, 40]],
        'community' => ['group' => 'money_delivery', 'description' => 'A community with groups and channels.', 'hours' => [2, 12]],
        'reporting' => ['group' => 'money_delivery', 'description' => 'Dashboards and reports that show what is working.', 'hours' => [2, 12]],
        'training' => ['group' => 'people', 'description' => 'Teach your team to use what we built.', 'hours' => [1, 8]],
        'retainer' => ['group' => 'people', 'description' => 'A set number of hours every month for upkeep and improvements.', 'hours' => [5, 60]],
        'other' => ['group' => 'other', 'description' => 'Something that does not fit above. Tell us what you need.', 'hours' => null],
    ],

    // Ready-made bundles. They only tick services; every one stays editable.
    'packages' => [
        ['key' => 'lead_capture', 'label' => 'Lead-capture starter', 'description' => 'Get leads in, tracked and followed up.', 'services' => ['crm', 'funnel', 'forms', 'workflow']],
        ['key' => 'appointment_booking', 'label' => 'Appointment-booking system', 'description' => 'People book themselves and get reminders.', 'services' => ['calendar', 'forms', 'workflow', 'sms_compliance']],
        ['key' => 'reputation', 'label' => 'Reputation and reviews', 'description' => 'Ask happy customers for reviews automatically.', 'services' => ['reputation', 'workflow', 'campaign']],
        ['key' => 'ai_receptionist', 'label' => 'AI receptionist', 'description' => 'An AI that answers, qualifies and books, day and night.', 'services' => ['ai_agent', 'calendar', 'phone', 'workflow']],
        ['key' => 'full_automation', 'label' => 'Full automation package', 'description' => 'The whole system: CRM, funnel, forms, workflows, calendar, campaigns, AI agent, integrations, reporting and training.', 'services' => ['snapshot', 'crm', 'funnel', 'forms', 'workflow', 'calendar', 'campaign', 'ai_agent', 'integration', 'reporting', 'training']],
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
