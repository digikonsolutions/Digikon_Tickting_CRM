<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'agency_id',
        'work_item_id',
        'created_by',
        'number',
        'title',
        'description',
        'estimated_hours',
        'priority',
        'status',
        'position',
        'start_date',
        'due_date',
    ];

    protected $appends = [
        'key',
    ];

    protected function casts(): array
    {
        return [
            'agency_id' => 'integer',
            'number' => 'integer',
            'estimated_hours' => 'decimal:2',
            'position' => 'integer',
            'start_date' => 'date',
            'due_date' => 'date',
        ];
    }

    protected function key(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $prefix = $this->relationLoaded('agency')
                    ? $this->agency?->ticket_prefix
                    : ($this->relationLoaded('workItem') && $this->workItem?->relationLoaded('agency')
                        ? $this->workItem->agency?->ticket_prefix
                        : ($this->agency_id ? Agency::query()->where('id', $this->agency_id)->value('ticket_prefix') : null));

                return ($prefix && $this->number) ? "{$prefix}-{$this->number}" : null;
            }
        );
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TicketAllocation::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }
}
