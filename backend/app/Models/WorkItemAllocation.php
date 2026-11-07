<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkItemAllocation extends Model
{
    protected $fillable = [
        'work_item_id',
        'employee_id',
        'work_date',
        'hours',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'work_date' => 'date',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
