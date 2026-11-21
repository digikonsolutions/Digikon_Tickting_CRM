<?php

namespace App\Services;

use App\Models\EmployeeCertification;
use App\Models\EmployeeEducation;
use App\Models\EmployeeExperience;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EmployeeProfileService
{
    /** Scalar profile columns an admin request may set (cnic and employee_code are handled separately). */
    private const FIELDS = [
        'father_name', 'date_of_birth', 'gender', 'phone', 'whatsapp', 'personal_email',
        'current_address', 'permanent_address', 'city',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
        'headline', 'bio', 'department', 'timezone', 'availability', 'preferred_hours', 'english_level',
        'portfolio_url', 'github_url', 'linkedin_url',
        'join_date', 'employment_type', 'employment_status', 'probation_end_date', 'contract_end_date',
        'team_lead_id', 'weekly_capacity_hours',
        'pay_rate', 'payment_method', 'bank_name', 'bank_account', 'internal_notes',
    ];

    /** The profile row, created (with its EMP-0001 style code) the first time it is needed. */
    public function profileFor(User $employee): EmployeeProfile
    {
        $profile = $employee->employeeProfile;
        if ($profile) {
            return $profile;
        }

        return DB::transaction(function () use ($employee): EmployeeProfile {
            $profile = $employee->employeeProfile()->create([]);
            // The primary key is unique, so a code derived from it can never collide.
            $profile->forceFill(['employee_code' => sprintf('EMP-%04d', $profile->id)])->save();
            $employee->setRelation('employeeProfile', $profile);

            return $profile;
        });
    }

    /**
     * Applies a validated payload. Keys that are absent are left alone; a list key (skills, educations,
     * experiences, certifications) replaces the whole list. Call inside the caller's transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(User $employee, array $data): EmployeeProfile
    {
        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $employee->forceFill(['name' => $data['name']])->save();
        }

        $profile = $this->profileFor($employee);
        $profile->fill(array_intersect_key($data, array_flip(self::FIELDS)));

        if (array_key_exists('cnic', $data)) {
            $profile->setCnic($data['cnic'] === null ? null : EmployeeProfile::normalizeCnic($data['cnic']));
        }
        if (array_key_exists('checklist', $data) && is_array($data['checklist'])) {
            $profile->checklist = array_merge($profile->checklistState(), array_map('boolval', $data['checklist']));
        }
        $profile->save();

        if (array_key_exists('skills', $data)) {
            $this->replace($data['skills'] ?? [], EmployeeSkill::class, $employee, ['name', 'level', 'years_experience']);
        }
        if (array_key_exists('educations', $data)) {
            $this->replace($data['educations'] ?? [], EmployeeEducation::class, $employee, ['degree', 'institution', 'start_date', 'end_date', 'description']);
        }
        if (array_key_exists('experiences', $data)) {
            $this->replace($data['experiences'] ?? [], EmployeeExperience::class, $employee, ['title', 'company', 'start_date', 'end_date', 'description']);
        }
        if (array_key_exists('certifications', $data)) {
            $this->replace($data['certifications'] ?? [], EmployeeCertification::class, $employee, ['name', 'issuer', 'issued_at', 'expires_at', 'credential_url']);
        }

        foreach (['skills', 'educations', 'experiences', 'certifications', 'employeeDocuments'] as $relation) {
            $employee->unsetRelation($relation);
        }

        return $profile;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  list<string>  $columns
     */
    private function replace(array $rows, string $model, User $employee, array $columns): void
    {
        $model::query()->where('user_id', $employee->id)->delete();
        foreach ($rows as $row) {
            $model::create(['user_id' => $employee->id, ...array_intersect_key($row, array_flip($columns))]);
        }
    }

    /**
     * Server-side completeness: share of the checks below that are satisfied, plus what is still missing.
     *
     * @return array{percent: int, done: int, total: int, missing: list<string>}
     */
    public function completeness(User $employee): array
    {
        $employee->loadMissing(['employeeProfile', 'skills', 'educations', 'experiences', 'employeeDocuments']);
        $p = $employee->employeeProfile;
        $docTypes = $employee->employeeDocuments->pluck('type')->all();
        $filled = fn (string $field): bool => $p !== null && filled($p->{$field});

        $checks = [
            'Phone number' => $filled('phone'),
            'CNIC' => $filled('cnic'),
            'Date of birth' => $filled('date_of_birth'),
            'Current address' => $filled('current_address'),
            'Emergency contact' => $filled('emergency_contact_name') && $filled('emergency_contact_phone'),
            'Profile photo' => $employee->photoUrl() !== null,
            'Headline' => $filled('headline'),
            'Bio' => $filled('bio'),
            'Department' => $filled('department'),
            'Time zone' => $filled('timezone'),
            'Skills' => $employee->skills->isNotEmpty(),
            'Education' => $employee->educations->isNotEmpty(),
            'Experience' => $employee->experiences->isNotEmpty(),
            'Join date' => $filled('join_date'),
            'Employment type' => $filled('employment_type'),
            'CNIC (front)' => in_array('cnic_front', $docTypes, true),
            'CNIC (back)' => in_array('cnic_back', $docTypes, true),
            'CV' => in_array('cv', $docTypes, true),
        ];
        $done = count(array_filter($checks));

        return [
            'percent' => (int) round($done / count($checks) * 100),
            'done' => $done,
            'total' => count($checks),
            'missing' => array_keys(array_filter($checks, fn (bool $ok): bool => ! $ok)),
        ];
    }

    /** @return list<string> the strongest skills first, for compact cards */
    public function topSkills(User $employee, int $limit = 3): array
    {
        $rank = ['expert' => 0, 'intermediate' => 1, 'beginner' => 2];

        return $employee->skills->sortBy(fn (EmployeeSkill $s): array => [$rank[$s->level] ?? 3, -1 * (float) $s->years_experience, $s->name])
            ->take($limit)->pluck('name')->values()->all();
    }

    /**
     * Compact admin view for the employees list. Never contains personal, finance or document data.
     *
     * @return array<string, mixed>
     */
    public function adminSummary(User $employee): array
    {
        $employee->loadMissing('employeeProfile');
        $p = $employee->employeeProfile;

        return [
            'employee_code' => $p?->employee_code,
            'department' => $p?->department,
            'availability' => $p?->availability ?? 'available',
            'employment_status' => $p?->employment_status ?? 'active',
            'completeness' => $this->completeness($employee)['percent'],
        ];
    }

    /**
     * What an agency sees on the Hold Employee list: the same whitelist as the public profile, shortened.
     *
     * @return array<string, mixed>
     */
    public function publicSummary(User $employee): array
    {
        $employee->loadMissing(['employeeProfile', 'skills']);
        $p = $employee->employeeProfile;

        return [
            'photo_url' => $employee->photoUrl(),
            'headline' => $p?->headline,
            'department' => $p?->department,
            'availability' => $p?->availability ?? 'available',
            'top_skills' => $this->topSkills($employee),
        ];
    }
}

