<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientProfile extends Model
{
    public const CONTACT_METHODS = ['email', 'phone', 'whatsapp'];

    /** Fields an agency owner may set. `notes` are internal: only the owner (and admins) ever read them. */
    public const FIELDS = ['company_name', 'job_title', 'website', 'phone', 'preferred_contact_method', 'timezone', 'address', 'country', 'notes'];

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
