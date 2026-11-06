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
            'role' => ['required', Rule::enum(Role::class)],
            'agency_name' => [
                Rule::requiredIf(fn () => Role::tryFrom((string) $this->input('role')) === Role::AgencyOwner),
                Rule::prohibitedIf(fn () => Role::tryFrom((string) $this->input('role')) !== Role::AgencyOwner),
                'string',
                'max:160',
            ],
            'agency_id' => [
                Rule::requiredIf(fn () => Role::tryFrom((string) $this->input('role')) === Role::AgencyMember),
                Rule::prohibitedIf(fn () => Role::tryFrom((string) $this->input('role')) !== Role::AgencyMember),
                'nullable',
                'integer',
                'exists:agencies,id',
            ],
        ];
    }
}
