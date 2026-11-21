<?php

namespace App\Http\Requests;

use App\Models\ClientProfile;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) and edit (PATCH) an agency client. On edit every field is optional. */
class AgencyClientRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => NameNormalizer::person($this->input('name'))]);
        }
        // Empty form fields mean "not given", not "an empty string".
        $this->merge(collect(ClientProfile::FIELDS)->mapWithKeys(
            fn (string $field): array => is_string($this->input($field)) ? [$field => trim($this->input($field)) ?: null] : []
        )->all());
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $editing = $this->isMethod('PATCH');
        $required = $editing ? 'sometimes' : 'required';
        $client = $this->route('client');

        return [
            'name' => [$required, 'string', 'max:120'],
            'email' => [$required, 'string', 'email', 'max:254', Rule::unique('users', 'email')->ignore($client)],
            'company_name' => ['nullable', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\-\s]+$/'],
            'preferred_contact_method' => ['nullable', Rule::in(ClientProfile::CONTACT_METHODS)],
            'timezone' => ['nullable', 'timezone:all'],
            'address' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'send_invitation' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists.',
            'phone.regex' => 'Use digits, spaces and + ( ) - only.',
            'website.url' => 'Enter a full web address such as https://example.com.',
        ];
    }
}
