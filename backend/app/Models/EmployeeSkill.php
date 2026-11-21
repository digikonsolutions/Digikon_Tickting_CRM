<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSkill extends Model
{
    public const LEVELS = ['beginner', 'intermediate', 'expert'];

    protected $fillable = ['user_id', 'name', 'level', 'years_experience'];

    protected function casts(): array
    {
        return ['years_experience' => 'decimal:1'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
