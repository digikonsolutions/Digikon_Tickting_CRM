<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketDetail extends Model
{
    protected $guarded = ['id', 'ticket_id'];

    protected function casts(): array
    {
        return [
            'labels' => 'array', 'requirements' => 'array', 'ghl_refs' => 'array', 'inputs' => 'array', 'depends_on' => 'array',
            'testing' => 'array', 'delivery' => 'array', 'review' => 'array', 'inputs_ready' => 'boolean', 'blocked' => 'boolean',
            'affects_live_traffic' => 'boolean', 'requires_approval' => 'boolean', 'delivery_submitted_at' => 'datetime',
            'checklist_total' => 'integer', 'checklist_done' => 'integer', 'completeness' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
