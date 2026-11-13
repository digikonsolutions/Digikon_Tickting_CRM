<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Per-user delivery preferences for each notification type.
 *
 * Defaults (when a row is missing): in-app on for everything; email on for needs_input and
 * rescheduled only. `needs_input` in-app can never be turned off (`locked`).
 */
class NotificationPreferences
{
    public const TYPES = [
        'needs_input' => [
            'label' => 'Needs your input',
            'description' => 'A ticket is waiting for your review or an answer.',
            'email_default' => true,
            'locked' => true,
        ],
        'rescheduled' => [
            'label' => 'Schedule changes',
            'description' => 'Delivery dates of a ticket moved.',
            'email_default' => true,
            'locked' => false,
        ],
        'status_changed' => [
            'label' => 'Status updates',
            'description' => 'Changes requested, blocked, approved.',
            'email_default' => false,
            'locked' => false,
        ],
        'comment' => [
            'label' => 'Comments',
            'description' => 'New comments on your tickets.',
            'email_default' => false,
            'locked' => false,
        ],
        'cancelled' => [
            'label' => 'Cancellations',
            'description' => 'A ticket was cancelled.',
            'email_default' => false,
            'locked' => false,
        ],
        'assigned' => [
            'label' => 'New tickets',
            'description' => 'A new ticket was added to your work.',
            'email_default' => false,
            'locked' => false,
        ],
    ];

    /**
     * @return list<array{type: string, label: string, description: string, in_app: bool, email: bool, locked: bool}>
     */
    public function forUser(User $user): array
    {
        $stored = NotificationPreference::query()->where('user_id', $user->id)->get()->keyBy('type');

        $rows = [];
        foreach (self::TYPES as $type => $meta) {
            $row = $stored->get($type);
            $rows[] = [
                'type' => $type,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'in_app' => $meta['locked'] ? true : ($row?->in_app ?? true),
                'email' => $row?->email ?? $meta['email_default'],
                'locked' => $meta['locked'],
            ];
        }

        return $rows;
    }

    /**
     * Channels to use for one notification type: any of 'database' and 'mail'. Inactive users get none.
     *
     * @return list<string>
     */
    public function channelsFor(User $user, string $type): array
    {
        if (! $user->is_active || ! isset(self::TYPES[$type])) {
            return [];
        }

        $row = collect($this->forUser($user))->firstWhere('type', $type);
        $channels = [];
        if ($row['in_app']) {
            $channels[] = 'database';
        }
        if ($row['email']) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @param list<array{type: string, in_app?: bool, email?: bool}> $changes
     */
    public function update(User $user, array $changes): void
    {
        foreach ($changes as $change) {
            $type = $change['type'];
            $meta = self::TYPES[$type];
            $current = collect($this->forUser($user))->firstWhere('type', $type);

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => $type],
                [
                    'in_app' => $meta['locked'] ? true : (bool) ($change['in_app'] ?? $current['in_app']),
                    'email' => (bool) ($change['email'] ?? $current['email']),
                ]
            );
        }
    }
}
