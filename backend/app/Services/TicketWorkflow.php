<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketWorkflow
{
    public const TRANSITIONS = [
        'start' => [
            'from' => 'todo',
            'to' => 'in_progress',
            'who' => 'employee',
            'requires_comment' => false,
            'label' => 'Start',
        ],
        'send_to_review' => [
            'from' => 'in_progress',
            'to' => 'in_review',
            'who' => 'employee',
            'requires_comment' => false,
            'label' => 'Send to review',
        ],
        'mark_blocked' => [
            'from' => 'in_progress',
            'to' => 'blocked',
            'who' => 'employee',
            'requires_comment' => true,
            'label' => 'Mark blocked',
        ],
        'unblock' => [
            'from' => 'blocked',
            'to' => 'in_progress',
            'who' => 'employee',
            'requires_comment' => false,
            'label' => 'Unblock',
        ],
        'request_client_input' => [
            'from' => 'in_progress',
            'to' => 'waiting_on_client',
            'who' => 'employee',
            'requires_comment' => true,
            'label' => 'Ask client',
        ],
        'provide_input' => [
            'from' => 'waiting_on_client',
            'to' => 'in_progress',
            'who' => 'client',
            'requires_comment' => true,
            'label' => 'Reply to specialist',
        ],
        'approve' => [
            'from' => 'in_review',
            'to' => 'done',
            'who' => 'client',
            'requires_comment' => false,
            'label' => 'Approve',
        ],
        'request_changes' => [
            'from' => 'in_review',
            'to' => 'changes_requested',
            'who' => 'client',
            'requires_comment' => true,
            'label' => 'Request changes',
        ],
        'restart_work' => [
            'from' => 'changes_requested',
            'to' => 'in_progress',
            'who' => 'employee',
            'requires_comment' => false,
            'label' => 'Start rework',
        ],
    ];

    public function __construct(
        private TicketEventRecorder $recorder,
        private TicketPlanner $planner
    ) {}

    /**
     * @return list<array{action: string, label: string, requires_comment: bool}>
     */
    public function allowedActions(Ticket $ticket, ?User $viewer): array
    {
        if (! $viewer) {
            return [];
        }

        $workItem = $ticket->workItem;
        if (! $workItem || ! in_array($workItem->status, ['scheduled', 'in_progress'], true)) {
            return [];
        }

        if ($workItem->assignment?->status !== 'active') {
            return [];
        }

        $actions = [];
        $requireApproval = (bool) config('scheduling.require_client_approval', true);

        foreach (self::TRANSITIONS as $action => $def) {
            if ($def['from'] !== $ticket->status) {
                continue;
            }

            if ($this->canPerformAction($action, $def, $ticket, $viewer, $requireApproval)) {
                $actions[] = [
                    'action' => $action,
                    'label' => $def['label'],
                    'requires_comment' => $def['requires_comment'],
                ];
            }
        }

        return $actions;
    }

    public function transition(Ticket $ticket, string $action, ?string $commentBody, User $actor): Ticket
    {
        $def = self::TRANSITIONS[$action] ?? null;
        abort_unless($def !== null, 422, "Unknown action: {$action}");

        $workItem = $ticket->workItem;
        abort_unless($workItem !== null, 404);

        $this->ensureProjectAccess($actor, $workItem);

        return DB::transaction(function () use ($ticket, $workItem, $action, $def, $commentBody, $actor): Ticket {
            $this->lockActiveProjectAssignment($workItem);
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            abort_unless(in_array($lockedWorkItem->status, ['scheduled', 'in_progress'], true), 422, 'Transitions are only allowed on open projects.');

            $lockedTicket = $lockedWorkItem->tickets()->lockForUpdate()->findOrFail($ticket->id);

            // Approval rule check
            $requireApproval = (bool) config('scheduling.require_client_approval', true);
            if ($action === 'approve' && $actor->role === Role::Employee) {
                if ($requireApproval) {
                    abort(422, 'Only the client can approve this ticket.');
                }
            }

            if (! $this->canPerformAction($action, $def, $lockedTicket, $actor, $requireApproval)) {
                if ($action === 'approve' && $actor->role === Role::Employee && $requireApproval) {
                    abort(422, 'Only the client can approve this ticket.');
                }
                abort(403, 'You do not have permission to perform this action.');
            }

            abort_unless($lockedTicket->status === $def['from'], 422, "Cannot transition from {$lockedTicket->status} with action {$action}.");

            if ($def['requires_comment']) {
                $trimmed = trim((string) $commentBody);
                if ($trimmed === '') {
                    throw ValidationException::withMessages([
                        'comment' => 'A comment is required for this action.',
                    ]);
                }
            }

            $commentId = null;
            if (! empty(trim((string) $commentBody))) {
                $comment = TicketComment::create([
                    'ticket_id' => $lockedTicket->id,
                    'user_id' => $actor->id,
                    'body' => trim($commentBody),
                    'is_internal' => false,
                ]);
                $commentId = $comment->id;
            }

            $fromStatus = $lockedTicket->status;
            $toStatus = $def['to'];
            $lockedTicket->update(['status' => $toStatus]);

            // The employee is no longer actively working on it: stop any running timer.
            if (in_array($toStatus, ['blocked', 'waiting_on_client', 'in_review', 'done'], true)) {
                app(TimeTracker::class)->stopForTickets([$lockedTicket->id], 'status_'.$toStatus);
            }

            $this->recorder->record(
                $lockedTicket,
                'status_changed',
                [
                    'from' => $fromStatus,
                    'to' => $toStatus,
                    'action' => $action,
                    'comment_id' => $commentId,
                ],
                $actor
            );

            // Project status updates
            if ($toStatus === 'in_progress' && $lockedWorkItem->status === 'scheduled') {
                $lockedWorkItem->update(['status' => 'in_progress']);
            }

            $hasOpenTickets = $lockedWorkItem->tickets()
                ->whereNotIn('status', ['done', 'cancelled'])
                ->exists();
            $hasDoneTickets = $lockedWorkItem->tickets()->where('status', 'done')->exists();

            if (! $hasOpenTickets && $hasDoneTickets) {
                $lockedWorkItem->update(['status' => 'completed']);
            }

            $this->planner->plan($lockedWorkItem, null, [
                'type' => 'status_changed',
                'ticket_id' => $lockedTicket->id,
                'ticket_key' => $lockedTicket->key,
                'actor' => $actor,
                'actor_name' => $actor->name,
            ]);

            return $lockedTicket->fresh()->load(['agency', 'allocations', 'creator']);
        });
    }

    private function canPerformAction(
        string $action,
        array $def,
        Ticket $ticket,
        User $user,
        bool $requireApproval
    ): bool {
        $workItem = $ticket->workItem;

        if ($def['who'] === 'employee') {
            if ($user->role !== Role::Employee || $workItem->employee_id !== $user->id) {
                return false;
            }
            if ($user->agency_id !== null && $user->agency?->status !== 'active') {
                return false;
            }

            return true;
        }

        if ($def['who'] === 'client') {
            // Check fallback for employee approval if require_client_approval is false
            if ($action === 'approve' && ! $requireApproval) {
                if ($user->role === Role::Employee && $workItem->employee_id === $user->id) {
                    if ($user->agency_id === null || $user->agency?->status === 'active') {
                        return true;
                    }
                }
            }

            if (! in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)) {
                return false;
            }
            if ($user->agency_id === null || $user->agency_id !== $ticket->agency_id || $user->agency?->status !== 'active') {
                return false;
            }

            return true;
        }

        return false;
    }

    private function ensureProjectAccess(User $user, WorkItem $workItem): void
    {
        // Admin cannot transition tickets (read-only)
        if ($user->role === Role::Admin) {
            abort(403);
        }

        if ($user->role === Role::Employee) {
            abort_unless($workItem->employee_id === $user->id, 404);
            if ($user->agency_id !== null) {
                abort_unless($user->agency?->status === 'active', 403);
            }

            return;
        }

        if (in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)) {
            abort_unless($user->agency_id === $workItem->agency_id, 404);
            abort_unless($user->agency?->status === 'active', 403);

            return;
        }

        abort(404);
    }

    private function lockActiveProjectAssignment(WorkItem $workItem): EmployeeAssignment
    {
        $assignment = EmployeeAssignment::query()
            ->whereKey($workItem->assignment_id)
            ->where('employee_id', $workItem->employee_id)
            ->where('agency_id', $workItem->agency_id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        abort_unless($assignment !== null, 404);

        return $assignment;
    }
}
