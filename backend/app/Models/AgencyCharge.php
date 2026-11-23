<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of what an agency owes for accepted rush work. Integer minor units plus a currency code.
 * Extension point: a payment gateway or invoice provider would read `unbilled` rows, create an invoice and move
 * them to `invoiced` / `paid`; nothing in the app charges a card.
 */
class AgencyCharge extends Model
{
    protected $fillable = ['agency_id', 'rush_request_id', 'description', 'amount_minor', 'currency', 'status'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function rushRequest(): BelongsTo
    {
        return $this->belongsTo(RushRequest::class);
    }
}
