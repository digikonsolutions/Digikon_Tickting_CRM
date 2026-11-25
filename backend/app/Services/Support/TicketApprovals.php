<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketEventRecorder;
use Illuminate\Support\Facades\DB;

/** Requests for a decision on a ticket (scope, quote, delivery, complimentary) and who may decide each kind. */
class TicketApprovals
{
    /** kind => the capabilities that may decide it */
    public const DECIDERS = ['scope' => ['delivery_manager'], 'complimentary' => ['delivery_manager'], 'quote' => ['delivery_manager', 'finance'], 'delivery' => ['qa', 'delivery_manager']];

    public function __construct(private TicketEventRecorder $recorder) {}

    public function request(Ticket $ticket, User $actor, string $kind, ?string $note): int
    {
        abort_unless(StaffCapability::any($actor) || $ticket->assigned_to_id === $actor->id, 403);
        $id = DB::table('ticket_approvals')->insertGetId(['ticket_id' => $ticket->id, 'kind' => $kind, 'status' => 'pending', 'requested_by' => $actor->id, 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
        $this->recorder->record($ticket, 'approval_requested', ['kind' => $kind, 'note' => $note], $actor, 'internal');

        return $id;
    }

    public function decide(Ticket $ticket, int $approvalId, User $actor, bool $approve, ?string $note): void
    {
        $row = DB::table('ticket_approvals')->where('id', $approvalId)->where('ticket_id', $ticket->id)->first();
        abort_if($row === null, 404);
        abort_unless(StaffCapability::has($actor, ...self::DECIDERS[$row->kind]), 403, 'You cannot decide this kind of approval.');
        abort_unless($row->status === 'pending', 422, 'This approval was already decided.');
        DB::table('ticket_approvals')->where('id', $approvalId)->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decision_note' => $note, 'decided_at' => now(), 'updated_at' => now()]);
        $this->recorder->record($ticket, 'approval_decided', ['kind' => $row->kind, 'approved' => $approve, 'note' => $note], $actor, 'internal');
    }

    /** @return list<array<string, mixed>> */
    public function list(Ticket $ticket, User $viewer): array
    {
        return DB::table('ticket_approvals')->where('ticket_id', $ticket->id)->orderByDesc('id')->get()->map(fn ($r): array => [
            'id' => $r->id, 'kind' => $r->kind, 'status' => $r->status, 'note' => $r->note, 'decision_note' => $r->decision_note, 'decided_at' => $r->decided_at,
            'can_decide' => $r->status === 'pending' && StaffCapability::has($viewer, ...self::DECIDERS[$r->kind]),
        ])->all();
    }
}
