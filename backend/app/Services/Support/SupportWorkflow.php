<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketEventRecorder;
use App\Support\TicketStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place a lifecycle ticket changes status (new, triage, assigned, in_progress, waiting_*, qa, resolved, closed,
 * rejected). One transaction per change: it enforces the transition table, the data each transition needs, who may do it
 * and the reopen window, then writes the audit event. Old hour-slice tickets keep using TicketWorkflow.
 */
class SupportWorkflow
{
    /** from => [to, ...]. "triage" out of resolved or closed is a reopen. */
    public const MAP = [
        'new' => ['triage', 'rejected'],
        'triage' => ['assigned', 'waiting_agency', 'waiting_third_party', 'rejected'],
        'assigned' => ['in_progress', 'waiting_agency', 'waiting_third_party', 'triage'],
        'in_progress' => ['waiting_agency', 'waiting_third_party', 'qa', 'resolved', 'assigned'],
        'waiting_agency' => ['in_progress'],
        'waiting_third_party' => ['in_progress'],
        'qa' => ['resolved', 'in_progress'],
        'resolved' => ['closed', 'triage'],
        'closed' => ['triage'],
        'rejected' => ['triage'],
    ];

    public function __construct(private TicketEventRecorder $recorder) {}

    /** Whether the type needs a QA pass before Resolved. */
    public static function qaRequired(Ticket $ticket): bool
    {
        return (bool) (config("support.types.{$ticket->type}.qa") ?? false);
    }

    /**
     * What this person may do next on this ticket: the status control lists only these.
     *
     * @return list<array{to: string, label: string, needs: list<string>}>
     */
    public function allowed(Ticket $ticket, User $actor): array
    {
        if (! $ticket->usesLifecycle()) {
            return [];
        }
        $out = [];
        foreach (self::MAP[$ticket->status] ?? [] as $to) {
            if ($to === 'resolved' && $ticket->status === 'in_progress' && self::qaRequired($ticket)) {
                continue;
            }
            if ($to === 'triage' && in_array($ticket->status, ['resolved', 'closed'], true) && $actor->role->isAgencyRole() && ! $this->insideReopenWindow($ticket)) {
                continue;
            }
            if (! $this->mayDo($ticket, $ticket->status, $to, $actor)) {
                continue;
            }
            $out[] = ['to' => $to, 'label' => $this->label($ticket->status, $to), 'needs' => $this->needs($ticket->status, $to)];
        }

        return $out;
    }

    private function label(string $from, string $to): string
    {
        if ($to === 'triage' && in_array($from, ['resolved', 'closed'], true)) {
            return 'Reopen';
        }

        return TicketStatus::LABELS[$to] ?? $to;
    }

    /** @return list<string> the fields the person must supply */
    private function needs(string $from, string $to): array
    {
        return match (true) {
            in_array($to, ['waiting_agency', 'waiting_third_party'], true) => ['waiting_reason', 'follow_up_date'],
            $to === 'qa' => ['reviewer_id'],
            $to === 'resolved' => ['resolution_summary', 'resolution_category'],
            $to === 'closed' => ['resolution_summary', 'resolution_category'],
            $to === 'rejected' => ['reason'],
            $to === 'assigned' => ['assigned_to_id'],
            $to === 'in_progress' && $from === 'qa' => ['failure_note'],
            $to === 'triage' && in_array($from, ['resolved', 'closed', 'rejected'], true) => ['reason'],
            default => [],
        };
    }

    private function isOwner(Ticket $ticket, User $actor): bool
    {
        return $ticket->assigned_to_id !== null && $ticket->assigned_to_id === $actor->id;
    }

