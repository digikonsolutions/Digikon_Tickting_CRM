<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Agency extends Model
{
    use HasFactory;

    /** draft: created from the wizard but not yet activated (no invite sent, owner cannot sign in). */
    public const STATUSES = ['draft', 'active', 'suspended'];

    /** Portal slugs that would collide with real routes or look official. */
    public const RESERVED_SLUGS = ['admin', 'api', 'login', 'a', 'assets', 'storage', 'up', 'www', 'app', 'support', 'static', 'public'];

    protected $fillable = [
        'name',
        'slug',
        'status',
        'ticket_prefix',
        'ticket_sequence',
    ];

    protected function casts(): array
    {
        return [
            'ticket_sequence' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Agency $agency): void {
            if (blank($agency->slug)) {
                $agency->slug = self::generateSlug((string) $agency->name);
            }
        });

        static::updating(function (Agency $agency): void {
            if ($agency->isDirty('ticket_prefix') && $agency->tickets()->exists()) {
                throw new DomainException('The ticket prefix is immutable once tickets exist.');
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(AgencyProfile::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(AgencyContract::class);
    }

    public function branding(): HasOne
    {
        return $this->hasOne(AgencyBranding::class);
    }

    /** A unique portal slug: lowercase letters, digits and hyphens, 3 to 40 characters, never a reserved word. */
    public static function generateSlug(string $name, ?int $ignoreId = null): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = substr($base, 0, 34);
        if (strlen($base) < 3) {
            $base = str_pad($base === '' ? 'team' : $base, 3, 'x');
        }
        $candidate = $base;
        $n = 2;
        while (in_array($candidate, self::RESERVED_SLUGS, true) || DB::table('agencies')->where('slug', $candidate)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $candidate = $base.'-'.$n++;
        }

        return $candidate;
    }

    public static function generatePrefix(string $name, ?int $agencyId = null): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
        if (strlen($clean) < 2) {
            $clean = str_pad($clean, 2, 'X');
        }
        $base = substr($clean, 0, min(4, strlen($clean)));
        if (strlen($base) < 2) {
            $base = str_pad($base, 2, 'X');
        }

        $candidate = $base;
        $suffix = 1;
        while (DB::table('agencies')
            ->where('ticket_prefix', $candidate)
            ->when($agencyId, fn ($query) => $query->where('id', '!=', $agencyId))
            ->exists()
        ) {
            $suffixStr = (string) $suffix;
            $maxBaseLen = 6 - strlen($suffixStr);
            $candidate = substr($base, 0, $maxBaseLen).$suffixStr;
            $suffix++;
        }

        return $candidate;
    }
}
