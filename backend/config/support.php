<?php

/*
| Digikon support module settings. Nothing here is hard-coded in code: SLA numbers, hours, windows and the id prefix are
| read from this file (and, once the settings screen exists, from the database rows seeded from it).
| Business hours are minutes in a day; times are in SUPPORT_TIMEZONE.
*/

return [
    'ticket_prefix' => env('TICKET_ID_PREFIX', 'DG'),
    'first_number' => (int) env('TICKET_FIRST_NUMBER', 1000),

    'timezone' => env('SUPPORT_TIMEZONE', 'Asia/Karachi'),
    'hours' => ['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '18:00'],
    'holidays' => [], // 'Y-m-d' dates

    // Business minutes. "first response" and "resolution" per priority (P1..P4 = urgent, high, normal, low).
    'sla' => [
        'urgent' => ['response' => 60, 'resolution' => 4 * 60],
        'high' => ['response' => 4 * 60, 'resolution' => 9 * 60],
        'normal' => ['response' => 9 * 60, 'resolution' => 3 * 9 * 60],
        'low' => ['response' => 2 * 9 * 60, 'resolution' => 5 * 9 * 60],
    ],
    'at_risk_ratio' => 0.75,
    'pause_while_waiting_for_agency' => true,
    'unassigned_escalation_minutes' => ['default' => 120, 'urgent' => 15],

    'reopen_days' => 14,
    'auto_close_days' => 7,
    'waiting_agency_reminder_business_days' => 2,
    'waiting_agency_stalled_business_days' => 5,

    // Internal priority value <=> what people read.
    'priorities' => [
        'urgent' => ['label' => 'P1 Critical', 'short' => 'P1'],
        'high' => ['label' => 'P2 High', 'short' => 'P2'],
        'normal' => ['label' => 'P3 Normal', 'short' => 'P3'],
        'low' => ['label' => 'P4 Low', 'short' => 'P4'],
    ],

    'services' => ['ghl' => 'GoHighLevel', 'ai' => 'AI', 'n8n_api' => 'n8n / API', 'website_funnel' => 'Website / Funnel', 'other' => 'Other'],

    'resolution_categories' => [
        'fixed' => 'Fixed', 'configuration_updated' => 'Configuration updated', 'information_provided' => 'Information provided', 'user_error' => 'User error',
        'third_party_issue' => 'Third-party issue', 'duplicate' => 'Duplicate', 'out_of_scope' => 'Out of scope', 'converted_to_project' => 'Converted to project', 'no_issue_found' => 'No issue found',
    ],

    'scope_classifications' => [
        'bug' => 'Bug / defect', 'missed_requirement' => 'Missed requirement', 'included_revision' => 'Included revision', 'new_scope' => 'New scope',
        'enhancement' => 'Enhancement', 'warranty' => 'Warranty / fix promise',
    ],
    'billing_statuses' => ['billable', 'non_billable', 'complimentary'],

    /*
    | Ticket types. kind: the default (staff can change it). team: default routing. qa: QA required before Resolved.
    | example: the one-line hint on the submit form.
    */
    'types' => [
        'technical_support' => ['label' => 'Technical support', 'kind' => 'support', 'team' => 'support', 'qa' => false, 'example' => 'Something is not working and I need help'],
        'bug' => ['label' => 'Bug or error', 'kind' => 'support', 'team' => 'technical', 'qa' => true, 'example' => 'A workflow, page or automation does the wrong thing'],
        'fulfillment_request' => ['label' => 'Fulfillment request', 'kind' => 'fulfillment', 'team' => 'delivery', 'qa' => true, 'example' => 'Please build or set up something'],
        'revision_request' => ['label' => 'Revision request', 'kind' => 'fulfillment', 'team' => 'delivery', 'qa' => false, 'example' => 'Change something we already delivered'],
        'change_request' => ['label' => 'Change request', 'kind' => 'change_request', 'team' => 'delivery_manager', 'qa' => false, 'example' => 'New work beyond what was agreed'],
        'workflow_automation' => ['label' => 'Workflow or automation', 'kind' => 'fulfillment', 'team' => 'automation', 'qa' => true, 'example' => 'Build or fix an automation'],
        'ai_agent' => ['label' => 'AI agent', 'kind' => 'fulfillment', 'team' => 'ai_automation', 'qa' => true, 'example' => 'A chatbot or voice agent needs setup or changes'],
        'integration_api' => ['label' => 'Integration or API', 'kind' => 'fulfillment', 'team' => 'integration', 'qa' => true, 'example' => 'Connect or fix a connection between systems'],
        'account_access' => ['label' => 'Account or access', 'kind' => 'support', 'team' => 'support', 'qa' => false, 'example' => 'Access, users or permissions'],
        'billing' => ['label' => 'Billing or subscription', 'kind' => 'support', 'team' => 'finance', 'qa' => false, 'example' => 'A question about an invoice or plan'],
        'training' => ['label' => 'Training or consultation', 'kind' => 'support', 'team' => 'support', 'qa' => false, 'example' => 'Show me how, or advise me'],
        'critical_incident' => ['label' => 'Critical incident', 'kind' => 'support', 'team' => 'escalation', 'qa' => true, 'example' => 'Something important is down right now'],
    ],

    /*
    | The extra questions of each type, stored in tickets.details. [key => [label, required, input]]. The submit form is
    | drawn from this list and the Form Request validates against it, so a new question is one line here.
    */
    'type_fields' => [
        'technical_support' => ['goal' => ['What are you trying to do?', true, 'textarea'], 'tried' => ['What have you tried?', false, 'textarea']],
        'bug' => [
            'steps' => ['Steps to reproduce (numbered, repeatable)', true, 'textarea'], 'actual_result' => ['What is happening?', true, 'textarea'],
            'affected' => ['Who or what is affected?', true, 'text'], 'when' => ['When did it occur?', true, 'text'], 'evidence' => ['Evidence or example (link)', true, 'text'],
            'record_ref' => ['Affected contact or record reference (only where safe)', false, 'text'],
        ],
        'fulfillment_request' => ['deliverables' => ['Deliverables', true, 'textarea'], 'acceptance' => ['Acceptance criteria', true, 'textarea'], 'assets' => ['Asset links', false, 'text']],
        'revision_request' => [
            'deliverable' => ['Which deliverable (workflow, page or pipeline URL or ID)', true, 'text'], 'change' => ['What should change?', true, 'textarea'],
            'reference' => ['Reference or example', false, 'text'], 'included' => ['Is this included in my package?', false, 'text'],
        ],
        'change_request' => [
            'what_new' => ['What is new?', true, 'textarea'], 'why' => ['Why do you need it?', true, 'textarea'], 'business_impact' => ['Business impact', false, 'textarea'],
            'budget' => ['Budget expectation', false, 'text'], 'timeline' => ['Desired timeline', false, 'text'],
        ],
        'workflow_automation' => ['workflow' => ['Workflow name or URL', true, 'text'], 'trigger' => ['Trigger', true, 'text'], 'expected_behavior' => ['Expected behavior', true, 'textarea'], 'example_contact' => ['Example contact (safe reference)', false, 'text']],
        'ai_agent' => ['agent' => ['Agent name', true, 'text'], 'channel' => ['Channel (chat, voice or both)', true, 'text'], 'example' => ['Example conversation or call link', false, 'text'], 'expected_behavior' => ['Expected behavior', true, 'textarea']],
        'integration_api' => ['systems' => ['Systems involved', true, 'text'], 'endpoint' => ['Endpoint or webhook', false, 'text'], 'error' => ['Error message or log snippet', false, 'textarea'], 'direction' => ['Direction and frequency', false, 'text']],
        'account_access' => ['access' => ['What access is needed?', true, 'text'], 'account' => ['Which account?', true, 'text'], 'who' => ['Who needs it?', true, 'text'], 'role' => ['Role needed', false, 'text']],
        'billing' => ['reference' => ['Invoice or subscription reference', false, 'text'], 'question' => ['Your question', true, 'textarea'], 'amount' => ['Amount in question', false, 'text']],
        'training' => ['topic' => ['Topic', true, 'text'], 'format' => ['Preferred format (live or Loom)', false, 'text'], 'times' => ['Preferred times and timezone', false, 'text']],
        'critical_incident' => [
            'what_down' => ['What is down?', true, 'textarea'], 'since' => ['Since when?', true, 'text'], 'revenue_impact' => ['Revenue impact', true, 'text'],
            'mitigation' => ['Current mitigation', false, 'text'], 'emergency_phone' => ['Emergency phone', true, 'text'],
        ],
    ],
];
