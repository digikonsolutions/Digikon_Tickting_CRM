<?php

namespace App\Notifications;

/**
 * A reminder to an employee: work that should start today, or a deadline that is close or missed.
 * Stored with a `dedupe_key` so the scheduler can never send the same reminder twice.
 */
class WorkReminder extends TicketNotification
{
    public static function type(): string
    {
        return 'reminder';
    }

    public function toArray(object $notifiable): array
    {
        return [
            ...parent::toArray($notifiable),
            'kind' => $this->payload['kind'] ?? null,
            'dedupe_key' => $this->payload['dedupe_key'] ?? null,
        ];
    }
}
