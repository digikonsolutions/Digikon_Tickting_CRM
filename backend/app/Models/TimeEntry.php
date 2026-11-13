<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimeEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ticket_id', 'work_item_id', 'user_id', 'entry_date', 'minutes', 'started_at', 'ended_at',
        'note', 'source', 'auto_stopped', 'stop_reason', 'modified_reason',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'minutes' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'auto_stopped' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return $this->source === 'timer' && $this->minutes === null && $this->ended_at === null;
    }
}
