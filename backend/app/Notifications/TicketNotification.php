<?php

namespace App\Notifications;

use App\Services\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;

/**
 * Base for every workflow and ticket notification. The stored `data` is
 * {type, ticket_id, ticket_key, project_id, assignment_id, title, body, url_path, actor_name, count}.
 *
 * Delivery channels follow the recipient's preferences; inactive users never receive anything.
 */
abstract class TicketNotification extends Notification
{
    use Queueable;

    /**
     * @param array{type?: string, ticket_id?: int|null, ticket_key?: string|null, project_id?: int|null, assignment_id?: int|null, title: string, body: string, url_path: string, actor_name?: string|null, excerpt?: string|null, action_text?: string|null} $payload
     */
    public function __construct(public array $payload)
    {
        $this->afterCommit();
    }

    /** The preference type this class represents (needs_input, status_changed, comment, project, assignment...). */
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
            ...Arr::only($this->payload, [
                'ticket_id',
                'ticket_key',
                'project_id',
                'assignment_id',
                'title',
                'body',
                'url_path',
                'actor_name',
            ]),
            'type' => static::type(),
            'count' => 1,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $key = $this->payload['ticket_key'] ?? (\App\Support\MailBrand::for($notifiable)['name'] ?? 'ClientHub');
        $brand = \App\Support\MailBrand::for($notifiable);
        $message = \App\Support\MailBrand::apply((new MailMessage)
            ->subject("[{$key}] {$this->payload['title']}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->payload['body']), $brand);

        // Only ever the excerpt of a public comment: internal notes never reach an email body.
        if (! empty($this->payload['excerpt'])) {
            $message->line('> '.str_replace("\n", ' ', $this->payload['excerpt']));
        }

        $url = ! empty($this->payload['url_path']) ? $frontend.$this->payload['url_path'] : $frontend;
        $actionText = ! empty($this->payload['action_text']) ? $this->payload['action_text'] : (! empty($this->payload['ticket_key']) ? 'Open ticket' : 'View in ClientHub');

        return $message
            ->action($actionText, $url)
            ->line('You are receiving this because of activity in your '.(\App\Support\MailBrand::for($notifiable)['name'] ?? 'ClientHub').' workspace.')
            ->line('[Manage notification settings]('.$frontend.'/settings/notifications)');
    }
}
