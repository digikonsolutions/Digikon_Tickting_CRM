<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The numbers an admin edits on the settings screen (SLA targets, hours, holidays, windows). They sit in the database and
 * override config/support.php, which stays the default. Loaded once per request; saving clears the cache.
 */
class SupportSettings
{
    private const CACHE = 'support.settings';

    /** Overlays the saved values onto config('support'). Safe before the table exists (fresh installs, first migrate). */
    public static function apply(): void
    {
        try {
            if (! Schema::hasTable('support_settings')) {
                return;
            }
            $saved = Cache::remember(self::CACHE, 60, fn (): array => DB::table('support_settings')->pluck('value', 'key')->map(fn ($v) => json_decode((string) $v, true))->all());
        } catch (\Throwable) {
            return;
        }
        foreach ($saved as $key => $value) {
            // The screen always saves a whole structure, so a saved value replaces the default outright (a shorter holiday list stays shorter).
            config(["support.{$key}" => $value]);
        }
    }

    /** @return array<string, mixed> what the screen shows: the effective values */
    public static function current(): array
    {
        return collect(self::KEYS)->mapWithKeys(fn (string $key): array => [$key => config("support.{$key}")])->all();
    }

    public const KEYS = ['sla', 'hours', 'timezone', 'holidays', 'at_risk_ratio', 'pause_while_waiting_for_agency', 'unassigned_escalation_minutes', 'reopen_days', 'auto_close_days', 'waiting_agency_reminder_business_days', 'waiting_agency_stalled_business_days'];

    /** @param  array<string, mixed>  $values */
    public static function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::KEYS, true)) {
                DB::table('support_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
            }
        }
        Cache::forget(self::CACHE);
        self::apply();
    }
}
