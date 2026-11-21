<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerProfile extends Model
{
    public const CONTACT_METHODS = ['email', 'phone', 'whatsapp'];

    protected $guarded = ['id', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}