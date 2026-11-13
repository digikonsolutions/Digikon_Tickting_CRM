<?php

namespace App\Notifications;

use App\Services\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;

/**
 * Base for every ticket notification. The stored `data` is
 * {type, ticket_id, ticket_key, title, body, url_path, actor_name, count}.
 *
 * Queued after the surrounding transaction commits. Delivery channels follow the recipient's
 * preferences; inactive users never receive anything.
 */
abstract class TicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array{type: string, ticket_id: int, ticket_key: string|null, title: string, body: string, url_path: string, actor_name: string|null, excerpt?: string|null} $payload
     */
    public function __construct(public array $payload)
    {
        $this->afterCommit();
    }

    /** The preference type this class represents (needs_input, status_changed, comment, ...). */
    abstract public static function type(): string;

    public function via(object $notifiable): array
    {
        return app(NotificationPreferences::class)->channelsFor($notifiable, static::type());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            ...Arr::only($this->payload, ['ticket_id', 'ticket_key', 'title', 'body', 'url_path', 'actor_name']),
            'type' => static::type(),
            'count' => 1,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $key = $this->payload['ticket_key'] ?? 'Ticket';
        $message = (new MailMessage)
            ->subject("[{$key}] {$this->payload['title']}")
            ->line($this->payload['body']);

        // Only ever the excerpt of a public comment: internal notes never reach an email body.
        if (! empty($this->payload['excerpt'])) {
            $message->line('> '.str_replace("\n", ' ', $this->payload['excerpt']));
        }

        return $message
            ->action('Open ticket', $frontend.$this->payload['url_path'])
            ->line('[Manage notification settings]('.$frontend.'/settings/notifications)');
    }
}