    private function mayDo(Ticket $ticket, string $from, string $to, User $actor): bool
    {
        if ($actor->role->isAgencyRole()) {
            if ($actor->agency_id === null || $actor->agency_id !== $ticket->agency_id || $actor->agency?->status !== 'active') {
                return false;
            }
            // Agency users confirm a resolution or reopen a finished ticket, inside the window.
            if ($from === 'resolved' && $to === 'closed') {
                return true;
            }

            // The window is checked in transition() so the person gets a clear message instead of a bare refusal.
            return $to === 'triage' && in_array($from, ['resolved', 'closed'], true);
        }
        if (! StaffCapability::isStaff($actor)) {
            return false;
        }
        $manages = StaffCapability::manages($actor);
        $owner = $this->isOwner($ticket, $actor) || $manages;

        return match (true) {
            $from === 'qa' => StaffCapability::has($actor, 'qa', 'delivery_manager'),
            $to === 'rejected', $to === 'closed' => $manages,
            $to === 'assigned' && $from === 'triage' => $manages,
            $to === 'triage' => $manages || ($from === 'assigned' && $this->isOwner($ticket, $actor)) || (in_array($from, ['resolved', 'closed'], true) && $this->isOwner($ticket, $actor)),
            default => $owner,
        };
    }

    public function insideReopenWindow(Ticket $ticket): bool
    {
        $since = $ticket->status === 'closed' ? $ticket->closed_at : $ticket->resolved_at;

        return $since !== null && $since->greaterThanOrEqualTo(now()->subDays((int) config('support.reopen_days', 14)));
    }

