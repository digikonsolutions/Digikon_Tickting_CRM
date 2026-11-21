<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A private file on an employee's record. `path` and `disk` are internal and never serialised. */
class EmployeeDocument extends Model
{
    public const TYPES = ['cnic_front', 'cnic_back', 'cv', 'degree', 'experience_letter', 'contract', 'nda', 'other'];

    protected $guarded = ['id'];

    protected $hidden = ['path', 'disk'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'verified_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
