<?php

namespace App\Http\Requests;

use App\Services\UserAccountService;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(UserAccountService::class)->canUseAccount($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:128', 'current_password'],
            // The same policy as the password reset flow, and it must actually be a new password.
            'password' => ['required', 'string', 'max:128', 'confirmed', 'different:current_password', UserAccountService::passwordRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The current password is incorrect.',
            'password.different' => 'Choose a password you have not used on this account just now.',
            'password.confirmed' => 'The new password and its confirmation do not match.',
        ];
    }
}