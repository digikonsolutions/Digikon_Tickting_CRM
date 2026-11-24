<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer of an agency (the agency's own client). Scoped to one agency, never shared. */
class EndClient extends Model
{
    protected $fillable = ['agency_id', 'name', 'contact_name', 'contact_email', 'external_ref'];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