    /**
     * @param  array<string, mixed>  $data  the fields the transition needs (see needs())
     *
     * @throws ValidationException
     */
    public function transition(Ticket $ticket, string $to, User $actor, array $data = []): Ticket
    {
        if (! $ticket->usesLifecycle()) {
            throw ValidationException::withMessages(['status' => 'This ticket uses the project workflow.']);
        }

        return DB::transaction(function () use ($ticket, $to, $actor, $data): Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $from = $locked->status;
            if (! in_array($to, self::MAP[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "A ticket that is {$from} cannot move to {$to}."]);
            }
            abort_unless($this->mayDo($locked, $from, $to, $actor), 403, 'You do not have permission to make this change.');
            $reopen = $to === 'triage' && in_array($from, ['resolved', 'closed'], true);
            if ($reopen && $actor->role->isAgencyRole() && ! $this->insideReopenWindow($locked)) {
                throw ValidationException::withMessages(['status' => 'This ticket can no longer be reopened. Please open a new ticket.']);
            }
            if ($to === 'resolved' && $from === 'in_progress' && self::qaRequired($locked)) {
                throw ValidationException::withMessages(['status' => 'This type of ticket needs a QA check before it can be resolved.']);
            }

            $changes = ['status' => $to];
            $internal = [];
            $public = ['from' => $from, 'to' => $to, 'from_label' => TicketStatus::label($from), 'to_label' => TicketStatus::label($to)];

            if (in_array($from, ['waiting_agency', 'waiting_third_party'], true)) {
                $changes += ['waiting_reason' => null, 'waiting_kind' => null, 'follow_up_date' => null];
            }

            switch (true) {
                case $to === 'assigned':
                    $assignee = $this->staffUser($data['assigned_to_id'] ?? $locked->assigned_to_id, 'assigned_to_id');
                    $changes['assigned_to_id'] = $assignee->id;
                    $changes['assigned_team'] = $data['assigned_team'] ?? $locked->assigned_team;
                    $internal['assignee'] = $assignee->name;
                    break;

                case in_array($to, ['waiting_agency', 'waiting_third_party'], true):
                    $reason = trim((string) ($data['waiting_reason'] ?? ''));
                    $date = $data['follow_up_date'] ?? null;
                    $errors = [];
                    if ($reason === '') {
                        $errors['waiting_reason'] = 'Say what you are waiting for.';
                    }
                    if ($date === null || ! strtotime((string) $date) || $date < now()->toDateString()) {
                        $errors['follow_up_date'] = 'Choose a follow-up date that is today or later.';
                    }
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                    $changes += ['waiting_reason' => mb_substr($reason, 0, 500), 'waiting_kind' => $to === 'waiting_agency' ? 'agency' : 'third_party', 'follow_up_date' => $date];
                    $public['reason'] = $changes['waiting_reason'];
                    $public['follow_up_date'] = $date;
                    break;

                case $to === 'qa':
                    $reviewer = $this->staffUser($data['reviewer_id'] ?? null, 'reviewer_id');
                    if (! StaffCapability::has($reviewer, 'qa')) {
                        throw ValidationException::withMessages(['reviewer_id' => 'The reviewer needs the QA capability.']);
                    }
                    if ($locked->priority === 'urgent' && $reviewer->id === $locked->assigned_to_id) {
                        $override = trim((string) ($data['override_reason'] ?? ''));
                        if ($actor->role !== \App\Enums\Role::Admin || $override === '') {
                            throw ValidationException::withMessages(['reviewer_id' => 'On a critical ticket the reviewer must be someone other than the builder.']);
                        }
                        $internal['override_reason'] = $override;
                    }
                    $changes['reviewer_id'] = $reviewer->id;
                    $internal['reviewer'] = $reviewer->name;
                    break;

                case $to === 'in_progress' && $from === 'qa':
                    $note = trim((string) ($data['failure_note'] ?? ''));
                    if ($note === '') {
                        throw ValidationException::withMessages(['failure_note' => 'Say what failed so the builder can fix it.']);
                    }
                    $internal['failure_note'] = $note;
                    $internal['qa'] = 'fail';
                    break;

                case $to === 'resolved':
                    $summary = trim((string) ($data['resolution_summary'] ?? $locked->resolution_summary ?? ''));
                    $category = $data['resolution_category'] ?? $locked->resolution_category;
                    $this->requireResolution($summary, $category);
                    $changes += ['resolution_summary' => $summary, 'resolution_category' => $category, 'resolved_at' => now()];
                    if (isset($data['root_cause'])) {
                        $changes['root_cause'] = $data['root_cause'];
                    }
                    if ($from === 'qa') {
                        $internal['qa'] = 'pass';
                    }
                    break;

                case $to === 'closed':
                    $summary = trim((string) ($data['resolution_summary'] ?? $locked->resolution_summary ?? ''));
                    $category = $data['resolution_category'] ?? $locked->resolution_category;
                    $this->requireResolution($summary, $category);
                    $changes += ['resolution_summary' => $summary, 'resolution_category' => $category, 'closed_at' => now()];
                    break;

                case $to === 'rejected':
                    $reason = trim((string) ($data['reason'] ?? ''));
                    if ($reason === '') {
                        throw ValidationException::withMessages(['reason' => 'Say why this is being rejected.']);
                    }
                    $changes += ['resolution_summary' => $reason, 'resolution_category' => $data['resolution_category'] ?? 'out_of_scope', 'closed_at' => now()];
                    $public['reason'] = $reason;
                    break;

                case $to === 'triage' && in_array($from, ['resolved', 'closed', 'rejected'], true):
                    $reason = trim((string) ($data['reason'] ?? ''));
                    if ($reason === '') {
                        throw ValidationException::withMessages(['reason' => 'Say why this is being reopened.']);
                    }
                    $changes += ['resolved_at' => null, 'closed_at' => null, 'assigned_to_id' => $from === 'rejected' ? null : $locked->assigned_to_id];
                    if ($from !== 'rejected') {
                        $changes['reopened_count'] = $locked->reopened_count + 1;
                    }
                    $public['reason'] = $reason;
                    $public['reopened'] = $from !== 'rejected';
                    break;
            }

            $locked->forceFill($changes)->save();
            $this->recorder->record($locked, 'status_changed', $public, $actor, 'public');
            if ($internal !== []) {
                $this->recorder->record($locked, 'status_detail', $internal + ['from' => $from, 'to' => $to], $actor, 'internal');
            }

            return $locked->fresh();
        });
    }

    private function requireResolution(string $summary, ?string $category): void
    {
        $errors = [];
        if ($summary === '') {
            $errors['resolution_summary'] = 'Write a short summary of what was done.';
        }
        if ($category === null || ! array_key_exists($category, config('support.resolution_categories'))) {
            $errors['resolution_category'] = 'Choose a resolution category.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function staffUser(mixed $id, string $field): User
    {
        $user = $id ? User::query()->find($id) : null;
        if ($user === null || ! StaffCapability::isStaff($user) || ! $user->is_active) {
            throw ValidationException::withMessages([$field => 'Choose an active member of staff.']);
        }

        return $user;
    }
}
