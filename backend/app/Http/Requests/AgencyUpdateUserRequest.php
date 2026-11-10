<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgencyUpdateUserRequest extends FormRequest
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
        $userId = $this->route('agencyUser');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:254',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role' => ['sometimes', 'required', Rule::in([Role::AgencyClient->value])],
            'is_active' => ['sometimes', 'boolean'],
            'agency_id' => ['prohibited'],
        ];
    }
}
