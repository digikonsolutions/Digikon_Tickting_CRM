<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\AgencyProfile;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Admin edit of an agency's name and company details. Contract, status and branding have their own endpoints. */
class AdminUpdateAgencyDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['name', 'legal_name', 'brand_name'] as $key) {
            if (is_string($this->input($key))) {
                $merge[$key] = NameNormalizer::organization($this->input($key));
            }
        }
        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $optional = ['sometimes', 'nullable'];

        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'legal_name' => [...$optional, 'string', 'max:160'],
            'brand_name' => [...$optional, 'string', 'max:160'],
            'website' => [...$optional, 'url:http,https', 'max:255'],
            'industry' => [...$optional, 'string', 'max:100'],
            'country' => [...$optional, 'string', 'max:100'],
            'city' => [...$optional, 'string', 'max:100'],
            'address' => [...$optional, 'string', 'max:500'],
            'company_size' => [...$optional, Rule::in(AgencyProfile::COMPANY_SIZES)],
            'tax_number' => [...$optional, 'string', 'max:50'],
            'billing_email' => [...$optional, 'email', 'max:254'],
            'billing_contact_name' => [...$optional, 'string', 'max:120'],
            'account_manager_id' => [...$optional, 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', [Role::Admin->value, Role::TeamLead->value])->where('is_active', true))],
            'internal_notes' => [...$optional, 'string', 'max:5000'],
        ];
    }
}
