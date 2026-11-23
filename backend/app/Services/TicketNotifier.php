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
     * White-label: what an agency user reads must not name platform staff or the internal agency record. Staff names become
     * "{agency} Support" and the internal agency name becomes the brand name, in the title, body, excerpt and actor.
     * Done here, once, so every notification type is covered.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function forRecipient(User $user, array $payload): array
    {
        if (! $user->role->isAgencyRole() || $user->agency === null) {
            return $payload;
        }
        $agency = $user->agency;
        $brand = \App\Services\AgencyBrandingPresenter::displayName($agency);
        $support = $brand.' Support';
        $staff = User::query()->whereIn('role', ['admin', 'team_lead', 'qa'])->pluck('name')->filter()->unique()->sortByDesc(fn (string $name): int => strlen($name))->values()->all();
        $clean = function (?string $text) use ($staff, $support, $agency, $brand): ?string {
            if ($text === null) {
                return null;
            }
            $text = $staff === [] ? $text : str_replace($staff, $support, $text);

            return $agency->name !== $brand ? str_replace($agency->name, $brand, $text) : $text;
        };
        foreach (['title', 'body', 'excerpt', 'actor_name'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $payload[$key] = $clean($payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * @param class-string<\App\Notifications\TicketNotification> $class
     * @param array<string, mixed> $payload
     */
    public function send(User $user, string $class, array $payload): void
    {
        if (! $user->is_active) {
            return;
        }

        $payload = $this->forRecipient($user, $payload);
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
