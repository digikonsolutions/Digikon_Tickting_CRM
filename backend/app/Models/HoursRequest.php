<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoursRequest extends Model
{
    protected $fillable = ['ticket_id', 'work_item_id', 'agency_id', 'requested_by', 'hours', 'reason', 'status', 'decided_by', 'decision_note', 'decided_at'];

    protected function casts(): array
    {
        return ['hours' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
