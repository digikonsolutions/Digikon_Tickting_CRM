<?php

namespace App\Services;

/** The switches and limits for work snapshots, read from config/activity.php in one place. */
class SnapshotPolicy
{
    public function enabled(): bool
    {
        return (bool) config('activity.snapshots.enabled');
    }

    public function disk(): string
    {
        return (string) config('activity.snapshots.disk');
    }

    public function maxKb(): int
    {
        return (int) config('activity.snapshots.max_kb');
    }

    public function minSpacingSeconds(): int
    {
        return (int) floor((int) config('activity.snapshots.min_minutes') * 60 / 2);
    }

    /** @return list<string> */
    public function domains(): array
    {
        return array_values(array_map('strtolower', (array) config('activity.snapshots.allowed_domains')));
    }

    /** @return list<string> */
    public function blocked(): array
    {
        return array_values(array_map('strtolower', (array) config('activity.snapshots.blocked_domains')));
    }

    private function matches(string $domain, array $list): bool
    {
        foreach ($list as $item) {
            if ($domain === $item || str_ends_with($domain, '.'.$item)) {
                return true;
            }
        }

        return false;
    }

    /** Any website, unless it is blocked; when an allow list is set, only those sites and their subdomains. */
    public function domainAllowed(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || $this->matches($domain, $this->blocked())) {
            return false;
        }
        $allowed = $this->domains();

        return $allowed === [] || $this->matches($domain, $allowed);
    }

    /** Working hours: the configured days and time window in the application timezone. */
    public function inWorkingHours(?\Carbon\CarbonInterface $at = null): bool
    {
        $at = ($at ?? now())->copy()->setTimezone(config('app.timezone'));
        if (! in_array($at->dayOfWeekIso, (array) config('activity.snapshots.days'), true)) {
            return false;
        }
        $time = $at->format('H:i');

        return $time >= (string) config('activity.snapshots.hours_start') && $time < (string) config('activity.snapshots.hours_end');
    }

    public function hoursLabel(): string
    {
        return config('activity.snapshots.hours_start').' to '.config('activity.snapshots.hours_end');
    }

    /** What the extension needs to know when it checks in. */
    public function forExtension(): array
    {
        return [
            'enabled' => $this->enabled(),
            'min_minutes' => (int) config('activity.snapshots.min_minutes'),
            'max_minutes' => (int) config('activity.snapshots.max_minutes'),
            'allowed_domains' => $this->domains(),
            'blocked_domains' => $this->blocked(),
            'max_kb' => $this->maxKb(),
        ];
    }
}