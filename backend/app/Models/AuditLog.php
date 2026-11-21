<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/** Append-only trail of who looked at or changed sensitive employee data. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['viewed_sensitive', 'downloaded_document', 'updated_profile', 'revealed_cnic'];

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'ip'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function record(User $actor, string $action, Model $subject, ?Request $request = null): self
    {
        $request ??= request();

        return self::create([
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'ip' => $request?->ip(),
        ]);
    }
}
