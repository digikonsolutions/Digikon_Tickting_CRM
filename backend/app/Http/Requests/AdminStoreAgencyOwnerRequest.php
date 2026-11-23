<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\AgencyProfile;
use App\Models\EmployeeAssignment;
use App\Models\OwnerProfile;
use App\Models\Plan;
use App\Services\AgencyOnboardingService;
use App\Support\ColorContrast;
use App\Support\NameNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The agency onboarding wizard: owner, agency, contract and setup sections. Only the owner's name and email and
 * the agency name are required, so an admin can save early and finish later.
 */
class AdminStoreAgencyOwnerRequest extends FormRequest
{
    private const PHONE_REGEX = '/^\+?[0-9][0-9\s\-()]{6,19}$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        $owner = $this->input('owner');
        if (is_array($owner)) {
            if (is_string($owner['email'] ?? null)) {
                $owner['email'] = mb_strtolower(trim($owner['email']));
            }
            if (is_string($owner['name'] ?? null)) {
                $owner['name'] = NameNormalizer::person($owner['name']);
            }
            $merge['owner'] = $owner;
        }
        $agency = $this->input('agency');
        if (is_array($agency)) {
            foreach (['name', 'legal_name', 'brand_name'] as $key) {
                if (is_string($agency[$key] ?? null)) {
                    $agency[$key] = NameNormalizer::organization($agency[$key]);
                }
            }
            $merge['agency'] = $agency;
        }
        $contract = $this->input('contract');
        if (is_array($contract)) {
            if (is_string($contract['currency'] ?? null)) {
                $contract['currency'] = strtoupper(trim($contract['currency']));
            }
            if (is_array($contract['features'] ?? null)) {
                $contract['features'] = array_values(array_filter(array_map(fn ($line) => is_string($line) ? trim($line) : $line, $contract['features']), fn ($line) => $line !== '' && $line !== null));
            }
            $merge['contract'] = $contract;
        }
        $setup = $this->input('setup');
        if (is_array($setup) && is_string($setup['brand_color'] ?? null)) {
            $setup['brand_color'] = strtoupper(trim($setup['brand_color']));
            $merge['setup'] = $setup;
        }
        if (is_array($setup) && is_string($setup['slug'] ?? null)) {
            $setup['slug'] = strtolower(trim($setup['slug']));
            $merge['setup'] = $setup;
        }
        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $optional = ['nullable'];
        $employee = fn ($query) => $query->where('role', Role::Employee->value)->where('is_active', true);

