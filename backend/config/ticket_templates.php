<?php

/*
| Work types for tickets, and what each one expects. A template gives the new ticket a starting checklist and an
| acceptance-criteria skeleton, says which fields are recommended (they drive the gentle "Missing: ..." hint, never a
| block) and which section of the project brief is the useful context. Read by App\Services\TicketTemplateRegistry.
|
| Recommended fields are dotted paths into the work order (see App\Support\TicketWorkOrder).
*/

$common = ['requirements.instructions', 'requirements.acceptance_criteria'];

return [
    'workflow_build' => [
        'label' => 'Workflow build', 'brief_section' => 'automations',
        'recommended' => [...$common, 'requirements.expected_behavior', 'ghl.refs', 'testing.steps'],
        'acceptance' => "- Triggers on the agreed event only\n- Every step runs in order and branches as described\n- Tested with a test contact and nothing sent to real contacts",
        'checklist' => ['Confirm trigger and filters', 'Build actions and waits', 'Add internal notifications', 'Test with a test contact', 'Publish (after approval if required)'],
    ],
    'workflow_fix' => [
        'label' => 'Workflow fix', 'brief_section' => 'automations',
        'recommended' => [...$common, 'ghl.refs', 'testing.steps'],
        'acceptance' => "- The reported problem no longer happens\n- Nothing else in the workflow changed",
        'checklist' => ['Reproduce the problem', 'Find the cause', 'Fix it', 'Re-test the whole path'],
    ],
    'funnel_page' => [
        'label' => 'Funnel page', 'brief_section' => 'funnels',
        'recommended' => [...$common, 'ghl.refs', 'inputs.client_supplies', 'testing.devices'],
        'acceptance' => "- Matches the supplied design or copy\n- Form submits and the thank-you step works\n- Looks right on mobile and desktop",
        'checklist' => ['Build the page', 'Connect the form', 'Check tracking pixels', 'Test on mobile and desktop'],
    ],
    'website_page' => [
        'label' => 'Website page', 'brief_section' => 'funnels',
        'recommended' => [...$common, 'ghl.refs', 'inputs.client_supplies', 'testing.devices'],
        'acceptance' => "- Content and layout as agreed\n- Links and forms work\n- Responsive",
        'checklist' => ['Build the page', 'Add content and images', 'Check links and forms', 'Test on mobile and desktop'],
    ],
    'form_survey' => [
        'label' => 'Form or survey', 'brief_section' => 'funnels',
        'recommended' => [...$common, 'ghl.refs', 'testing.steps'],
        'acceptance' => "- All fields as listed, with the right types\n- Submissions create or update the contact and trigger the follow-up",
        'checklist' => ['Create the form', 'Map fields to contact fields', 'Set the thank-you flow', 'Submit a test entry'],
    ],
    'email_template' => [
        'label' => 'Email template', 'brief_section' => 'communications',
        'recommended' => [...$common, 'inputs.client_supplies', 'ghl.refs'],
        'acceptance' => "- Branded as per the guidelines\n- Merge fields render correctly\n- Looks right in common mail clients",
        'checklist' => ['Build the template', 'Check merge fields', 'Send a test email', 'Check mobile rendering'],
    ],
    'sms_template' => [
        'label' => 'SMS template', 'brief_section' => 'communications',
        'recommended' => [...$common, 'ghl.refs'],
        'acceptance' => "- Within length limits and includes the opt-out wording\n- Merge fields render correctly",
        'checklist' => ['Write the message', 'Add opt-out wording', 'Send a test message'],
    ],
    'pipeline_change' => [
        'label' => 'Pipeline or stage change', 'brief_section' => 'pipelines',
        'recommended' => [...$common, 'ghl.refs'],
        'acceptance' => "- Stages in the agreed order\n- Existing opportunities moved correctly",
        'checklist' => ['Back up or note the current stages', 'Make the change', 'Check existing opportunities', 'Check workflows that use the stages'],
    ],
    'custom_field_tag' => [
        'label' => 'Custom field or tag setup', 'brief_section' => 'contacts_data',
        'recommended' => [...$common, 'ghl.refs'],
        'acceptance' => "- Fields have the right type and options\n- Tags follow the agreed naming",
        'checklist' => ['Create the fields or tags', 'Check types and options', 'Update forms or workflows that use them'],
    ],
    'calendar_setup' => [
        'label' => 'Calendar setup', 'brief_section' => 'calendars',
        'recommended' => [...$common, 'ghl.refs', 'testing.steps'],
        'acceptance' => "- Availability, buffers and reminders as agreed\n- A test booking works end to end",
        'checklist' => ['Create the calendar', 'Set availability and buffers', 'Set reminders', 'Make a test booking'],
    ],
    'integration' => [
        'label' => 'Integration or webhook', 'brief_section' => 'payments',
        'recommended' => [...$common, 'ghl.refs', 'testing.steps', 'environment.rollback_note'],
        'acceptance' => "- Data flows in the agreed direction\n- Failures are visible and do not lose data",
        'checklist' => ['Connect the account', 'Map the data', 'Test with sample data', 'Document how it works'],
    ],
    'domain_email_auth' => [
        'label' => 'DNS, domain or email authentication', 'brief_section' => 'communications',
        'recommended' => [...$common, 'inputs.access_needed', 'environment.rollback_note'],
        'acceptance' => "- DNS records added by the domain owner\n- SPF, DKIM and DMARC pass in a test",
        'checklist' => ['Get DNS access or send the records', 'Verify the records', 'Send a test email and check headers'],
    ],
    'a2p_compliance' => [
        'label' => 'A2P or compliance', 'brief_section' => 'communications',
        'recommended' => [...$common, 'inputs.client_supplies', 'inputs.access_needed'],
        'acceptance' => "- Registration submitted with the right business details\n- Consent wording in place",
        'checklist' => ['Collect business details', 'Submit the registration', 'Add consent wording', 'Track approval'],
    ],
    'import_migration' => [
        'label' => 'Import or migration', 'brief_section' => 'contacts_data',
        'recommended' => [...$common, 'inputs.client_supplies', 'environment.rollback_note', 'testing.steps'],
        'acceptance' => "- Record counts match the source\n- Fields mapped correctly\n- Duplicates handled as agreed",
        'checklist' => ['Check the source file', 'Map fields', 'Import a small sample', 'Run the full import', 'Compare counts'],
    ],
    'reporting' => [
        'label' => 'Reporting or dashboard', 'brief_section' => 'handover',
        'recommended' => [...$common, 'ghl.refs'],
        'acceptance' => "- Every agreed number is on the dashboard\n- Numbers match the source",
        'checklist' => ['Build the widgets', 'Check the numbers', 'Share access'],
    ],
    'qa_testing' => [
        'label' => 'QA or testing', 'brief_section' => 'scope',
        'recommended' => [...$common, 'testing.steps', 'testing.expected'],
        'acceptance' => "- Every listed path tested and the results written down",
        'checklist' => ['Write down what was tested', 'Note every problem found', 'Re-test the fixes'],
    ],
    'training_handover' => [
        'label' => 'Training or handover', 'brief_section' => 'handover',
        'recommended' => [...$common],
        'acceptance' => "- The client can do the agreed tasks without help",
        'checklist' => ['Record or run the training', 'Share the documentation', 'Agree the support period'],
    ],
    'bug_fix' => [
        'label' => 'Bug or fix', 'brief_section' => 'scope',
        'recommended' => [...$common, 'requirements.expected_behavior', 'testing.steps'],
        'acceptance' => "- The problem is fixed and stays fixed\n- No new problem introduced",
        'checklist' => ['Reproduce the problem', 'Fix it', 'Re-test'],
    ],
    'other' => [
        'label' => 'Other', 'brief_section' => null,
        'recommended' => [...$common],
        'acceptance' => "- Done as described",
        'checklist' => [],
    ],
];
