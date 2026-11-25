<?php

namespace App\Services\Support;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\User;
use App\Notifications\TicketAssigned;
use App\Notifications\TicketCommented;
use App\Notifications\TicketNeedsYourInput;
use App\Notifications\TicketStatusChanged;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Who is told about what on a support-module ticket (the notification matrix). Internal events only ever reach staff;
 * nothing an agency reads carries internal text. The listener sends what this returns; the actor is never notified.
 */
class SupportNotificationPlanner
{
    /**
     * @return list<array{class: class-string, recipients: Collection<int, User|null>, title: string, body: string, excerpt?: string|null}>
     */
    public function plan(TicketEvent $event, Ticket $ticket): array
    {
        $ref = $ticket->ref ?? 'this ticket';
        $actor = $event->actor_name ?? 'The support team';
        $payload = $event->payload ?? [];
        $agency = $this->agencySide($ticket);
        $owner = collect([$ticket->assignee]);
        $staff = $owner->merge($this->managers());

        switch ($event->type) {
            case 'created':
                $plans = [
                    ['class' => TicketStatusChanged::class, 'recipients' => collect([$ticket->submitter]), 'title' => "We received {$ref}", 'body' => "Your ticket {$ref} was received. We will review it and keep you updated."],
                    ['class' => TicketAssigned::class, 'recipients' => $this->managers(), 'title' => 'New support ticket', 'body' => "{$ref}: {$ticket->title}"],
                ];
                if ($ticket->priority === 'urgent') {
                    $plans[] = ['class' => TicketNeedsYourInput::class, 'recipients' => $this->managers(), 'title' => 'Critical ticket', 'body' => "{$ref} is critical and needs a manager now: {$ticket->title}"];
                }

                return $plans;

            case 'comment':
                if ($event->visibility === 'internal') {
                    return [['class' => TicketCommented::class, 'recipients' => $staff, 'title' => 'New internal note', 'body' => "{$actor} added an internal note on {$ref}."]];
                }
                $comment = isset($payload['comment_id']) ? TicketComment::query()->find($payload['comment_id']) : null;
                $fromAgency = in_array($event->actor_role, [Role::AgencyOwner->value, Role::AgencyClient->value], true);

                return [[
                    'class' => TicketCommented::class,
                    'recipients' => $fromAgency ? ($ticket->assigned_to_id ? $owner : $this->managers()) : $agency,
                    'title' => 'New reply', 'body' => "{$actor} replied on {$ref}.",
                    'excerpt' => $comment && ! $comment->is_internal ? Str::limit(trim($comment->body), 200) : null,
                ]];

            case 'status_changed':
                return match ($payload['to'] ?? null) {
                    'waiting_agency' => [['class' => TicketNeedsYourInput::class, 'recipients' => $agency, 'title' => 'We need something from you', 'body' => "{$ref}: ".($payload['reason'] ?? 'we are waiting for your reply.')]],
                    'resolved' => [['class' => TicketStatusChanged::class, 'recipients' => $agency, 'title' => 'Your ticket is resolved', 'body' => "{$ref} was resolved. Please confirm it, or reopen it if it is not fixed.", 'excerpt' => Str::limit((string) $ticket->resolution_summary, 200)]],
                    'closed' => [['class' => TicketStatusChanged::class, 'recipients' => $agency, 'title' => 'Ticket closed', 'body' => "{$ref} is closed. How did we do? You can leave feedback on the ticket."]],
                    'rejected' => [['class' => TicketStatusChanged::class, 'recipients' => $agency, 'title' => 'Ticket closed without action', 'body' => "{$ref}: ".($payload['reason'] ?? 'it is out of scope.')]],
                    'assigned' => [['class' => TicketAssigned::class, 'recipients' => $owner, 'title' => 'Ticket assigned to you', 'body' => "{$ref}: {$ticket->title}"]],
                    'qa' => [['class' => TicketAssigned::class, 'recipients' => collect([$ticket->reviewer]), 'title' => 'QA requested', 'body' => "{$ref} is ready for your review."]],
                    'triage' => ! empty($payload['reopened']) ? [['class' => TicketNeedsYourInput::class, 'recipients' => $owner->merge($this->managers()), 'title' => 'Ticket reopened', 'body' => "{$ref} was reopened: ".($payload['reason'] ?? '')]] : [],
                    default => [],
                };

            case 'quote_sent':
                return [['class' => TicketNeedsYourInput::class, 'recipients' => $agency, 'title' => 'A quote is waiting for you', 'body' => "{$ref}: we sent you a quote for the new work. The account owner can accept or decline it on the ticket."]];

            case 'quote_decided':
                return [['class' => TicketStatusChanged::class, 'recipients' => $staff, 'title' => ! empty($payload['accepted']) ? 'Quote accepted' : 'Quote declined', 'body' => "{$ref} was answered by the agency."]];

            case 'reassigned':
                return [['class' => TicketAssigned::class, 'recipients' => $owner, 'title' => 'Ticket assigned to you', 'body' => "{$ref}: {$ticket->title}"]];

            case 'sla_at_risk':
                return [['class' => TicketNeedsYourInput::class, 'recipients' => $staff, 'title' => 'Ticket at risk', 'body' => "{$ref} is close to its {$payload['kind']} deadline."]];

            case 'sla_breached':
                return [['class' => TicketNeedsYourInput::class, 'recipients' => $staff, 'title' => 'SLA breached', 'body' => "{$ref} missed its {$payload['kind']} deadline."]];

            case 'escalated_unassigned':
                return [['class' => TicketNeedsYourInput::class, 'recipients' => $this->managers(), 'title' => 'Nobody has picked this up', 'body' => "{$ref} has been waiting for an owner."]];

            case 'waiting_reminder':
                return [['class' => TicketNeedsYourInput::class, 'recipients' => $agency, 'title' => 'Still waiting for you', 'body' => "{$ref}: we are still waiting for your reply."]];

            case 'waiting_stalled':
                return [['class' => TicketNeedsYourInput::class, 'recipients' => $staff, 'title' => 'Waiting for the agency too long', 'body' => "{$ref} has been waiting for the agency for several days."]];

            default:
                return [];
        }
    }

    /** The person who submitted it and the agency owners. */
    private function agencySide(Ticket $ticket): Collection
    {
        return collect([$ticket->submitter])->merge(
            User::query()->where('role', Role::AgencyOwner->value)->where('agency_id', $ticket->agency_id)->where('is_active', true)->get()
        );
    }

    /** Admins and everyone holding the triage or delivery-manager capability. */
    public function managers(): Collection
    {
        return User::query()->where('is_active', true)->whereIn('role', [Role::Admin->value, Role::Employee->value])->get()
            ->filter(fn (User $u): bool => StaffCapability::manages($u))->values();
    }
}
