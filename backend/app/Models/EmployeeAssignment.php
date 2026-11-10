<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAssignment extends Model
{
    protected $fillable = [
        'holder_id',
        'agency_id',
        'employee_id',
        'status',
        'active_employee_id',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'assignment_id');
    }
}
