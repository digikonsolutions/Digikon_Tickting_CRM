<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** An agency owner's feedback about an employee they held. */
class EmployeeFeedback extends Model
{
    protected $table = 'employee_feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ratings' => 'array', 'highlights' => 'array', 'concerns' => 'array', 'rating' => 'integer',
            'editable_until' => 'datetime', 'removed_at' => 'datetime',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function reply(): HasOne
    {
        return $this->hasOne(EmployeeFeedbackReply::class, 'feedback_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function isEditable(): bool
    {
        return $this->status === 'published' && $this->editable_until->isFuture();
    }
}
