<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A decision on a submission: the internal check by our team first, then the agency's. */
class TicketReview extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TicketSubmission::class, 'submission_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}