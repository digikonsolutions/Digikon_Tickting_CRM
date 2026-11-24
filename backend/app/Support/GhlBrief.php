<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * The detailed GoHighLevel brief of a project: its sections, the validation of every field, which fields are
 * recommended (they drive the completeness percentage, never a block), and the few fields copied into real columns for
 * cards and filters. Every section is stored whole as JSON; the columns are derived copies.
 */
class GhlBrief
{
    public const ACCESS_METHODS = ['invite', 'user_created', 'api_key', 'other'];

    public const CONTACT_METHODS = ['email', 'phone', 'whatsapp', 'sms'];

    public const DEADLINES = ['fixed', 'flexible', 'asap'];

    public const BILLING = ['fixed', 'hourly', 'retainer'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const CATEGORIES = ['funnel', 'website', 'workflow', 'crm_setup', 'pipeline', 'email_sms_campaign', 'reputation', 'calendar', 'forms_surveys', 'membership', 'reporting', 'integration', 'migration', 'audit_fix', 'other'];

    public const SECTION_COLUMNS = [
        'client' => 'client', 'ghl' => 'ghl', 'scope' => 'scope', 'pipelines' => 'pipelines', 'data' => 'contacts_data',
        'automations' => 'automations', 'funnels' => 'funnels', 'communications' => 'communications', 'calendars' => 'calendars',
        'payments' => 'payments', 'branding' => 'branding', 'handover' => 'handover',
    ];

    /** Fields that make a brief "complete enough" for an employee to start. */
    public const RECOMMENDED = [
        'client.name', 'client.email', 'client.timezone', 'ghl.location_id', 'ghl.access_method', 'ghl.admin_holder',
        'scope.categories', 'scope.goal', 'scope.deliverables', 'scope.launch_date', 'scope.out_of_scope',
        'branding.asset_folder_url', 'branding.tone_of_voice', 'handover.training', 'handover.post_launch_support',
    ];

    public const SECTION_LABELS = [
        'client' => 'Client and contact', 'ghl' => 'GoHighLevel account', 'scope' => 'Scope', 'pipelines' => 'Pipelines',
        'data' => 'Contacts and data', 'automations' => 'Automations', 'funnels' => 'Funnels, websites and forms',
        'communications' => 'Communications', 'calendars' => 'Calendars and booking', 'payments' => 'Payments and integrations',
        'branding' => 'Branding and assets', 'handover' => 'Reporting and handover', 'notes' => 'Notes',
    ];

    private const PHONE = '/^\+?[0-9][0-9\s\-()]{6,19}$/';

    /** @return list<string> */
    private static function text(int $max = 255): array
    {
        return ['nullable', 'string', "max:$max"];
    }

    /** @return list<string> */
    private static function url(): array
    {
        return ['nullable', 'string', 'url:http,https', 'max:500'];
    }

    /**
     * Validation rules for `ghl_brief.*`. Unknown keys are dropped by validated(); server-owned columns
     * (completeness, source, ghl_synced_at) have no rule and so can never be set by a client.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $p = 'ghl_brief'): array
    {
        $list = fn (string $key, int $max, array $item = ['string', 'max:200']): array => [
            "$p.$key" => ['nullable', 'array', "max:$max"],
            "$p.$key.*" => $item,
        ];
        $urls = fn (string $key, int $max): array => $list($key, $max, ['string', 'url:http,https', 'max:500']);
        $in = fn (array $values): array => ['nullable', Rule::in($values)];

        return [
            $p => ['nullable', 'array'],

            // 1. Client and contact
            "$p.client" => ['nullable', 'array'],
            "$p.client.name" => self::text(120),
            "$p.client.email" => ['nullable', 'email', 'max:254'],
            "$p.client.phone" => ['nullable', 'regex:'.self::PHONE],
            "$p.client.company" => self::text(160),
            "$p.client.website" => self::url(),
            "$p.client.role" => self::text(120),
            "$p.client.preferred_contact" => $in(self::CONTACT_METHODS),
            "$p.client.timezone" => ['nullable', 'timezone:all'],
            "$p.client.business_hours" => self::text(200),
            "$p.client.secondary_contact" => ['nullable', 'array'],
            "$p.client.secondary_contact.name" => self::text(120),
            "$p.client.secondary_contact.email" => ['nullable', 'email', 'max:254'],
            "$p.client.secondary_contact.phone" => ['nullable', 'regex:'.self::PHONE],
            "$p.client.address" => self::text(500),
            "$p.client.ghl_contact_id" => self::text(80),

            // 2. GoHighLevel account
            "$p.ghl" => ['nullable', 'array'],
            "$p.ghl.subaccount_name" => self::text(160),
            "$p.ghl.location_id" => self::text(80),
            "$p.ghl.access_level" => $in(['agency', 'sub_account']),
            "$p.ghl.plan_tier" => self::text(60),
            "$p.ghl.white_label_domain" => self::text(255),
            "$p.ghl.access_method" => $in(self::ACCESS_METHODS),
            "$p.ghl.admin_holder" => self::text(120),
            "$p.ghl.existing_snapshot" => self::text(160),
            "$p.ghl.build_type" => $in(['new_build', 'cleanup']),
            "$p.ghl.credentials_shared_via" => self::text(200), // how access is shared, never the credentials themselves

            // 3. Scope
            "$p.scope" => ['nullable', 'array'],
            ...$list('scope.categories', 15, ['string', Rule::in(self::CATEGORIES)]),
            "$p.scope.goal" => self::text(3000),
            ...$list('scope.kpis', 20, ['string', 'max:200']),
            ...$list('scope.deliverables', 50, ['string', 'max:300']),
            "$p.scope.out_of_scope" => self::text(3000),
            "$p.scope.launch_date" => ['nullable', 'date_format:Y-m-d'],
            "$p.scope.deadline_flexibility" => $in(self::DEADLINES),
            "$p.scope.priority" => $in(self::PRIORITIES),
            "$p.scope.budget_range" => self::text(100),
            "$p.scope.billing_type" => $in(self::BILLING),

            // 4. Pipelines and opportunities
            "$p.pipelines" => ['nullable', 'array', 'max:20'],
            "$p.pipelines.*.name" => ['required', 'string', 'max:160'],
            "$p.pipelines.*.ghl_id" => self::text(80),
            "$p.pipelines.*.stages" => ['nullable', 'array', 'max:50'],
            "$p.pipelines.*.stages.*" => ['string', 'max:120'],
            "$p.pipelines.*.sources" => ['nullable', 'array', 'max:30'],
            "$p.pipelines.*.sources.*" => ['string', 'max:120'],
            "$p.pipelines.*.lost_reasons" => ['nullable', 'array', 'max:30'],
            "$p.pipelines.*.lost_reasons.*" => ['string', 'max:120'],
            "$p.pipelines.*.value_rules" => self::text(1000),

            // 5. Contacts and data
            "$p.data" => ['nullable', 'array'],
            ...$list('data.tags', 100),
            "$p.data.custom_fields" => ['nullable', 'array', 'max:100'],
            "$p.data.custom_fields.*.name" => ['required', 'string', 'max:120'],
            "$p.data.custom_fields.*.type" => ['nullable', 'string', 'max:40'],
            "$p.data.custom_fields.*.options" => ['nullable', 'array', 'max:50'],
            "$p.data.custom_fields.*.options.*" => ['string', 'max:120'],
            "$p.data.custom_values" => ['nullable', 'array', 'max:50'],
            "$p.data.custom_values.*.key" => ['required', 'string', 'max:120'],
            "$p.data.custom_values.*.value" => self::text(500),
            ...$list('data.smart_lists', 30),
            ...$list('data.lead_sources', 30),
            "$p.data.import" => ['nullable', 'array'],
            "$p.data.import.source" => self::text(160),
            "$p.data.import.record_count" => ['nullable', 'integer', 'min:0', 'max:100000000'],
            "$p.data.import.file_link" => self::url(),
            "$p.data.dnd_consent" => self::text(1000),

            // 6. Automations
            "$p.automations" => ['nullable', 'array'],
            ...$list('automations.triggers', 30),
            ...$list('automations.actions', 50, ['string', 'max:300']),
            "$p.automations.branching" => self::text(2000),
            "$p.automations.wait_rules" => self::text(1000),
            "$p.automations.internal_notifications" => self::text(1000),
            "$p.automations.assignment_rules" => self::text(1000),
            "$p.automations.existing_workflows" => ['nullable', 'array', 'max:50'],
            "$p.automations.existing_workflows.*.name" => ['required', 'string', 'max:160'],
            "$p.automations.existing_workflows.*.ghl_id" => self::text(80),
            "$p.automations.existing_workflows.*.url" => self::url(),

            // 7. Funnels, websites and forms
            "$p.funnels" => ['nullable', 'array'],
            "$p.funnels.pages" => ['nullable', 'array', 'max:50'],
            "$p.funnels.pages.*.name" => ['required', 'string', 'max:160'],
            "$p.funnels.pages.*.purpose" => self::text(300),
            "$p.funnels.domain" => self::text(255),
            "$p.funnels.dns_owner" => $in(['client', 'agency', 'unknown']),
            "$p.funnels.pixels" => ['nullable', 'array'],
            "$p.funnels.pixels.facebook" => self::text(80),
            "$p.funnels.pixels.ga4" => self::text(80),
            "$p.funnels.pixels.gtm" => self::text(80),
            "$p.funnels.seo" => self::text(1000),
            ...$urls('funnels.inspiration_urls', 10),
            "$p.funnels.copy_status" => $in(['provided', 'needed']),
            ...$list('funnels.form_fields', 50),
            "$p.funnels.thank_you" => self::text(1000),

            // 8. Communications
            "$p.communications" => ['nullable', 'array'],
            "$p.communications.email" => ['nullable', 'array'],
            "$p.communications.email.sending_domain" => self::text(255),
            "$p.communications.email.dkim" => $in(['unknown', 'not_set', 'partial', 'pass']),
            "$p.communications.email.spf" => $in(['unknown', 'not_set', 'partial', 'pass']),
            "$p.communications.email.dmarc" => $in(['unknown', 'not_set', 'partial', 'pass']),
            "$p.communications.email.from_name" => self::text(120),
            "$p.communications.email.from_address" => ['nullable', 'email', 'max:254'],
            "$p.communications.email.templates" => self::text(1000),
            "$p.communications.sms" => ['nullable', 'array'],
            "$p.communications.sms.provider" => self::text(80),
            "$p.communications.sms.a2p_status" => $in(['not_started', 'pending', 'approved', 'rejected', 'not_needed']),
            ...$list('communications.sms.numbers', 20, ['string', 'regex:'.self::PHONE]),
            "$p.communications.sms.consent_wording" => self::text(1000),
            "$p.communications.call" => ['nullable', 'array'],
            "$p.communications.call.missed_call_text_back" => ['nullable', 'boolean'],
            "$p.communications.call.call_tracking" => self::text(300),
            "$p.communications.call.ivr" => self::text(1000),
            "$p.communications.social" => ['nullable', 'array'],
            "$p.communications.social.google_business" => self::text(300),
            "$p.communications.social.meta" => self::text(300),
            "$p.communications.social.other" => self::text(300),

            // 9. Calendars and booking
            "$p.calendars" => ['nullable', 'array'],
            ...$list('calendars.types', 20),
            ...$list('calendars.team_members', 30, ['string', 'max:120']),
            "$p.calendars.availability" => self::text(500),
            "$p.calendars.buffers" => self::text(200),
            "$p.calendars.reminders" => self::text(500),
            "$p.calendars.round_robin" => ['nullable', 'boolean'],
            ...$urls('calendars.meeting_links', 10),

            // 10. Payments and integrations
            "$p.payments" => ['nullable', 'array'],
            "$p.payments.processor" => $in(['stripe', 'paypal', 'other', 'none']),
            "$p.payments.products" => ['nullable', 'array', 'max:50'],
            "$p.payments.products.*.name" => ['required', 'string', 'max:160'],
            "$p.payments.products.*.price" => self::text(40),
            "$p.payments.invoices" => self::text(500),
            "$p.payments.memberships" => self::text(500),
            ...$list('payments.integrations', 30),
            ...$list('payments.connected_accounts', 30),
            ...$list('payments.tools', 30),

            // 11. Branding and assets
            "$p.branding" => ['nullable', 'array'],
            "$p.branding.logo_url" => self::url(),
            ...$list('branding.colors', 12, ['string', 'regex:/^#?[0-9A-Fa-f]{3,8}$/']),
            ...$list('branding.fonts', 10, ['string', 'max:80']),
            "$p.branding.guideline_url" => self::url(),
            "$p.branding.asset_folder_url" => self::url(),
            "$p.branding.tone_of_voice" => self::text(500),
            ...$urls('branding.competitor_urls', 10),

            // 12. Reporting and handover
            "$p.handover" => ['nullable', 'array'],
            ...$list('handover.dashboards', 20),
            "$p.handover.cadence" => self::text(160),
            "$p.handover.training" => $in(['loom', 'live', 'none']),
            "$p.handover.documentation" => self::text(500),
            "$p.handover.post_launch_support" => self::text(500),

            // 13. Notes. Internal notes are never accepted from agency users (see UpdateProjectBriefRequest).
            "$p.notes" => ['nullable', 'array'],
            "$p.notes.client" => self::text(5000),
        ];
    }

    /**
     * The row to store for a validated `ghl_brief` array: sections whole as JSON, plus the derived columns.
     *
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>
     */
    public static function toColumns(array $brief): array
    {
        $row = [];
        foreach (self::SECTION_COLUMNS as $key => $column) {
            if (array_key_exists($key, $brief)) {
                $row[$column] = $brief[$key];
            }
        }
        if (array_key_exists('notes', $brief)) {
            $row['notes_client'] = $brief['notes']['client'] ?? null;
        }

        $copy = [
            'client_name' => 'client.name', 'client_email' => 'client.email', 'client_phone' => 'client.phone', 'company' => 'client.company',
            'website' => 'client.website', 'ghl_location_id' => 'ghl.location_id', 'ghl_subaccount_name' => 'ghl.subaccount_name',
            'access_method' => 'ghl.access_method', 'plan_tier' => 'ghl.plan_tier', 'launch_date' => 'scope.launch_date',
            'deadline_flexibility' => 'scope.deadline_flexibility', 'billing_type' => 'scope.billing_type', 'budget_range' => 'scope.budget_range',
            'priority' => 'scope.priority',
        ];
        foreach ($copy as $column => $path) {
            [$section, $field] = explode('.', $path);
            if (array_key_exists($section, $brief) && is_array($brief[$section])) {
                $row[$column] = $brief[$section][$field] ?? null;
            }
        }

        return $row;
    }

    /**
     * 0-100: how many recommended fields are filled. Gentle by design: nothing is blocked by it.
     *
     * @param  array<string, mixed>  $assembled  the brief as sections (see assemble())
     * @return array{percent: int, missing: list<string>}
     */
    public static function completeness(array $assembled): array
    {
        $missing = [];
        foreach (self::RECOMMENDED as $path) {
            $value = data_get($assembled, $path);
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $path;
            }
        }

        return ['percent' => (int) round((count(self::RECOMMENDED) - count($missing)) / count(self::RECOMMENDED) * 100), 'missing' => $missing];
    }

    /**
     * The sections of a stored row, keyed as the API shows them.
     *
     * @return array<string, mixed>
     */
    public static function assemble(object $row, bool $withInternal = false): array
    {
        $out = [];
        foreach (self::SECTION_COLUMNS as $key => $column) {
            $out[$key] = $row->{$column};
        }
        $out['notes'] = ['client' => $row->notes_client] + ($withInternal ? ['internal' => $row->notes_internal] : []);

        return $out;
    }
}
