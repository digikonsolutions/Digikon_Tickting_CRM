<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Everything ClientHub knows about an employee beyond the login account. Most of it is admin-only;
 * the fields agency users may see are whitelisted in App\Http\Resources\PublicEmployeeProfileResource.
 */
class EmployeeProfile extends Model
{
    public const DEPARTMENTS = ['ghl', 'web', 'software', 'design', 'qa', 'other'];
    public const AVAILABILITY = ['available', 'partial', 'unavailable'];
    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract'];
    public const EMPLOYMENT_STATUSES = ['probation', 'active', 'notice', 'left'];
    public const GENDERS = ['male', 'female', 'other', 'prefer_not_to_say'];
    public const CHECKLIST_KEYS = ['nda_signed', 'cnic_verified', 'equipment_given', 'accounts_created'];

    protected $guarded = ['id', 'user_id', 'cnic', 'cnic_hash', 'employee_code'];

    /** Sensitive columns never leave the model through toArray()/toJson(), even by accident. */
    protected $hidden = ['cnic', 'cnic_hash', 'bank_account', 'pay_rate', 'internal_notes'];

    protected function casts(): array
    {
        return [
            'cnic' => 'encrypted',
            'bank_account' => 'encrypted',
            'date_of_birth' => 'date',
            'join_date' => 'date',
            'probation_end_date' => 'date',
            'contract_end_date' => 'date',
            'checklist' => 'array',
            'pay_rate' => 'decimal:2',
            'weekly_capacity_hours' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_lead_id');
    }

    /** Digits only, or null when the value is not a 13-digit CNIC. Accepts 12345-1234567-1. */
    public static function normalizeCnic(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return strlen($digits) === 13 ? $digits : null;
    }

    public static function hashCnic(string $digits): string
    {
        return hash_hmac('sha256', $digits, (string) config('app.key'));
    }

    /** Stores the CNIC encrypted, plus a keyed hash so uniqueness can be checked without decrypting. */
    public function setCnic(?string $digits): void
    {
        $this->cnic = $digits;
        $this->cnic_hash = $digits === null ? null : self::hashCnic($digits);
    }

    /** 34101-*******-1 (first five and last digit only). */
    public function cnicMasked(): ?string
    {
        $digits = $this->cnic;

        return $digits ? substr($digits, 0, 5).'-*******-'.substr($digits, -1) : null;
    }

    /** CNIC formatted as 12345-1234567-1 for the one place that reveals it. */
    public function cnicFormatted(): ?string
    {
        $digits = $this->cnic;

        return $digits ? substr($digits, 0, 5).'-'.substr($digits, 5, 7).'-'.substr($digits, 12, 1) : null;
    }

    /** ******1234: only the last four characters. */
    public function bankAccountMasked(): ?string
    {
        $account = (string) $this->bank_account;
        if ($account === '') {
            return null;
        }

        return str_repeat('*', max(strlen($account) - 4, 4)).substr($account, -4);
    }

    /** @return array<string, bool> */
    public function checklistState(): array
    {
        $saved = (array) $this->checklist;

        return collect(self::CHECKLIST_KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => (bool) ($saved[$key] ?? false)])
            ->all();
    }
}
