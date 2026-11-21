<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The ONLY employee shape agency owners and clients ever receive. It is a strict whitelist: nothing is
 * spread from the model, so a column added to employee_profiles later stays private until it is listed here.
 * Deliberately absent: email, phone, CNIC, addresses, bank, pay rate, notes, documents, checklist.
 *
 * @property User $resource
 */
class PublicEmployeeProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $employee = $this->resource;
        $employee->loadMissing(['employeeProfile', 'skills', 'educations', 'experiences', 'certifications']);
        $p = $employee->employeeProfile;

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'photo_url' => $employee->photoUrl(),
            'headline' => $p?->headline,
            'bio' => $p?->bio,
            'department' => $p?->department,
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
            'english_level' => $p?->english_level,
            'timezone' => $p?->timezone,
            'availability' => $p?->availability ?? 'available',
            'preferred_hours' => $p?->preferred_hours,
            'portfolio_url' => $p?->portfolio_url,
            'github_url' => $p?->github_url,
            'linkedin_url' => $p?->linkedin_url,
            'weekly_capacity_hours' => $p?->weekly_capacity_hours ?? 36,
        ];
    }
}
