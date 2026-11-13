<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketAttachment extends Model
{
    use SoftDeletes;

    public const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id',
        'comment_event_id',
        'uploaded_by',
        'uploader_name',
        'original_name',
        'stored_path',
        'disk',
        'mime_type',
        'size_bytes',
        'sha256',
        'is_internal',
    ];

    protected $hidden = ['stored_path', 'disk', 'sha256'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_internal' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
