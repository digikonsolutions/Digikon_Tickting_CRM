<?php

namespace App\Http\Requests;

use App\Services\UserAccountService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service profile update, the same for every role. Only the display name can change; email,
 * role, agency, activation and password have their own (admin-only or dedicated) flows.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Deactivated users and users of a suspended agency normally have no session at all; this is the backstop.
        $user = $this->user();

        return $user !== null && app(UserAccountService::class)->canUseAccount($user);
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            // Trim and collapse any run of whitespace (including non-breaking spaces) to a single space.
            $this->merge(['name' => trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $name))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['prohibited'],
            'role' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name.',
            'name.max' => 'Your name may not be longer than 120 characters.',
            'email.prohibited' => 'Your email address can only be changed by an administrator.',
            'password.prohibited' => 'Use the change password form to set a new password.',
        ];
    }
}