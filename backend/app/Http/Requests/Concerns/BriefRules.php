<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * Validation for the optional "brief" fields on projects and tickets. Links must be http(s) so a
 * stored value can never be a javascript: or data: URL.
 */
trait BriefRules
{
    public const PLATFORMS = ['ghl', 'wordpress', 'custom', 'other'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const DUE_PREFERENCES = ['asap', 'this_week', 'next_week', 'flexible'];

    /** @return array<string, mixed> */
    protected function projectBriefRules(): array
    {
        $url = ['nullable', 'string', 'url:http,https', 'max:500'];

        return [
            'objective' => ['nullable', 'string', 'max:3000'],
            'deliverables' => ['nullable', 'array', 'max:25'],
            'deliverables.*' => ['string', 'max:200'],
            'target_url' => $url,
            'staging_url' => $url,
            'repo_url' => $url,
            'platform' => ['nullable', Rule::in(self::PLATFORMS)],
            'tech_stack' => ['nullable', 'string', 'max:255'],
            'brand_assets_url' => $url,
            'access_details' => ['nullable', 'string', 'max:3000'],
            'reference_links' => ['nullable', 'array', 'max:10'],
            'reference_links.*' => ['string', 'url:http,https', 'max:500'],
            'acceptance_criteria' => ['nullable', 'string', 'max:3000'],
            'budget' => ['nullable', 'string', 'max:100'],
            'brief_priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'string', 'email', 'max:254'],
        ];
    }

    /** @return array<string, mixed> */
    protected function ticketBriefRules(?int $workItemId, ?int $exceptTicketId = null): array
    {
        return [
            'steps_to_reproduce' => ['nullable', 'string', 'max:5000'],
            'expected_result' => ['nullable', 'string', 'max:3000'],
            'actual_result' => ['nullable', 'string', 'max:3000'],
            'affected_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'environment' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', Rule::in(self::SEVERITIES)],
            'due_preference' => ['nullable', Rule::in(self::DUE_PREFERENCES)],
            'related_ticket_ids' => ['nullable', 'array', 'max:10'],
            // Related tickets must belong to the same project, so ids from another agency are never accepted.
            'related_ticket_ids.*' => [
                'integer', 'distinct',
                Rule::exists('tickets', 'id')->where('work_item_id', $workItemId ?? 0),
                ...($exceptTicketId ? [Rule::notIn([$exceptTicketId])] : []),
            ],
        ];
    }
}
