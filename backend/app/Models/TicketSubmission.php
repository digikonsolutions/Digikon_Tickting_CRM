<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One time the employee sent a ticket's work for review, with what the reviewer needs to check it. */
class TicketSubmission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['links' => 'array', 'visible_to_agency' => 'boolean', 'time_minutes' => 'integer'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TicketReview::class, 'submission_id')->orderBy('id');
    }
}