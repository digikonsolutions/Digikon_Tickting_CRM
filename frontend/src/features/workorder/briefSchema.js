// The GoHighLevel project brief as data: sections, fields and how each is drawn. The form (BriefForm), the read view
// (BriefView) and the completeness estimate all read this one list, so adding a field means adding one line here and
// one rule in backend/app/Support/GhlBrief.php. Field `path` is the path inside the section's API object.
//
// type: text | email | tel | url | textarea | date | select | bool | chips | urls | multi | rows
// copy: show a Copy button in the read view (ids and links people paste into GoHighLevel).

const tri = [{ value: 'unknown', label: 'Not sure' }, { value: 'not_set', label: 'Not set up' }, { value: 'partial', label: 'Partly set up' }, { value: 'pass', label: 'Passing' }]

export const CATEGORIES = [
  ['funnel', 'Funnel'], ['website', 'Website'], ['workflow', 'Workflow'], ['crm_setup', 'CRM setup'], ['pipeline', 'Pipeline'],
  ['email_sms_campaign', 'Email or SMS campaign'], ['reputation', 'Reputation'], ['calendar', 'Calendar'], ['forms_surveys', 'Forms and surveys'],
  ['membership', 'Membership'], ['reporting', 'Reporting'], ['integration', 'Integration'], ['migration', 'Migration'], ['audit_fix', 'Audit or fix'], ['other', 'Other'],
].map(([value, label]) => ({ value, label }))

const choice = (...pairs) => pairs.map(([value, label]) => ({ value, label }))

