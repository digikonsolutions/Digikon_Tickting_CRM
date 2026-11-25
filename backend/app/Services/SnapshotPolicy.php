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

    /** A site is allowed when it is one of the approved domains or a subdomain of one. */
    public function domainAllowed(string $domain): bool
    {
        $domain = strtolower($domain);
        foreach ($this->domains() as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** What the extension needs to know when it checks in. */
    public function forExtension(): array
    {
        return [
            'enabled' => $this->enabled(),
            'min_minutes' => (int) config('activity.snapshots.min_minutes'),
            'max_minutes' => (int) config('activity.snapshots.max_minutes'),
            'allowed_domains' => $this->domains(),
            'max_kb' => $this->maxKb(),
        ];
    }
}