<?php

namespace App\Listeners;

use App\Enums\Role;
use App\Events\TicketEventRecorded;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\User;
use App\Notifications\TicketAssigned;
use App\Notifications\TicketCancelled;
use App\Notifications\TicketCommented;
use App\Notifications\TicketNeedsYourInput;
use App\Notifications\TicketRescheduled;
use App\Notifications\TicketStatusChanged;
use App\Services\TicketNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a recorded ticket event into notifications. The actor is never notified, inactive users and
 * users of suspended agencies are skipped, and internal events only ever reach admins and the
 * assigned employee.
 */
class SendTicketNotifications
{
    public function __construct(private TicketNotifier $notifier) {}

    public function handle(TicketEventRecorded $recorded): void
    {
        $event = $recorded->event;
        $ticket = Ticket::query()->with(['agency', 'creator', 'workItem.employee'])->find($event->ticket_id);
        if (! $ticket) {
            return;
        }

        $plan = $this->plan($event, $ticket);
        if ($plan === null) {
            return;
        }

        $payload = [
            'ticket_id' => $ticket->id,
            'ticket_key' => $ticket->key,
            'title' => $plan['title'],
            'body' => $plan['body'],
            'excerpt' => $plan['excerpt'] ?? null,
            'url_path' => '/tickets/'.$ticket->key,
            'actor_name' => $event->actor_name,
        ];

        foreach ($this->eligible($plan['recipients'], $event->actor_id) as $user) {
            $this->notifier->send($user, $plan['class'], $payload);
        }
    }

    /**
     * @return array{class: class-string, recipients: Collection<int, User|null>, title: string, body: string, excerpt?: string|null}|null
     */
    private function plan(TicketEvent $event, Ticket $ticket): ?array
    {
        $key = $ticket->key ?? 'this ticket';
        $actor = $event->actor_name ?? 'Someone';
        $payload = $event->payload ?? [];

        switch ($event->type) {
            case 'comment':
                if ($event->visibility === 'internal') {
                    // Internal notes: admins and the assigned employee only, and no text in the notification.
                    return [
                        'class' => TicketCommented::class,
                        'recipients' => $this->admins()->push($ticket->workItem?->employee),
                        'title' => 'New internal note',
                        'body' => "{$actor} added an internal note on {$key}.",
                    ];
                }

                $excerpt = $this->excerpt($payload['comment_id'] ?? null);
                $fromAgency = in_array($event->actor_role, [Role::AgencyOwner->value, Role::AgencyClient->value], true);

                return [
                    'class' => TicketCommented::class,
                    'recipients' => $fromAgency
                        ? collect([$ticket->workItem?->employee])
                        : $this->creatorAndOwners($ticket),
                    'title' => 'New comment',
                    'body' => "{$actor} commented on {$key}.",
                    'excerpt' => $excerpt,
                ];

            case 'status_changed':
                $excerpt = $this->excerpt($payload['comment_id'] ?? null);

                return match ($payload['to'] ?? null) {
                    'in_review' => [
                        'class' => TicketNeedsYourInput::class,
                        'recipients' => $this->creatorAndOwners($ticket),
                        'title' => 'Needs your review',
                        'body' => "{$key} is ready for your review.",
                    ],
                    'waiting_on_client' => [
                        'class' => TicketNeedsYourInput::class,
                        'recipients' => $this->creatorAndOwners($ticket),
                        'title' => 'Needs your input',
                        'body' => "{$actor} has a question about {$key}.",
                        'excerpt' => $excerpt,
                    ],
                    'changes_requested' => [
                        'class' => TicketStatusChanged::class,
                        'recipients' => collect([$ticket->workItem?->employee]),
                        'title' => 'Changes requested',
                        'body' => "{$actor} requested changes on {$key}.",
                        'excerpt' => $excerpt,
                    ],
                    'blocked' => [
                        'class' => TicketStatusChanged::class,
                        'recipients' => $this->owners($ticket)->merge($this->admins()),
                        'title' => 'Ticket blocked',
                        'body' => "{$key} was marked as blocked by {$actor}.",
                        'excerpt' => $excerpt,
                    ],
                    'done' => [
                        'class' => TicketStatusChanged::class,
                        'recipients' => collect([$ticket->workItem?->employee, $ticket->creator]),
                        'title' => 'Ticket approved',
                        'body' => "{$key} was approved and is done.",
                    ],
                    default => null,
                };

            case 'rescheduled':
                return [
                    'class' => TicketRescheduled::class,
                    'recipients' => $this->creatorAndOwners($ticket),
                    'title' => 'Schedule changed',
                    'body' => $payload['reason'] ?? "The schedule of {$key} changed.",
                ];

            case 'cancelled':
                return [
                    'class' => TicketCancelled::class,
                    'recipients' => collect([$ticket->creator, $ticket->workItem?->employee]),
                    'title' => 'Ticket cancelled',
                    'body' => "{$key} was cancelled.",
                ];

            case 'created':
                return [
                    'class' => TicketAssigned::class,
                    'recipients' => collect([$ticket->workItem?->employee]),
                    'title' => 'New ticket',
                    'body' => "{$actor} added {$key}: {$ticket->title}",
                ];

            default:
                return null;
        }
    }

    /** Public comment text only, shortened for emails and the in-app list. */
    private function excerpt(?int $commentId): ?string
    {
        if (! $commentId) {
            return null;
        }

        $comment = TicketComment::query()->find($commentId);

        return ($comment && ! $comment->is_internal) ? Str::limit(trim($comment->body), 200) : null;
    }

    /** @return Collection<int, User> */
    private function admins(): Collection
    {
        return User::query()->where('role', Role::Admin->value)->where('is_active', true)->get();
    }

    /** @return Collection<int, User> */
    private function owners(Ticket $ticket): Collection
    {
        return User::query()
            ->where('role', Role::AgencyOwner->value)
            ->where('agency_id', $ticket->agency_id)
            ->where('is_active', true)
            ->get();
    }

    /** @return Collection<int, User|null> */
    private function creatorAndOwners(Ticket $ticket): Collection
    {
        return collect([$ticket->creator])->merge($this->owners($ticket));
    }

    /**
     * @param Collection<int, User|null> $users
     * @return Collection<int, User>
     */
    private function eligible(Collection $users, ?int $actorId): Collection
    {
        return $users
            ->filter()
            ->unique('id')
            ->reject(fn (User $user): bool => $user->id === $actorId)
            ->filter(fn (User $user): bool => $user->is_active
                && ($user->agency_id === null || $user->agency?->status === 'active'))
            ->values();
    }
}
