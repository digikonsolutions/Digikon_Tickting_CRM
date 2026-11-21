<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateUserRequest extends FormRequest
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
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:254',
                Rule::unique('users', 'email')->ignore($this->route('adminUser')),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['prohibited'],
            'agency_id' => ['prohibited'],
        ];
    }
}
