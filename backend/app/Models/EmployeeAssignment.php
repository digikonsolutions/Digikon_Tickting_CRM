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
        'source',
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

    /**
     * Who may schedule work on this assignment: its holder, and any client of the same agency (clients cannot add
     * employees, so they work with the ones their agency has).
     */
    public function usableBy(User $user): bool
    {
        return $this->agency_id === $user->agency_id
            && ($this->holder_id === $user->id || $user->role === \App\Enums\Role::AgencyClient);
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
