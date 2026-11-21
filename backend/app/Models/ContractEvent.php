<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['agency_contract_id', 'user_id', 'type', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}