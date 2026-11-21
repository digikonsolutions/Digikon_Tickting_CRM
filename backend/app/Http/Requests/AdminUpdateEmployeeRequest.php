<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\EmployeeProfileRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update an employee's profile. The login email and active flag stay on PATCH /admin/users/{id}, which
 * already handles the re-invitation and the cascading effects of deactivation.
 */
class AdminUpdateEmployeeRequest extends FormRequest
{
    use EmployeeProfileRules;

    protected function editedEmployeeId(): ?int
    {
        return (int) $this->route('id');
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        if (is_string($name)) {
            $this->merge(['name' => \App\Support\NameNormalizer::person($name)]);
        }
        $this->prepareProfileInput();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['prohibited'],
            'role' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            ...$this->profileRules(),
        ];
    }

    public function messages(): array
    {
        return $this->profileMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateProfileDates($validator);
    }
}
