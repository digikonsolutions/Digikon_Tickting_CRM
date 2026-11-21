<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The activity log of a project's (or ticket's) timeline review. Rows are never edited. */
class TimelineEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['proposed', 'review_started', 'edited', 'changes_requested', 'confirmed', 'reopened', 'reminder_sent', 'due_date_changed'];

    protected $fillable = ['work_item_id', 'subject_type', 'subject_id', 'actor_id', 'actor_name', 'actor_role', 'type', 'before', 'after', 'note'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }
}
