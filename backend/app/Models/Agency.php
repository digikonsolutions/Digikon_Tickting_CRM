<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Agency extends Model
{
    protected $fillable = [
        'name',
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
