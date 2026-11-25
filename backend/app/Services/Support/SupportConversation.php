<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\TicketEventRecorder;

/**
 * What a new message on a support-module ticket sets in motion: the first staff reply stamps first response, an agency
 * reply puts a waiting ticket back to work (or reopens a resolved one), and text that looks like a password or key flags
 * the ticket for staff. Comments themselves are written by TicketController::comment, as for every ticket.
 */
class SupportConversation
{
    public function __construct(private TicketEventRecorder $recorder, private SecretScanner $scanner, private SlaService $sla) {}

    public function afterComment(Ticket $ticket, User $author, TicketComment $comment): void
    {
        if (! $ticket->usesLifecycle()) {
            return;
        }
        if (! $comment->is_internal) {
            $kinds = $this->scanner->scan($comment->body);
            if ($kinds !== []) {
                $ticket->forceFill(['secret_flagged_at' => now()])->save();
                $this->recorder->record($ticket, 'secret_flagged', ['kinds' => $kinds, 'comment_id' => $comment->id], $author, 'internal');
            }
        }
        if (StaffCapability::isStaff($author)) {
            if (! $comment->is_internal && $ticket->first_response_at === null) {
                $ticket->forceFill(['first_response_at' => now()])->save();
                $this->sla->met($ticket, 'response');
            }

            return;
        }
        // An agency reply.
        if ($ticket->status === 'waiting_agency') {
            $this->move($ticket, $author, 'in_progress', ['reason' => 'The agency replied.']);
        } elseif ($ticket->status === 'resolved' && app(SupportWorkflow::class)->insideReopenWindow($ticket)) {
            $this->move($ticket, $author, 'triage', ['reason' => 'The agency replied after the ticket was resolved.', 'reopened' => true], true);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function move(Ticket $ticket, User $actor, string $to, array $extra, bool $reopen = false): void
    {
        $from = $ticket->status;
        $changes = ['status' => $to];
        if (in_array($from, ['waiting_agency', 'waiting_third_party'], true)) {
            $changes += ['waiting_reason' => null, 'waiting_kind' => null, 'follow_up_date' => null, 'waiting_since' => null];
        }
        if ($reopen) {
            $changes += ['resolved_at' => null, 'closed_at' => null, 'reopened_count' => $ticket->reopened_count + 1];
        }
        $ticket->forceFill($changes)->save();
        if ($from === 'waiting_agency') {
            $this->sla->resume($ticket);
        }
        if ($reopen) {
            $this->sla->restartResolution($ticket);
        }
        $this->recorder->record($ticket, 'status_changed', ['from' => $from, 'to' => $to, 'from_label' => \App\Support\TicketStatus::label($from), 'to_label' => \App\Support\TicketStatus::label($to)] + $extra, $actor, 'public');
    }
}
