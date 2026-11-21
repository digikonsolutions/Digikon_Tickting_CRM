<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\EmployeeProfileRules;
use App\Models\EmployeeDocument;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create an employee. Only name and email are required; everything else can be completed later. */
class AdminStoreEmployeeRequest extends FormRequest
{
    use EmployeeProfileRules;

    protected function editedEmployeeId(): ?int
    {
        return null;
    }

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
        $this->prepareProfileInput();
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
            'role' => ['prohibited'],
            'agency_id' => ['prohibited'],
            ...$this->profileRules(),
            'documents' => ['sometimes', 'nullable', 'array', 'max:20'],
            'documents.*.file' => ['required', 'file'],
            'documents.*.type' => ['required', Rule::in(EmployeeDocument::TYPES)],
            'documents.*.expires_at' => ['nullable', 'date'],
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
