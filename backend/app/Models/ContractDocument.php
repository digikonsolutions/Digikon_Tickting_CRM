<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A signed contract file on the private disk. `path` and `disk` never leave the server. */
class ContractDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path', 'disk'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(AgencyContract::class, 'agency_contract_id');
    }
}