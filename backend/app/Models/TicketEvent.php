<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class TicketEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'work_item_id',
        'actor_id',
        'actor_name',
        'actor_role',
        'type',
        'visibility',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Ticket events are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Ticket events are immutable and cannot be deleted.');
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
