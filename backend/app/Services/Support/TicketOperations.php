<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketEventRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scope classification and billing (proposed by a builder, approved by a delivery manager), time entries and the
 * agency's satisfaction answer. Everything here except the feedback is staff only.
 */
class TicketOperations
{
    /** Billing a classification implies until the delivery manager says otherwise. */
    public const DEFAULT_BILLING = [
        'bug' => 'non_billable', 'missed_requirement' => 'non_billable', 'warranty' => 'non_billable',
        'new_scope' => 'billable', 'enhancement' => 'billable', 'included_revision' => 'non_billable',
    ];

    public function __construct(private TicketEventRecorder $recorder) {}

    /**
     * A delivery manager (or admin) decides. Anyone else on the ticket's team proposes, and nothing changes until it is approved.
     *
     * @param  array{scope_classification: string, billing_status?: string|null, reason?: string|null}  $data
     */
    public function classify(Ticket $ticket, User $actor, array $data): Ticket
    {
        abort_unless(StaffCapability::has($actor, 'builder', 'triage', 'delivery_manager', 'qa'), 403);
        $billing = $data['billing_status'] ?? self::DEFAULT_BILLING[$data['scope_classification']] ?? null;
        if ($billing === 'complimentary' && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Say why this is complimentary.']);
        }
        $proposal = ['scope_classification' => $data['scope_classification'], 'billing_status' => $billing, 'reason' => $data['reason'] ?? null, 'by' => $actor->id, 'at' => now()->toISOString()];

        return DB::transaction(function () use ($ticket, $actor, $proposal): Ticket {
            if (! StaffCapability::has($actor, 'delivery_manager')) {
                $ticket->forceFill(['scope_proposal' => $proposal])->save();
                $this->recorder->record($ticket, 'scope_proposed', Arr::except($proposal, ['by']), $actor, 'internal');

                return $ticket;
            }

            return $this->apply($ticket, $actor, $proposal);
        });
    }

    public function approveProposal(Ticket $ticket, User $actor): Ticket
    {
        abort_unless(StaffCapability::has($actor, 'delivery_manager'), 403, 'Only a delivery manager can approve the scope.');
        abort_if(empty($ticket->scope_proposal), 422, 'There is no proposal to approve.');

        return DB::transaction(fn (): Ticket => $this->apply($ticket, $actor, $ticket->scope_proposal));
    }

    /** @param  array<string, mixed>  $p */
    private function apply(Ticket $ticket, User $actor, array $p): Ticket
    {
        $ticket->forceFill([
            'scope_classification' => $p['scope_classification'], 'billing_status' => $p['billing_status'] ?? null,
            'scope_approved_by' => $actor->id, 'scope_approved_at' => now(), 'scope_proposal' => null,
        ])->save();
        $this->recorder->record($ticket, 'scope_approved', ['scope_classification' => $p['scope_classification'], 'billing_status' => $p['billing_status'] ?? null, 'reason' => $p['reason'] ?? null], $actor, 'internal');

        return $ticket;
    }

    /** @param  array{minutes: int, entry_date?: string|null, activity?: string|null, billable?: bool|null, note?: string|null}  $data */
    public function logTime(Ticket $ticket, User $actor, array $data): void
    {
        abort_unless(StaffCapability::has($actor, 'builder', 'qa', 'triage', 'delivery_manager') || $ticket->assigned_to_id === $actor->id, 403);
        DB::table('ticket_time_entries')->insert([
            'ticket_id' => $ticket->id, 'user_id' => $actor->id, 'entry_date' => $data['entry_date'] ?? now()->toDateString(), 'minutes' => $data['minutes'],
            'activity' => $data['activity'] ?? 'work', 'billable' => (bool) ($data['billable'] ?? false), 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{entries: list<array<string, mixed>>, actual_minutes: int, billable_minutes: int, estimate_hours: float|null} */
    public function time(Ticket $ticket): array
    {
        $rows = DB::table('ticket_time_entries')->join('users', 'users.id', '=', 'ticket_time_entries.user_id')->where('ticket_id', $ticket->id)->orderByDesc('entry_date')->orderByDesc('ticket_time_entries.id')
            ->get(['ticket_time_entries.id', 'entry_date', 'minutes', 'activity', 'billable', 'note', 'users.name as user']);

        return [
            'entries' => $rows->map(fn ($r): array => ['id' => $r->id, 'date' => $r->entry_date, 'minutes' => (int) $r->minutes, 'activity' => $r->activity, 'billable' => (bool) $r->billable, 'note' => $r->note, 'user' => $r->user])->all(),
            'actual_minutes' => (int) $rows->sum('minutes'),
            'billable_minutes' => (int) $rows->where('billable', 1)->sum('minutes'),
            'estimate_hours' => $ticket->work_item_id === null && $ticket->estimated_hours !== null ? (float) $ticket->estimated_hours : null,
        ];
    }

    /** One answer per ticket, only after it is resolved or closed, only from the agency that owns it. */
    public function feedback(Ticket $ticket, User $user, int $rating, ?string $comment): void
    {
        abort_unless(in_array($ticket->status, ['resolved', 'closed'], true), 422, 'You can leave feedback once the ticket is resolved.');
        DB::table('ticket_feedback')->updateOrInsert(['ticket_id' => $ticket->id], ['user_id' => $user->id, 'rating' => $rating, 'comment' => $comment, 'created_at' => now(), 'updated_at' => now()]);
        $this->recorder->record($ticket, 'feedback_left', ['rating' => $rating], $user, 'internal');
    }
}
