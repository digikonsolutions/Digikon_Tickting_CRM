<?php

namespace App\Support;

use App\Services\TicketTemplateRegistry;
use Illuminate\Validation\Rule;

/**
 * The work order of one ticket: validation, how it maps onto `ticket_details`, and completeness against the work
 * type's recommended fields. The agency writes everything except delivery; the assigned employee writes delivery,
 * the blocked flag and ticks the checklist (see TicketDetailsService).
 */
class TicketWorkOrder
{
    public const SECTIONS = ['requirements' => 'requirements', 'inputs' => 'inputs', 'testing' => 'testing'];

    public const WAITING_ON = ['client', 'employee', 'third_party'];

    public const REF_TYPES = ['workflow', 'pipeline', 'stage', 'funnel', 'website', 'page', 'form', 'survey', 'calendar', 'custom_field', 'tag', 'email_template', 'sms_template', 'snapshot', 'other'];

    private static function text(int $max): array
    {
        return ['nullable', 'string', "max:$max"];
    }

    /**
     * Rules for the nested `work_order` object an agency user sends. `checklist` is the whole ordered list.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $p = 'work_order', ?int $workItemId = null, ?int $exceptTicketId = null): array
    {
        $url = ['string', 'url:http,https', 'max:500'];

        return [
            $p => ['nullable', 'array'],
            "$p.work_type" => ['nullable', Rule::in(TicketTemplateRegistry::types())],
            "$p.brief_section" => ['nullable', Rule::in([...array_keys(GhlBrief::SECTION_LABELS)])],
            "$p.labels" => ['nullable', 'array', 'max:10'],
            "$p.labels.*" => ['string', 'max:40'],

            "$p.requirements" => ['nullable', 'array'],
            "$p.requirements.instructions" => self::text(8000),
            "$p.requirements.acceptance_criteria" => self::text(4000),
            "$p.requirements.expected_behavior" => ['nullable', 'array', 'max:30'],
            "$p.requirements.expected_behavior.*" => ['string', 'max:400'],
            "$p.requirements.out_of_scope" => self::text(2000),
            "$p.requirements.reference_urls" => ['nullable', 'array', 'max:10'],
            "$p.requirements.reference_urls.*" => $url,
            "$p.requirements.loom_url" => ['nullable', ...$url],

            "$p.ghl" => ['nullable', 'array'],
            "$p.ghl.location_id" => self::text(80),
            "$p.ghl.refs" => ['nullable', 'array', 'max:30'],
            "$p.ghl.refs.*.type" => ['required', Rule::in(self::REF_TYPES)],
            "$p.ghl.refs.*.name" => ['required', 'string', 'max:160'],
            "$p.ghl.refs.*.ghl_id" => self::text(80),
            "$p.ghl.refs.*.url" => ['nullable', ...$url],

            "$p.inputs" => ['nullable', 'array'],
            "$p.inputs.client_supplies" => ['nullable', 'array', 'max:30'],
            "$p.inputs.client_supplies.*" => ['string', 'max:200'],
            "$p.inputs.asset_urls" => ['nullable', 'array', 'max:20'],
            "$p.inputs.asset_urls.*" => $url,
            "$p.inputs.inputs_ready" => ['nullable', 'boolean'],
            "$p.inputs.missing" => ['nullable', 'array', 'max:30'],
            "$p.inputs.missing.*" => ['string', 'max:200'],
            "$p.inputs.access_needed" => self::text(500),

            "$p.dependencies" => ['nullable', 'array'],
            // A ticket can only depend on tickets of its own project, so ids from another agency are never accepted.
            "$p.dependencies.depends_on" => ['nullable', 'array', 'max:20'],
            "$p.dependencies.depends_on.*" => ['integer', 'distinct', Rule::exists('tickets', 'id')->where('work_item_id', $workItemId ?? 0), ...($exceptTicketId ? [Rule::notIn([$exceptTicketId])] : [])],
            "$p.dependencies.waiting_on" => ['nullable', Rule::in(self::WAITING_ON)],

            "$p.environment" => ['nullable', 'array'],
            "$p.environment.environment" => ['nullable', Rule::in(['test', 'live'])],
            "$p.environment.affects_live_traffic" => ['nullable', 'boolean'],
            "$p.environment.requires_approval" => ['nullable', 'boolean'],
            "$p.environment.rollback_note" => self::text(2000),

            "$p.testing" => ['nullable', 'array'],
            "$p.testing.steps" => self::text(4000),
            "$p.testing.test_data" => self::text(1000),
            "$p.testing.devices" => self::text(300),
            "$p.testing.expected" => self::text(2000),
            "$p.testing.qa_checklist" => ['nullable', 'array', 'max:30'],
            "$p.testing.qa_checklist.*" => ['string', 'max:300'],

            "$p.checklist" => ['nullable', 'array', 'max:50'],
            "$p.checklist.*.id" => ['nullable', 'integer'],
            "$p.checklist.*.text" => ['required', 'string', 'max:300'],

            "$p.notes" => ['nullable', 'array'],
            "$p.notes.client" => self::text(5000),

            "$p.review" => ['nullable', 'array'],
            "$p.review.feedback" => self::text(3000),
            "$p.review.approved" => ['nullable', 'boolean'],
            "$p.review.rework_reason" => self::text(1000),
        ];
    }

    /**
     * Server-owned or employee-owned parts of the work order: an agency user cannot send them.
     *
     * @return array<string, mixed>
     */
    public static function agencyProhibited(string $p = 'work_order'): array
    {
        return array_fill_keys(array_map(fn (string $key): string => "$p.$key", ['delivery', 'delivery_submitted_at', 'blocked', 'completeness', 'source', 'checklist_total', 'checklist_done', 'notes.internal']), ['prohibited']);
    }

