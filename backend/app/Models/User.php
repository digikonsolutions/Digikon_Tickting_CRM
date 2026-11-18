<?php

namespace App\Models;

use App\Enums\Role;
use App\Notifications\SetPasswordNotification;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'agency_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** Public URL of the profile photo, or null when the user has none (the UI shows an initial instead). */
    public function photoUrl(): ?string
    {
        // Photos live in Cloudinary now (avatar_url); older local uploads (photo_path) keep working.
        return $this->avatar_url ?: ($this->photo_path ? Storage::disk('public')->url($this->photo_path) : null);
    }

    public function heldEmployeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class, 'holder_id');
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class, 'employee_id');
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new SetPasswordNotification($token));
    }
}
