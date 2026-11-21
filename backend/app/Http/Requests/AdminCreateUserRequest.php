<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminCreateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }

        $name = $this->input('name');
        if (is_string($name)) {
            $this->merge(['name' => \App\Support\NameNormalizer::person($name)]);
        }

        $agencyName = $this->input('agency_name');
        if (is_string($agencyName)) {
            $this->merge(['agency_name' => \App\Support\NameNormalizer::organization($agencyName)]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'role' => ['required', Rule::in([
                Role::Employee->value,
                Role::AgencyOwner->value,
            ])],
            'agency_name' => [
                Rule::requiredIf(fn () => Role::tryFrom((string) $this->input('role')) === Role::AgencyOwner),
                Rule::prohibitedIf(fn () => Role::tryFrom((string) $this->input('role')) !== Role::AgencyOwner),
                'string',
                'max:160',
            ],
            'agency_id' => ['prohibited'],
        ];
    }
}