        return [
            'mode' => ['sometimes', Rule::in(['create', 'draft'])],

            'owner' => ['required', 'array'],
            'owner.name' => ['required', 'string', 'max:120'],
            'owner.email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'owner.phone' => [...$optional, 'regex:'.self::PHONE_REGEX],
            'owner.whatsapp' => [...$optional, 'regex:'.self::PHONE_REGEX],
            'owner.job_title' => [...$optional, 'string', 'max:120'],
            'owner.preferred_contact_method' => [...$optional, Rule::in(OwnerProfile::CONTACT_METHODS)],
            'owner.timezone' => [...$optional, 'timezone:all'],

            'agency' => ['required', 'array'],
            'agency.name' => ['required', 'string', 'max:160'],
            'agency.legal_name' => [...$optional, 'string', 'max:160'],
            'agency.brand_name' => [...$optional, 'string', 'max:160'],
            'agency.website' => [...$optional, 'url:http,https', 'max:255'],
            'agency.industry' => [...$optional, 'string', 'max:100'],
            'agency.country' => [...$optional, 'string', 'max:100'],
            'agency.city' => [...$optional, 'string', 'max:100'],
            'agency.address' => [...$optional, 'string', 'max:500'],
            'agency.company_size' => [...$optional, Rule::in(AgencyProfile::COMPANY_SIZES)],
            'agency.tax_number' => [...$optional, 'string', 'max:50'],
            'agency.billing_email' => [...$optional, 'email', 'max:254'],
            'agency.billing_contact_name' => [...$optional, 'string', 'max:120'],
            'agency.account_manager_id' => [...$optional, 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', [Role::Admin->value, Role::TeamLead->value])->where('is_active', true))],
            'agency.internal_notes' => [...$optional, 'string', 'max:5000'],

            'contract' => ['sometimes', 'nullable', 'array'],
            'contract.plan_id' => [...$optional, 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'contract.employees' => [...$optional, 'integer', 'min:0', 'max:1000'],
            'contract.hours_per_month' => [...$optional, 'numeric', 'min:0', 'max:100000'],
            'contract.max_open_projects' => [...$optional, 'integer', 'min:0', 'max:1000'],
            'contract.max_client_accounts' => [...$optional, 'integer', 'min:0', 'max:1000'],
            'contract.price' => [...$optional, 'numeric', 'min:0', 'max:99999999'],
            'contract.currency' => [...$optional, 'regex:/^[A-Z]{3}$/'],
            'contract.billing_cycle' => [...$optional, Rule::in(Plan::BILLING_CYCLES)],
            'contract.overage_rate' => [...$optional, 'numeric', 'min:0', 'max:99999'],
            'contract.start_date' => [...$optional, 'date'],
            'contract.end_date' => [...$optional, 'date'],
            'contract.auto_renew' => [...$optional, 'boolean'],
            'contract.notice_period_days' => [...$optional, 'integer', 'min:0', 'max:365'],
            'contract.status' => [...$optional, Rule::in(['trial', 'active'])],
            'contract.trial_end_date' => ['nullable', 'date', 'required_if:contract.status,trial'],
            'contract.payment_terms_days' => [...$optional, 'integer', 'min:0', 'max:365'],
            'contract.features' => ['nullable', 'array', 'max:30'],
            'contract.features.*' => ['string', 'max:160'],
            'contract.discount_percent' => [...$optional, 'numeric', 'between:0,100'],

            'setup' => ['sometimes', 'nullable', 'array'],
            'setup.employee_ids' => [...$optional, 'array', 'max:50'],
            'setup.employee_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where($employee)],
            'setup.logo' => [...$optional, 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'setup.brand_color' => [...$optional, 'regex:/^#[0-9A-F]{6}$/'],
            // The agency's portal address and the brand it shows. All optional: the slug is generated from the name when absent.
            'setup.slug' => [...$optional, 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'not_in:'.implode(',', \App\Models\Agency::RESERVED_SLUGS), 'unique:agencies,slug'],
            'setup.display_name' => [...$optional, 'string', 'max:120'],
            'setup.tagline' => [...$optional, 'string', 'max:140'],
            'setup.support_email' => [...$optional, 'email:rfc', 'max:254'],
            'setup.send_invite' => [...$optional, 'boolean'],
            'setup.welcome_message' => [...$optional, 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'owner.email.unique' => 'An account with this email already exists.',
            'owner.phone.regex' => 'Enter a valid phone number, for example +92 300 1234567.',
            'owner.whatsapp.regex' => 'Enter a valid WhatsApp number, for example +92 300 1234567.',
            'contract.trial_end_date.required_if' => 'A trial needs a trial end date.',
            'setup.brand_color.regex' => 'Brand colour must be a hex value like #0F766E.',
            'setup.slug.regex' => 'Use lowercase letters, digits and single hyphens, for example northwind-studio.',
            'setup.slug.unique' => 'This portal address is already taken.',
            'setup.slug.not_in' => 'This portal address is reserved. Choose another.',
            'setup.employee_ids.*.exists' => 'Choose active employees only.',
            'agency.name.required' => 'Enter the agency name.',
            'owner.name.required' => 'Enter the owner\'s name.',
            'owner.email.required' => 'Enter the owner\'s email address.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $contract = AgencyOnboardingService::contractInput($this->input('contract'));

                if ($contract !== null) {
                    $start = $contract['start_date'] ?? now(config('app.timezone'))->toDateString();
                    if (! empty($contract['end_date']) && strtotime((string) $contract['end_date']) <= strtotime((string) $start)) {
                        $validator->errors()->add('contract.end_date', 'The end date must be after the start date.');
                    }
                    if (($contract['status'] ?? null) === 'trial' && ! empty($contract['trial_end_date']) && strtotime((string) $contract['trial_end_date']) < strtotime((string) $start)) {
                        $validator->errors()->add('contract.trial_end_date', 'The trial cannot end before the contract starts.');
                    }
                }

                $color = $this->input('setup.brand_color');
                if (is_string($color) && ! $validator->errors()->has('setup.brand_color') && ! ColorContrast::passesOnWhite($color)) {
                    $ratio = number_format(ColorContrast::ratio($color, '#FFFFFF'), 1);
                    $validator->errors()->add('setup.brand_color', "Brand colour is too light (contrast {$ratio}:1 against white, 4.5:1 needed). Pick a darker shade.");
                }

                $ids = array_values(array_unique(array_map('intval', (array) $this->input('setup.employee_ids', []))));
                if ($ids === [] || $validator->errors()->has('setup.employee_ids') || $validator->errors()->has('setup.employee_ids.*')) {
                    return;
                }
                // Early, friendly checks. The same rules run again, with row locks, inside the creating transaction.
                $seats = $contract['employees'] ?? (! empty($contract['plan_id']) ? Plan::query()->whereKey($contract['plan_id'])->value('employees') : null);
                if ($contract !== null && $seats !== null && count($ids) > (int) $seats) {
                    $validator->errors()->add('setup.employee_ids', 'The contract allows '.(int) $seats.' employee'.((int) $seats === 1 ? '' : 's').', but '.count($ids).' were chosen.');
                }
                if (EmployeeAssignment::query()->where('status', 'active')->whereIn('employee_id', $ids)->exists()) {
                    $validator->errors()->add('setup.employee_ids', 'One or more of these employees is already held by another agency.');
                }
            },
        ];
    }
}
