<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RushRequest extends Model
{
    public const TYPES = ['priority_insert', 'extra_employee'];

    public const OPEN = ['pending', 'quoted'];

    protected $fillable = [
        'agency_id', 'requested_by', 'type', 'assignment_id', 'title', 'description', 'skills_notes',
        'estimated_hours', 'needed_by', 'reason', 'status',
    ];

    protected function casts(): array
    {
        return [
            'estimated_hours' => 'decimal:2',
            'needed_by' => 'date',
            'quoted_amount_minor' => 'integer',
            'quote_expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'assignment_id');
    }

    public function quoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quoted_by');
    }

    public function proposedEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_employee_id');
    }

    public function charge(): HasOne
    {
        return $this->hasOne(AgencyCharge::class);
    }
}
