<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSession extends Model
{
    protected $fillable = [
        'employee_id', 'started_at', 'last_heartbeat_at', 'ended_at', 'ended_reason', 'active_seconds', 'idle_seconds', 'is_idle',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'ended_at' => 'datetime',
            'active_seconds' => 'integer',
            'idle_seconds' => 'integer',
            'is_idle' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
