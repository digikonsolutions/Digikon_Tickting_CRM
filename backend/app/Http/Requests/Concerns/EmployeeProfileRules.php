<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Role;
use App\Models\EmployeeCertification;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSkill;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Validation shared by "create employee" and "update employee". Every profile field is optional
 * (`sometimes|nullable`) so an admin can save a draft and finish later; PATCH leaves absent keys alone.
 */
trait EmployeeProfileRules
{
    private const PHONE_REGEX = '/^\+?[0-9][0-9\s\-()]{6,19}$/';

    /** The employee being edited (null when creating); lets the CNIC uniqueness check ignore their own row. */
    abstract protected function editedEmployeeId(): ?int;

    protected function prepareProfileInput(): void
    {
        // 12345-1234567-1, spaces and dots all normalise to 13 digits; anything else is left to fail validation.
        $cnic = $this->input('cnic');
        if (is_string($cnic)) {
            $normalized = EmployeeProfile::normalizeCnic($cnic);
            $this->merge(['cnic' => $normalized ?? trim($cnic)]);
        }
    }

    /** @return array<string, mixed> */
    protected function profileRules(): array
    {
        $optional = ['sometimes', 'nullable'];
        $today = now()->toDateString();
        $tooYoung = now()->subYears(16)->toDateString();
        $nextYear = now()->addYear()->toDateString();
        $url = [...$optional, 'url:http,https', 'max:255'];

        return [
            'father_name' => [...$optional, 'string', 'max:120'],
            'date_of_birth' => [...$optional, 'date', 'after_or_equal:1940-01-01', "before_or_equal:{$tooYoung}"],
            'gender' => [...$optional, Rule::in(EmployeeProfile::GENDERS)],
            'cnic' => [...$optional, 'digits:13', function (string $attribute, mixed $value, \Closure $fail): void {
                $taken = EmployeeProfile::query()
                    ->where('cnic_hash', EmployeeProfile::hashCnic((string) $value))
                    ->when($this->editedEmployeeId(), fn ($query, int $id) => $query->where('user_id', '!=', $id))
                    ->exists();
                if ($taken) {
                    $fail('This CNIC already belongs to another employee.');
                }
            }],
            'phone' => [...$optional, 'regex:'.self::PHONE_REGEX],
            'whatsapp' => [...$optional, 'regex:'.self::PHONE_REGEX],
            'personal_email' => [...$optional, 'email', 'max:254'],
            'current_address' => [...$optional, 'string', 'max:500'],
            'permanent_address' => [...$optional, 'string', 'max:500'],
            'city' => [...$optional, 'string', 'max:100'],
            'emergency_contact_name' => [...$optional, 'string', 'max:120'],
            'emergency_contact_relation' => [...$optional, 'string', 'max:60'],
            'emergency_contact_phone' => [...$optional, 'regex:'.self::PHONE_REGEX],

            'headline' => [...$optional, 'string', 'max:120'],
            'bio' => [...$optional, 'string', 'max:1000'],
            'department' => [...$optional, Rule::in(EmployeeProfile::DEPARTMENTS)],
            'timezone' => [...$optional, 'timezone:all'],
            'availability' => ['sometimes', Rule::in(EmployeeProfile::AVAILABILITY)],
            'preferred_hours' => [...$optional, 'string', 'max:120'],
            'english_level' => [...$optional, 'string', 'max:30'],
            'portfolio_url' => $url,
            'github_url' => $url,
            'linkedin_url' => $url,

            'join_date' => [...$optional, 'date', 'after_or_equal:2000-01-01', "before_or_equal:{$nextYear}"],
            'employment_type' => [...$optional, Rule::in(EmployeeProfile::EMPLOYMENT_TYPES)],
            'employment_status' => ['sometimes', Rule::in(EmployeeProfile::EMPLOYMENT_STATUSES)],
            'probation_end_date' => [...$optional, 'date'],
            'contract_end_date' => [...$optional, 'date'],
            'team_lead_id' => [...$optional, 'integer', Rule::exists('users', 'id')->where(
                fn ($query) => $query->where('is_active', true)->whereIn('role', [Role::Admin->value, Role::TeamLead->value, Role::Employee->value])
            )],
            'weekly_capacity_hours' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'checklist' => ['sometimes', 'array'],
            'checklist.*' => ['boolean'],

            'pay_rate' => [...$optional, 'numeric', 'min:0', 'max:99999999'],
            'payment_method' => [...$optional, 'string', 'max:40'],
            'bank_name' => [...$optional, 'string', 'max:120'],
            'bank_account' => [...$optional, 'string', 'max:34'],
            'internal_notes' => [...$optional, 'string', 'max:5000'],

            'skills' => ['sometimes', 'nullable', 'array', 'max:30'],
            'skills.*.name' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'skills.*.level' => ['required', Rule::in(EmployeeSkill::LEVELS)],
            'skills.*.years_experience' => ['nullable', 'numeric', 'min:0', 'max:60'],

            'educations' => ['sometimes', 'nullable', 'array', 'max:20'],
            'educations.*.degree' => ['required', 'string', 'max:160'],
            'educations.*.institution' => ['required', 'string', 'max:160'],
            'educations.*.start_date' => ['nullable', 'date', "before_or_equal:{$today}"],
            'educations.*.end_date' => ['nullable', 'date', "before_or_equal:{$nextYear}"],
            'educations.*.description' => ['nullable', 'string', 'max:1000'],

            'experiences' => ['sometimes', 'nullable', 'array', 'max:20'],
            'experiences.*.title' => ['required', 'string', 'max:160'],
            'experiences.*.company' => ['required', 'string', 'max:160'],
            'experiences.*.start_date' => ['nullable', 'date', "before_or_equal:{$today}"],
            'experiences.*.end_date' => ['nullable', 'date', "before_or_equal:{$nextYear}"],
            'experiences.*.description' => ['nullable', 'string', 'max:1000'],

            'certifications' => ['sometimes', 'nullable', 'array', 'max:30'],
            'certifications.*.name' => ['required', 'string', 'max:160'],
            'certifications.*.issuer' => ['nullable', 'string', 'max:160'],
            'certifications.*.issued_at' => ['nullable', 'date', "before_or_equal:{$today}"],
            'certifications.*.expires_at' => ['nullable', 'date'],
            'certifications.*.credential_url' => ['nullable', 'url:http,https', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    protected function profileMessages(): array
    {
        return [
            'cnic.digits' => 'The CNIC must be 13 digits, for example 12345-1234567-1.',
            'phone.regex' => 'Enter a valid phone number, for example +92 300 1234567.',
            'whatsapp.regex' => 'Enter a valid WhatsApp number, for example +92 300 1234567.',
            'emergency_contact_phone.regex' => 'Enter a valid phone number, for example +92 300 1234567.',
            'date_of_birth.before_or_equal' => 'The employee must be at least 16 years old.',
            'skills.*.name.distinct' => 'Each skill can only be listed once.',
        ];
    }

    /** Cross-field date checks. They run only when both dates were sent, so a partial PATCH never trips them. */
    protected function validateProfileDates(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $order = function (string $earlier, string $later, string $key, string $message) use ($validator): void {
                $a = $this->input($earlier);
                $b = $this->input($later);
                if ($a && $b && strtotime((string) $b) < strtotime((string) $a)) {
                    $validator->errors()->add($key, $message);
                }
            };

            $order('join_date', 'probation_end_date', 'probation_end_date', 'The probation end date cannot be before the join date.');
            $order('join_date', 'contract_end_date', 'contract_end_date', 'The contract end date cannot be before the join date.');

            foreach ((array) $this->input('educations', []) as $i => $row) {
                $this->rowOrder($validator, $row, 'start_date', 'end_date', "educations.{$i}.end_date", 'The end date cannot be before the start date.');
            }
            foreach ((array) $this->input('experiences', []) as $i => $row) {
                $this->rowOrder($validator, $row, 'start_date', 'end_date', "experiences.{$i}.end_date", 'The end date cannot be before the start date.');
            }
            foreach ((array) $this->input('certifications', []) as $i => $row) {
                $this->rowOrder($validator, $row, 'issued_at', 'expires_at', "certifications.{$i}.expires_at", 'The expiry date cannot be before the issue date.');
            }
        });
    }

    /** @param  mixed  $row */
    private function rowOrder(Validator $validator, $row, string $from, string $to, string $key, string $message): void
    {
        if (is_array($row) && ! empty($row[$from]) && ! empty($row[$to]) && strtotime((string) $row[$to]) < strtotime((string) $row[$from])) {
            $validator->errors()->add($key, $message);
        }
    }

    /** @return list<string> */
    protected static function documentTypes(): array
    {
        return EmployeeDocument::TYPES;
    }
}
