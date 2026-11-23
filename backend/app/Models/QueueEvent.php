<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['agency_id', 'employee_id', 'work_item_id', 'actor_id', 'type', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
