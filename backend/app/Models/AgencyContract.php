<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyContract extends Model
{
    public const STATUSES = ['trial', 'active', 'paused', 'expired', 'cancelled'];

    /** The statuses of which an agency may have at most one. */
    public const LIVE = ['trial', 'active'];

    protected $guarded = ['id', 'live_agency_id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'trial_end_date' => 'date',
            'auto_renew' => 'boolean',
            'features' => 'array',
            'hours_per_month' => 'decimal:2',
            'price' => 'decimal:2',
            'overage_rate' => 'decimal:2',
            'discount_percent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Keeps the unique "one live contract per agency" column in step with the status.
        static::saving(function (AgencyContract $contract): void {
            $contract->live_agency_id = in_array($contract->status, self::LIVE, true) ? $contract->agency_id : null;
        });
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContractEvent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function record(string $type, ?User $actor = null, array $data = []): ContractEvent
    {
        return $this->events()->create(['type' => $type, 'user_id' => $actor?->id, 'data' => $data ?: null]);
    }
}