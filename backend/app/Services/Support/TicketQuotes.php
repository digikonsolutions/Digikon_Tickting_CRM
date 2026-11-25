<?php

namespace App\Services\Support;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A quote for new scope: staff send it, the agency's owner accepts or declines it, and the ticket keeps the answer. */
class TicketQuotes
{
    public function __construct(private TicketEventRecorder $recorder) {}

    /** @param  array{amount: numeric, currency?: string|null, description: string, valid_until?: string|null}  $data */
    public function send(Ticket $ticket, User $actor, array $data): int
    {
        abort_unless(StaffCapability::has($actor, 'delivery_manager', 'finance'), 403, 'Only a delivery manager or finance can send a quote.');

        return DB::transaction(function () use ($ticket, $actor, $data): int {
            // One open quote at a time: a new one replaces the old.
            DB::table('ticket_quotes')->where('ticket_id', $ticket->id)->where('status', 'pending')->update(['status' => 'withdrawn', 'updated_at' => now()]);
            $id = DB::table('ticket_quotes')->insertGetId(['ticket_id' => $ticket->id, 'amount' => $data['amount'], 'currency' => strtoupper($data['currency'] ?? 'USD'), 'description' => $data['description'],
                'valid_until' => $data['valid_until'] ?? null, 'status' => 'pending', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->recorder->record($ticket, 'quote_sent', ['amount' => $data['amount'], 'currency' => strtoupper($data['currency'] ?? 'USD')], $actor, 'public');

            return $id;
        });
    }

    public function withdraw(Ticket $ticket, User $actor): void
    {
        abort_unless(StaffCapability::has($actor, 'delivery_manager', 'finance'), 403);
        DB::table('ticket_quotes')->where('ticket_id', $ticket->id)->where('status', 'pending')->update(['status' => 'withdrawn', 'updated_at' => now()]);
    }

    /** Only the owner of the ticket's agency decides money. */
    public function decide(Ticket $ticket, User $user, bool $accept, ?string $note): void
    {
        abort_unless($user->role === Role::AgencyOwner && $user->agency_id === $ticket->agency_id, 403, 'Only the account owner can answer a quote.');
        $quote = DB::table('ticket_quotes')->where('ticket_id', $ticket->id)->where('status', 'pending')->orderByDesc('id')->first();
        if ($quote === null) {
            throw ValidationException::withMessages(['quote' => 'There is no quote waiting for an answer.']);
        }
        if ($quote->valid_until !== null && $quote->valid_until < now()->toDateString()) {
            throw ValidationException::withMessages(['quote' => 'This quote has expired. Ask us for a new one.']);
        }
        DB::transaction(function () use ($ticket, $user, $accept, $note, $quote): void {
            DB::table('ticket_quotes')->where('id', $quote->id)->update(['status' => $accept ? 'accepted' : 'declined', 'decided_by' => $user->id, 'decision_note' => $note, 'decided_at' => now(), 'updated_at' => now()]);
            if ($accept) {
                $ticket->forceFill(['scope_classification' => 'new_scope', 'billing_status' => 'billable'])->save();
            }
            $this->recorder->record($ticket, 'quote_decided', ['accepted' => $accept], $user, 'public');
            if ($note) {
                $this->recorder->record($ticket, 'quote_note', ['note' => $note], $user, 'internal');
            }
        });
    }

    /** What the agency sees: the latest quote, never anything internal. @return array<string, mixed>|null */
    public function forAgency(Ticket $ticket): ?array
    {
        $q = DB::table('ticket_quotes')->where('ticket_id', $ticket->id)->where('status', '!=', 'withdrawn')->orderByDesc('id')->first();

        return $q ? ['amount' => (float) $q->amount, 'currency' => $q->currency, 'description' => $q->description, 'valid_until' => $q->valid_until, 'status' => $q->status] : null;
    }

    /** @return list<array<string, mixed>> staff see every quote, with who sent and who answered */
    public function forStaff(Ticket $ticket): array
    {
        return DB::table('ticket_quotes')->where('ticket_id', $ticket->id)->orderByDesc('id')->get()->map(fn ($q): array => [
            'id' => $q->id, 'amount' => (float) $q->amount, 'currency' => $q->currency, 'description' => $q->description, 'valid_until' => $q->valid_until, 'status' => $q->status, 'decision_note' => $q->decision_note, 'decided_at' => $q->decided_at,
        ])->all();
    }
}