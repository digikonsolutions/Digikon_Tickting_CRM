<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A ticket submitted from the agency portal. Everything the server owns (kind, ref, status, owner, SLA) is prohibited. */
class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $agencyId = $this->user()?->agency_id;
        $type = (string) $this->input('type');
        $rules = [
            'type' => ['required', Rule::in(array_keys(config('support.types')))],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:10000'],
            'expected_result' => ['required', 'string', 'max:5000'],
            'service' => ['nullable', Rule::in(array_keys(config('support.services')))],
            // Both must belong to the caller's own agency: another agency's ids are simply not found.
            'end_client_id' => ['nullable', 'integer', Rule::exists('end_clients', 'id')->where('agency_id', $agencyId)],
            'related_project_id' => ['nullable', 'integer', Rule::exists('work_items', 'id')->where('agency_id', $agencyId)],
            'requested_deadline' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'issue_started_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'confirm_urgent' => ['sometimes', 'boolean'],
            'impact' => ['nullable', 'array'],
            'impact.live_broken' => ['nullable', 'boolean'],
            'impact.revenue_affected' => ['nullable', 'boolean'],
            'impact.workaround' => ['nullable', 'boolean'],
            'details' => ['nullable', 'array'],
        ];
        foreach (config("support.type_fields.{$type}", []) as $key => [$label, $required]) {
            $rules["details.{$key}"] = [$required ? 'required' : 'nullable', 'string', 'max:5000'];
        }
        foreach (['kind', 'ref', 'status', 'assigned_to_id', 'assigned_team', 'reviewer_id', 'agency_id', 'submitted_by_id', 'scope_classification', 'billing_status', 'resolution_summary', 'resolution_category'] as $owned) {
            $rules[$owned] = ['prohibited'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        $type = (string) $this->input('type');
        $names = [];
        foreach (config("support.type_fields.{$type}", []) as $key => [$label]) {
            $names["details.{$key}"] = mb_strtolower($label);
        }

        return $names;
    }
}
