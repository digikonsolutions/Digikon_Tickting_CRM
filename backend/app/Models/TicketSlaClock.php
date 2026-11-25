<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSlaClock extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'due_at' => 'datetime', 'paused_at' => 'datetime', 'met_at' => 'datetime',
            'at_risk_at' => 'datetime', 'breached_at' => 'datetime', 'target_minutes' => 'integer', 'remaining_minutes' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
