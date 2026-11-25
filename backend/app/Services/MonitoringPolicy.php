<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\MonitoringConsent;
use App\Models\User;

/**
 * Reads the monitoring disclosure from config/monitoring.php and answers whether an employee has
 * accepted the current version. The consent notice, the invite email and ActivityLogger all use it,
 * so the text people agree to and what is actually recorded cannot drift apart.
 */
class MonitoringPolicy
{
    public function version(): string
    {
        return (string) config('monitoring.version');
    }

    public function allows(string $category): bool
    {
        return array_key_exists($category, (array) config('monitoring.categories', []));
    }

    public function requiresConsent(?User $user): bool
    {
        return $user !== null && $user->role === Role::Employee;
    }

    public function consentFor(User $user): ?MonitoringConsent
    {
        return MonitoringConsent::query()
            ->where('user_id', $user->id)
            ->where('policy_version', $this->version())
            ->first();
    }

    public function hasConsented(User $user): bool
    {
        return MonitoringConsent::query()
            ->where('user_id', $user->id)
            ->where('policy_version', $this->version())
            ->exists();
    }

    public function accept(User $user, ?string $ip): MonitoringConsent
    {
        return MonitoringConsent::query()->firstOrCreate(
            ['user_id' => $user->id, 'policy_version' => $this->version()],
            ['accepted_at' => now(), 'ip_address' => $ip],
        );
    }

    /**
     * @return array{version: string, title: string, summary: string, categories: list<array{key: string, label: string, description: string}>, not_collected: list<string>, viewers: string, retention: string}
     */
    public function disclosure(): array
    {
        $categories = [];
        foreach ((array) config('monitoring.categories', []) as $key => $category) {
            $categories[] = [
                'key' => (string) $key,
                'label' => (string) $category['label'],
                'description' => $this->fill((string) $category['description']),
            ];
        }

        return [
            'version' => $this->version(),
            'title' => (string) config('monitoring.title'),
            'summary' => (string) config('monitoring.summary'),
            'categories' => $categories,
            'not_collected' => array_values(array_map('strval', (array) config('monitoring.not_collected', []))),
            'viewers' => (string) config('monitoring.viewers'),
            'retention' => $this->fill((string) config('monitoring.retention')),
        ];
    }

    /**
     * The disclosure as plain lines, for the invite email.
     *
     * @return list<string>
     */
    public function disclosureLines(): array
    {
        $policy = $this->disclosure();
        $lines = [$policy['title'].' (version '.$policy['version'].')', $policy['summary']];
        foreach ($policy['categories'] as $category) {
            $lines[] = '- '.$category['label'].': '.$category['description'];
        }
        $lines[] = 'Never recorded: '.implode('; ', $policy['not_collected']).'.';
        $lines[] = 'Who can see it: '.$policy['viewers'];
        $lines[] = $policy['retention'];
        $lines[] = 'You will be asked to accept this notice the first time you sign in.';

        return $lines;
    }

    private function fill(string $text): string
    {
        return strtr($text, [
            ':idle_minutes' => (string) (int) config('activity.idle_after_minutes'),
            ':retention_days' => (string) (int) config('activity.retention_days'),
            ':snapshot_days' => (string) (int) config('activity.snapshots.retention_days'),
        ]);
    }
}
