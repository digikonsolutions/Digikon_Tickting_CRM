<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only. Rows are inserted by the extension sync and removed only by retention pruning (a query
 * delete). Updating or deleting a model instance is refused here as well as in routes and policy, so no
 * code path can quietly edit someone's history.
 */
class BrowserHistoryEvent extends Model
{
    public const TYPES = ['tab_opened', 'tab_closed', 'tab_activated', 'navigated'];

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'client_event_id', 'event_type', 'tab_session_id', 'tab_id', 'url', 'domain',
        'title', 'favicon_url', 'duration_seconds', 'occurred_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Browser history is append-only.'));
        static::deleting(fn () => throw new LogicException('Browser history is append-only.'));
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