export const BRIEF_SECTIONS = [
  { key: 'client', title: 'Client and contact', fields: [
    { path: 'name', label: 'Name', type: 'text', max: 120 }, { path: 'email', label: 'Email', type: 'email' }, { path: 'phone', label: 'Phone', type: 'tel' },
    { path: 'company', label: 'Company', type: 'text', max: 160 }, { path: 'website', label: 'Website', type: 'url' }, { path: 'role', label: 'Role', type: 'text', max: 120 },
    { path: 'preferred_contact', label: 'Preferred contact method', type: 'select', options: choice(['email', 'Email'], ['phone', 'Phone'], ['whatsapp', 'WhatsApp'], ['sms', 'SMS']) },
    { path: 'timezone', label: 'Timezone', type: 'text', max: 60, help: 'For example America/New_York' }, { path: 'business_hours', label: 'Business hours', type: 'text', max: 200 },
    { path: 'secondary_contact.name', label: 'Secondary contact name', type: 'text', max: 120 }, { path: 'secondary_contact.email', label: 'Secondary contact email', type: 'email' }, { path: 'secondary_contact.phone', label: 'Secondary contact phone', type: 'tel' },
    { path: 'address', label: 'Address', type: 'textarea', max: 500 }, { path: 'ghl_contact_id', label: 'GoHighLevel contact ID', type: 'text', max: 80, copy: true },
  ] },
  { key: 'ghl', title: 'GoHighLevel account', fields: [
    { path: 'subaccount_name', label: 'Sub-account name', type: 'text', max: 160 }, { path: 'location_id', label: 'Location ID', type: 'text', max: 80, copy: true },
    { path: 'access_level', label: 'Access level', type: 'select', options: choice(['agency', 'Agency'], ['sub_account', 'Sub-account only']) }, { path: 'plan_tier', label: 'Plan tier', type: 'text', max: 60 },
    { path: 'white_label_domain', label: 'White-label domain', type: 'text', max: 255, copy: true },
    { path: 'access_method', label: 'How we get access', type: 'select', options: choice(['invite', 'Invite to the sub-account'], ['user_created', 'You create a user for us'], ['api_key', 'API key'], ['other', 'Other']) },
    { path: 'admin_holder', label: 'Who holds admin access', type: 'text', max: 120 }, { path: 'credentials_shared_via', label: 'Credentials shared via', type: 'text', max: 200, help: 'How you will share access (for example "password manager invite"). Never type a password or API key here.' },
    { path: 'existing_snapshot', label: 'Existing snapshot', type: 'text', max: 160 },
    { path: 'build_type', label: 'New build or cleanup', type: 'select', options: choice(['new_build', 'New build'], ['cleanup', 'Cleanup of an existing account']) },
  ] },
  { key: 'scope', title: 'Scope', fields: [
    { path: 'categories', label: 'What kind of work', type: 'multi', options: CATEGORIES }, { path: 'goal', label: 'Goal', type: 'textarea', max: 3000 },
    { path: 'kpis', label: 'KPIs', type: 'chips' }, { path: 'deliverables', label: 'Deliverables', type: 'chips' }, { path: 'out_of_scope', label: 'Out of scope', type: 'textarea', max: 3000 },
    { path: 'launch_date', label: 'Launch date', type: 'date' }, { path: 'deadline_flexibility', label: 'Deadline', type: 'select', options: choice(['fixed', 'Fixed'], ['flexible', 'Flexible'], ['asap', 'As soon as possible']) },
    { path: 'priority', label: 'Priority', type: 'select', options: choice(['low', 'Low'], ['normal', 'Normal'], ['high', 'High'], ['urgent', 'Urgent']) },
    { path: 'budget_range', label: 'Budget range', type: 'text', max: 100 }, { path: 'billing_type', label: 'Billing', type: 'select', options: choice(['fixed', 'Fixed price'], ['hourly', 'Hourly'], ['retainer', 'Retainer']) },
  ] },
  { key: 'pipelines', title: 'Pipelines and opportunities', rows: true, fields: [
    { path: 'name', label: 'Pipeline name', type: 'text', max: 160 }, { path: 'ghl_id', label: 'GoHighLevel ID', type: 'text', max: 80, copy: true },
    { path: 'stages', label: 'Stages, in order', type: 'chips', max: 50 }, { path: 'sources', label: 'Sources', type: 'chips', max: 30 }, { path: 'lost_reasons', label: 'Lost reasons', type: 'chips', max: 30 },
    { path: 'value_rules', label: 'Value rules', type: 'textarea', max: 1000 },
  ], rowTitle: 'Pipeline', addLabel: 'Add pipeline', maxRows: 20 },
  { key: 'data', title: 'Contacts and data', fields: [
    { path: 'tags', label: 'Tags', type: 'chips', max: 100 }, { path: 'smart_lists', label: 'Smart lists', type: 'chips', max: 30 }, { path: 'lead_sources', label: 'Lead sources', type: 'chips', max: 30 },
    { path: 'custom_fields', label: 'Custom fields', type: 'rows', max: 100, addLabel: 'Add custom field', sub: [
      { path: 'name', label: 'Name', type: 'text', max: 120 }, { path: 'type', label: 'Type', type: 'text', max: 40 }, { path: 'options', label: 'Options', type: 'chips', max: 50 },
    ] },
    { path: 'custom_values', label: 'Custom values', type: 'rows', max: 50, addLabel: 'Add custom value', sub: [{ path: 'key', label: 'Key', type: 'text', max: 120 }, { path: 'value', label: 'Value', type: 'text', max: 500 }] },
    { path: 'import.source', label: 'Import or migration source', type: 'text', max: 160 }, { path: 'import.record_count', label: 'Number of records', type: 'number' }, { path: 'import.file_link', label: 'File link', type: 'url', copy: true },
    { path: 'dnd_consent', label: 'Do-not-disturb and consent', type: 'textarea', max: 1000 },
  ] },
  { key: 'automations', title: 'Automations', fields: [
    { path: 'triggers', label: 'Triggers', type: 'chips', max: 30 }, { path: 'actions', label: 'Actions', type: 'chips', max: 50 },
    { path: 'branching', label: 'Branching', type: 'textarea', max: 2000 }, { path: 'wait_rules', label: 'Wait rules', type: 'textarea', max: 1000 },
    { path: 'internal_notifications', label: 'Internal notifications', type: 'textarea', max: 1000 }, { path: 'assignment_rules', label: 'Assignment rules', type: 'textarea', max: 1000 },
    { path: 'existing_workflows', label: 'Existing workflows to change', type: 'rows', max: 50, addLabel: 'Add workflow', sub: [
      { path: 'name', label: 'Name', type: 'text', max: 160 }, { path: 'ghl_id', label: 'ID', type: 'text', max: 80, copy: true }, { path: 'url', label: 'Link', type: 'url', copy: true },
    ] },
  ] },
  { key: 'funnels', title: 'Funnels, websites and forms', fields: [
    { path: 'pages', label: 'Pages', type: 'rows', max: 50, addLabel: 'Add page', sub: [{ path: 'name', label: 'Page', type: 'text', max: 160 }, { path: 'purpose', label: 'Purpose', type: 'text', max: 300 }] },
    { path: 'domain', label: 'Domain or subdomain', type: 'text', max: 255, copy: true }, { path: 'dns_owner', label: 'Who controls DNS', type: 'select', options: choice(['client', 'Client'], ['agency', 'Agency'], ['unknown', 'Not sure']) },
    { path: 'pixels.facebook', label: 'Facebook pixel ID', type: 'text', max: 80, copy: true }, { path: 'pixels.ga4', label: 'GA4 measurement ID', type: 'text', max: 80, copy: true }, { path: 'pixels.gtm', label: 'GTM container ID', type: 'text', max: 80, copy: true },
    { path: 'seo', label: 'SEO notes', type: 'textarea', max: 1000 }, { path: 'inspiration_urls', label: 'Inspiration links', type: 'urls', max: 10 },
    { path: 'copy_status', label: 'Copy', type: 'select', options: choice(['provided', 'Client provides it'], ['needed', 'We need to write it']) },
    { path: 'form_fields', label: 'Form fields', type: 'chips', max: 50 }, { path: 'thank_you', label: 'Thank-you flow', type: 'textarea', max: 1000 },
  ] },
  { key: 'communications', title: 'Communications', fields: [
    { path: 'email.sending_domain', label: 'Sending domain', type: 'text', max: 255, copy: true },
    { path: 'email.dkim', label: 'DKIM', type: 'select', options: tri }, { path: 'email.spf', label: 'SPF', type: 'select', options: tri }, { path: 'email.dmarc', label: 'DMARC', type: 'select', options: tri },
    { path: 'email.from_name', label: 'From name', type: 'text', max: 120 }, { path: 'email.from_address', label: 'From address', type: 'email' }, { path: 'email.templates', label: 'Email templates', type: 'textarea', max: 1000 },
    { path: 'sms.provider', label: 'SMS provider', type: 'text', max: 80 },
    { path: 'sms.a2p_status', label: 'A2P 10DLC status', type: 'select', options: choice(['not_started', 'Not started'], ['pending', 'Pending'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['not_needed', 'Not needed']) },
    { path: 'sms.numbers', label: 'Sender numbers', type: 'chips', max: 20 }, { path: 'sms.consent_wording', label: 'Consent wording', type: 'textarea', max: 1000 },
    { path: 'call.missed_call_text_back', label: 'Missed-call text-back', type: 'bool' }, { path: 'call.call_tracking', label: 'Call tracking', type: 'text', max: 300 }, { path: 'call.ivr', label: 'IVR', type: 'textarea', max: 1000 },
    { path: 'social.google_business', label: 'Google Business Profile', type: 'text', max: 300 }, { path: 'social.meta', label: 'Meta (Facebook, Instagram)', type: 'text', max: 300 }, { path: 'social.other', label: 'Other social accounts', type: 'text', max: 300 },
  ] },
  { key: 'calendars', title: 'Calendars and booking', fields: [
    { path: 'types', label: 'Calendar types', type: 'chips', max: 20 }, { path: 'team_members', label: 'Team members', type: 'chips', max: 30 },
    { path: 'availability', label: 'Availability', type: 'textarea', max: 500 }, { path: 'buffers', label: 'Buffers', type: 'text', max: 200 }, { path: 'reminders', label: 'Reminders', type: 'textarea', max: 500 },
    { path: 'round_robin', label: 'Round-robin', type: 'bool' }, { path: 'meeting_links', label: 'Meeting links', type: 'urls', max: 10 },
  ] },
  { key: 'payments', title: 'Payments and integrations', fields: [
    { path: 'processor', label: 'Payment processor', type: 'select', options: choice(['stripe', 'Stripe'], ['paypal', 'PayPal'], ['other', 'Other'], ['none', 'None']) },
    { path: 'products', label: 'Products and prices', type: 'rows', max: 50, addLabel: 'Add product', sub: [{ path: 'name', label: 'Product', type: 'text', max: 160 }, { path: 'price', label: 'Price', type: 'text', max: 40 }] },
    { path: 'invoices', label: 'Invoices', type: 'textarea', max: 500 }, { path: 'memberships', label: 'Memberships', type: 'textarea', max: 500 },
    { path: 'integrations', label: 'Zapier, Make and webhooks', type: 'chips', max: 30 }, { path: 'connected_accounts', label: 'Connected accounts', type: 'chips', max: 30 }, { path: 'tools', label: 'Other tools', type: 'chips', max: 30 },
  ] },
  { key: 'branding', title: 'Branding and assets', fields: [
    { path: 'logo_url', label: 'Logo link', type: 'url', copy: true }, { path: 'colors', label: 'Brand colors', type: 'chips', max: 12, help: 'Hex codes, for example #0d9488' }, { path: 'fonts', label: 'Fonts', type: 'chips', max: 10 },
    { path: 'guideline_url', label: 'Brand guideline link', type: 'url', copy: true }, { path: 'asset_folder_url', label: 'Asset folder link', type: 'url', copy: true },
    { path: 'tone_of_voice', label: 'Tone of voice', type: 'textarea', max: 500 }, { path: 'competitor_urls', label: 'Competitor links', type: 'urls', max: 10 },
  ] },
  { key: 'handover', title: 'Reporting and handover', fields: [
    { path: 'dashboards', label: 'Dashboards wanted', type: 'chips', max: 20 }, { path: 'cadence', label: 'Reporting cadence', type: 'text', max: 160 },
    { path: 'training', label: 'Training', type: 'select', options: choice(['loom', 'Recorded video (Loom)'], ['live', 'Live session'], ['none', 'None']) },
    { path: 'documentation', label: 'Documentation', type: 'textarea', max: 500 }, { path: 'post_launch_support', label: 'Post-launch support', type: 'textarea', max: 500 },
  ] },
  { key: 'notes', title: 'Notes', fields: [{ path: 'client', label: 'Notes for the team', type: 'textarea', max: 5000, help: 'Visible to you, your team and the employee on this project.' }] },
]

// Mirrors GhlBrief::RECOMMENDED on the server (which is the source of truth for the saved percentage).
export const RECOMMENDED = [
  'client.name', 'client.email', 'client.timezone', 'ghl.location_id', 'ghl.access_method', 'ghl.admin_holder', 'scope.categories', 'scope.goal',
  'scope.deliverables', 'scope.launch_date', 'scope.out_of_scope', 'branding.asset_folder_url', 'branding.tone_of_voice', 'handover.training', 'handover.post_launch_support',
]

export const SECTION_BY_KEY = Object.fromEntries(BRIEF_SECTIONS.map((section) => [section.key, section]))