    /** Rules for what the assigned employee delivers. @return array<string, mixed> */
    public static function deliveryRules(): array
    {
        return [
            'done' => ['nullable', 'string', 'max:4000'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*' => ['string', 'url:http,https', 'max:500'],
            'ghl_changes' => self::text(3000),
            'maintain' => self::text(3000),
            'limitations' => self::text(2000),
            'followups' => self::text(2000),
            'time_note' => self::text(500),
        ];
    }

    /**
     * Columns and JSON for a validated `work_order` (checklist and notes are handled by the service).
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public static function toColumns(array $order): array
    {
        $row = [];
        foreach (['work_type', 'brief_section', 'labels'] as $key) {
            if (array_key_exists($key, $order)) {
                $row[$key] = $order[$key];
            }
        }
        foreach (['requirements', 'inputs', 'testing'] as $key) {
            if (array_key_exists($key, $order)) {
                $row[$key] = $order[$key];
            }
        }
        if (array_key_exists('ghl', $order)) {
            $row['ghl_location_id'] = $order['ghl']['location_id'] ?? null;
            $row['ghl_refs'] = $order['ghl']['refs'] ?? null;
        }
        if (array_key_exists('inputs', $order)) {
            $row['inputs_ready'] = $order['inputs']['inputs_ready'] ?? null;
        }
        if (array_key_exists('dependencies', $order)) {
            $row['depends_on'] = $order['dependencies']['depends_on'] ?? null;
            $row['waiting_on'] = $order['dependencies']['waiting_on'] ?? null;
        }
        if (array_key_exists('environment', $order)) {
            $env = $order['environment'] ?? [];
            $row['environment'] = $env['environment'] ?? null;
            $row['affects_live_traffic'] = $env['affects_live_traffic'] ?? null;
            $row['requires_approval'] = $env['requires_approval'] ?? null;
            $row['rollback_note'] = $env['rollback_note'] ?? null;
        }
        if (array_key_exists('notes', $order)) {
            $row['notes_client'] = $order['notes']['client'] ?? null;
        }
        if (array_key_exists('review', $order)) {
            $row['review'] = $order['review'];
        }

        return $row;
    }

    /**
     * The nested work order as the API shows it, from a stored row.
     *
     * @return array<string, mixed>
     */
    public static function assemble(object $row, bool $withInternal): array
    {
        return [
            'work_type' => $row->work_type,
            'brief_section' => $row->brief_section,
            'labels' => $row->labels ?? [],
            'requirements' => $row->requirements ?? [],
            'ghl' => ['location_id' => $row->ghl_location_id, 'refs' => $row->ghl_refs ?? []],
            'inputs' => ($row->inputs ?? []) + ['inputs_ready' => $row->inputs_ready],
            'dependencies' => ['depends_on' => $row->depends_on ?? [], 'waiting_on' => $row->waiting_on],
            'environment' => ['environment' => $row->environment, 'affects_live_traffic' => $row->affects_live_traffic, 'requires_approval' => $row->requires_approval, 'rollback_note' => $row->rollback_note],
            'testing' => $row->testing ?? [],
            'blocked' => ['blocked' => (bool) $row->blocked, 'reason' => $row->blocked_reason, 'waiting_on' => $row->waiting_on],
            'delivery' => $row->delivery ?? [],
            'delivery_submitted_at' => $row->delivery_submitted_at?->toISOString(),
            'review' => $row->review ?? [],
            'notes' => ['client' => $row->notes_client] + ($withInternal ? ['internal' => $row->notes_internal] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $assembled
     * @return array{percent: int, missing: list<string>}
     */
    public static function completeness(array $assembled, ?string $workType): array
    {
        $recommended = app(TicketTemplateRegistry::class)->recommended($workType);
        $missing = [];
        foreach ($recommended as $path) {
            $value = data_get($assembled, $path);
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $path;
            }
        }

        return [
            'percent' => $recommended === [] ? 100 : (int) round((count($recommended) - count($missing)) / count($recommended) * 100),
            'missing' => $missing,
        ];
    }
}
