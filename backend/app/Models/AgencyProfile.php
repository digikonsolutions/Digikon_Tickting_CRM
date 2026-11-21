<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyProfile extends Model
{
    public const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-500', '500+'];

    protected $guarded = ['id', 'agency_id'];

    protected $hidden = ['tax_number', 'internal_notes'];

    protected function casts(): array
    {
        return ['tax_number' => 'encrypted'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    /** ****1234: only the last four characters of the tax number. */
    public function taxNumberMasked(): ?string
    {
        $value = (string) $this->tax_number;

        return $value === '' ? null : str_repeat('*', max(strlen($value) - 4, 4)).substr($value, -4);
    }
}