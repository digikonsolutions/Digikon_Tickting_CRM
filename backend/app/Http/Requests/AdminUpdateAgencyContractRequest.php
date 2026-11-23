<?php

namespace App\Http\Requests;

use App\Models\AgencyContract;
use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Admin edit (or first creation) of an agency's contract: plan, limits, price, dates and status. */
class AdminUpdateAgencyContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
        if (is_array($this->input('features'))) {
            $this->merge(['features' => array_values(array_filter(array_map(fn ($line) => is_string($line) ? trim($line) : $line, $this->input('features')), fn ($line) => $line !== '' && $line !== null))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $optional = ['sometimes', 'nullable'];

        return [
            'plan_id' => [...$optional, 'integer', Rule::exists('plans', 'id')],
            'status' => ['sometimes', 'required', Rule::in(AgencyContract::STATUSES)],
            'employees' => [...$optional, 'integer', 'min:0', 'max:1000'],
            'hours_per_month' => [...$optional, 'numeric', 'min:0', 'max:100000'],
            'max_open_projects' => [...$optional, 'integer', 'min:0', 'max:1000'],
            'max_client_accounts' => [...$optional, 'integer', 'min:0', 'max:1000'],
            'price' => [...$optional, 'numeric', 'min:0', 'max:99999999'],
            'currency' => [...$optional, 'regex:/^[A-Z]{3}$/'],
            'billing_cycle' => ['sometimes', 'required', Rule::in(Plan::BILLING_CYCLES)],
            'overage_rate' => [...$optional, 'numeric', 'min:0', 'max:99999'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => [...$optional, 'date', 'after_or_equal:start_date'],
            'auto_renew' => ['sometimes', 'boolean'],
            'notice_period_days' => [...$optional, 'integer', 'min:0', 'max:365'],
            'trial_end_date' => ['sometimes', 'nullable', 'date', 'required_if:status,trial'],
            'payment_terms_days' => [...$optional, 'integer', 'min:0', 'max:365'],
            'discount_percent' => [...$optional, 'numeric', 'min:0', 'max:100'],
            'features' => ['sometimes', 'nullable', 'array', 'max:50'],
            'features.*' => ['string', 'max:200'],
        ];
    }
}
