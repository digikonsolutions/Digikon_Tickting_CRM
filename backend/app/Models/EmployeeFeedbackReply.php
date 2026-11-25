<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The employee's one reply to a piece of feedback about them. */
class EmployeeFeedbackReply extends Model
{
    protected $guarded = ['id'];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(EmployeeFeedback::class, 'feedback_id');
    }
}
