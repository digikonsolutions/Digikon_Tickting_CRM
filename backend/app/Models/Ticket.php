<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'work_item_id',
        'created_by',
        'title',
        'description',
        'estimated_hours',
        'priority',
        'status',
        'position',
        'start_date',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'estimated_hours' => 'decimal:2',
            'position' => 'integer',
            'start_date' => 'date',
            'due_date' => 'date',
        ];
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
