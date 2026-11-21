<?php

namespace App\Http\Resources;

use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\EmployeeProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full employee record for administrators. The CNIC and bank account are always masked here; the full
 * CNIC comes only from POST /admin/employees/{id}/reveal-cnic, which writes an audit log row.
 *
 * @property User $resource
 */
class AdminEmployeeProfileResource extends JsonResource
{
    public const EXPIRING_SOON_DAYS = 30;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $employee = $this->resource;
        $profiles = app(EmployeeProfileService::class);
        $employee->loadMissing(['employeeProfile.teamLead', 'skills', 'educations', 'experiences', 'certifications', 'employeeDocuments.verifier']);
        $p = $employee->employeeProfile;

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'is_active' => $employee->is_active,
            'photo_url' => $employee->photoUrl(),
            'completeness' => $profiles->completeness($employee),
            'profile' => [
                'employee_code' => $p?->employee_code,
                // Personal (admin only)
                'father_name' => $p?->father_name,
                'date_of_birth' => $p?->date_of_birth?->toDateString(),
                'gender' => $p?->gender,
                'cnic_masked' => $p?->cnicMasked(),
                'has_cnic' => $p !== null && filled($p->cnic),
                'phone' => $p?->phone,
                'whatsapp' => $p?->whatsapp,
                'personal_email' => $p?->personal_email,
                'current_address' => $p?->current_address,
                'permanent_address' => $p?->permanent_address,
                'city' => $p?->city,
                'emergency_contact_name' => $p?->emergency_contact_name,
                'emergency_contact_relation' => $p?->emergency_contact_relation,
                'emergency_contact_phone' => $p?->emergency_contact_phone,
                // Public
                'headline' => $p?->headline,
                'bio' => $p?->bio,
                'department' => $p?->department,
                'timezone' => $p?->timezone,
                'availability' => $p?->availability ?? 'available',
                'preferred_hours' => $p?->preferred_hours,
                'english_level' => $p?->english_level,
                'portfolio_url' => $p?->portfolio_url,
                'github_url' => $p?->github_url,
                'linkedin_url' => $p?->linkedin_url,
                // Employment
                'join_date' => $p?->join_date?->toDateString(),
                'employment_type' => $p?->employment_type,
                'employment_status' => $p?->employment_status ?? 'active',
                'probation_end_date' => $p?->probation_end_date?->toDateString(),
                'contract_end_date' => $p?->contract_end_date?->toDateString(),
                'team_lead' => $p?->teamLead ? ['id' => $p->teamLead->id, 'name' => $p->teamLead->name] : null,
                'team_lead_id' => $p?->team_lead_id,
                'weekly_capacity_hours' => $p?->weekly_capacity_hours ?? 36,
                'checklist' => $p?->checklistState() ?? array_fill_keys(\App\Models\EmployeeProfile::CHECKLIST_KEYS, false),
                // Finance and notes (admin only)
                'pay_rate' => $p?->pay_rate !== null ? (float) $p->pay_rate : null,
                'payment_method' => $p?->payment_method,
                'bank_name' => $p?->bank_name,
                'bank_account_masked' => $p?->bankAccountMasked(),
                'internal_notes' => $p?->internal_notes,
            ],
            'skills' => $employee->skills->map(fn ($s): array => [
                'name' => $s->name,
                'level' => $s->level,
                'years_experience' => $s->years_experience !== null ? (float) $s->years_experience : null,
            ])->values(),
            'educations' => $employee->educations->map(fn ($e): array => [
                'degree' => $e->degree,
                'institution' => $e->institution,
                'start_date' => $e->start_date?->toDateString(),
                'end_date' => $e->end_date?->toDateString(),
                'description' => $e->description,
            ])->values(),
            'experiences' => $employee->experiences->map(fn ($e): array => [
                'title' => $e->title,
                'company' => $e->company,
                'start_date' => $e->start_date?->toDateString(),
                'end_date' => $e->end_date?->toDateString(),
                'description' => $e->description,
            ])->values(),
            'certifications' => $employee->certifications->map(fn ($c): array => [
                'name' => $c->name,
                'issuer' => $c->issuer,
                'issued_at' => $c->issued_at?->toDateString(),
                'expires_at' => $c->expires_at?->toDateString(),
                'credential_url' => $c->credential_url,
            ])->values(),
            'documents' => $employee->employeeDocuments->map(fn (EmployeeDocument $d): array => self::documentPayload($d))->values(),
        ];
    }

    /** @return array<string, mixed> storage path and disk are never included */
    public static function documentPayload(EmployeeDocument $document): array
    {
        $today = now()->startOfDay();
        $expires = $document->expires_at;

        return [
            'id' => $document->id,
            'type' => $document->type,
            'name' => $document->original_name,
            'mime_type' => $document->mime,
            'size_bytes' => $document->size,
            'is_image' => str_starts_with($document->mime, 'image/'),
            'expires_at' => $expires?->toDateString(),
            'expired' => $expires !== null && $expires->lt($today),
            'expiring_soon' => $expires !== null && ! $expires->lt($today) && $expires->lte($today->copy()->addDays(self::EXPIRING_SOON_DAYS)),
            'verified' => $document->verified_at !== null,
            'verified_at' => $document->verified_at?->toISOString(),
            'verified_by' => $document->verifier?->name,
            'created_at' => $document->created_at?->toISOString(),
        ];
    }
}
