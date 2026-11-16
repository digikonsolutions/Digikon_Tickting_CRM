<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;

/**
 * Sends one notification to one user, coalescing in-app noise: if the recipient already has an
 * unread notification of the same type for the same ticket from the last 10 minutes, that one is
 * updated (count and text) instead of creating another, and no extra email is sent.
 *
 * Uses notifyNow so database notifications are persistent immediately and emails are delivered
 * via the configured mail transport. Mail delivery failures are logged and swallowed so
 * database transactions and in-app notifications are never broken.
 */
class TicketNotifier
{
    public const COALESCE_MINUTES = 10;

    /**
     * @param class-string<\App\Notifications\TicketNotification> $class
     * @param array<string, mixed> $payload
     */
    public function send(User $user, string $class, array $payload): void
    {
        if (! $user->is_active) {
            return;
        }

        $type = $class::type();

        // Only coalesce notifications for the same ticket within the coalesce window
        if (! empty($payload['ticket_id'])) {
            $existing = DatabaseNotification::query()
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->id)
                ->whereNull('read_at')
                ->where('created_at', '>=', now()->subMinutes(self::COALESCE_MINUTES))
                ->where('data->type', $type)
                ->where('data->ticket_id', $payload['ticket_id'])
                ->latest()
                ->first();

            if ($existing) {
                $data = $existing->data;
                $existing->forceFill([
                    'data' => [
                        ...$data,
                        'title' => $payload['title'],
                        'body' => $payload['body'],
                        'actor_name' => $payload['actor_name'] ?? null,
                        'count' => ((int) ($data['count'] ?? 1)) + 1,
                    ],
                ])->save();

                return;
            }
        }

        try {
            $user->notifyNow(new $class($payload));
        } catch (\Throwable $e) {
            report($e);
            Log::error("Notification delivery failed for user {$user->id} ({$user->email}): ".$e->getMessage(), [
                'exception' => $e,
                'class' => $class,
                'payload' => $payload,
            ]);
        }
    }
}
