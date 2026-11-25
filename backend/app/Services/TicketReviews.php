<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketReview;
use App\Models\TicketSubmission;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The employee's review package and the two reviews that follow: our own check first (an administrator), then the
 * agency's. Statuses move through TicketWorkflow; this class keeps the submissions and the reviewers' decisions.
 */
class TicketReviews
{
    public function __construct(private TicketWorkflow $workflow, private TicketEventRecorder $recorder) {}

    /** @param  array<string, mixed>  $data  validated package fields */
    public function submit(Ticket $ticket, User $employee, array $data): TicketSubmission
    {
        $workItem = $ticket->workItem;
        abort_unless($workItem !== null && $workItem->employee_id === $employee->id && $employee->role === Role::Employee, 404);

        return DB::transaction(function () use ($ticket, $employee, $data): TicketSubmission {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            abort_unless($locked->status === 'in_progress', 422, 'Only a ticket that is in progress can be sent for review.');

            $items = $locked->checklistItems()->get();
            $minutes = $data['time_minutes'] ?? (int) $locked->timeEntries()->whereNotNull('minutes')->sum('minutes');
            $submission = TicketSubmission::create([
                'ticket_id' => $locked->id,
                'version' => ((int) TicketSubmission::query()->where('ticket_id', $locked->id)->max('version')) + 1,
                'submitted_by' => $employee->id,
                'summary' => $data['summary'],
                'links' => array_values($data['links']),
                'ghl_changes' => $data['ghl_changes'] ?? null,
                'test_steps' => $data['test_steps'] ?? null,
                'limitations' => $data['limitations'] ?? null,
                'access_note' => $data['access_note'] ?? null,
                'time_minutes' => $minutes > 0 ? $minutes : null,
                'checklist_total' => $items->count(),
                'checklist_done' => $items->where('done', true)->count(),
                // With the internal check switched off the agency gets the package straight away.
                'visible_to_agency' => ! config('scheduling.internal_review'),
            ]);

            $this->workflow->transition($locked, 'send_to_review', null, $employee, ['package' => true]);

            return $submission;
        });
    }

    /** Our own check. Approving sends the work on to the agency; asking for changes sends it back to the employee. */
    public function internalReview(Ticket $ticket, User $admin, string $decision, ?string $feedback, array $items = []): TicketReview
    {
        abort_unless($admin->role === Role::Admin, 403);

        return DB::transaction(function () use ($ticket, $admin, $decision, $feedback, $items): TicketReview {
            $workItem = WorkItem::query()->lockForUpdate()->findOrFail($ticket->work_item_id);
            abort_unless(in_array($workItem->status, ['scheduled', 'in_progress'], true), 422, 'Reviews are only possible on open projects.');
            $locked = $workItem->tickets()->lockForUpdate()->findOrFail($ticket->id);
            abort_unless($locked->status === 'in_internal_review', 422, 'This ticket is not waiting for an internal review.');
            if ($decision === 'changes_requested' && blank($feedback)) {
                throw ValidationException::withMessages(['feedback' => 'Say what needs to change.']);
            }

            $submission = TicketSubmission::query()->where('ticket_id', $locked->id)->orderByDesc('version')->firstOrFail();
            $review = TicketReview::create([
                'submission_id' => $submission->id, 'stage' => 'internal', 'reviewer_id' => $admin->id,
                'decision' => $decision, 'feedback' => $feedback, 'items' => $items ?: null,
            ]);

            $to = $decision === 'approved' ? 'in_review' : 'in_progress';
            $locked->update(['status' => $to]);
            if ($decision === 'approved') {
                $submission->update(['visible_to_agency' => true]);
            } else {
                // The feedback reaches the employee as an internal note; the agency never sees this round.
                TicketComment::create(['ticket_id' => $locked->id, 'user_id' => $admin->id, 'body' => trim((string) $feedback), 'is_internal' => true]);
            }
            app(ActivityLogger::class)->ticketStatusChanged($admin, $locked, 'in_internal_review', $to);
            $this->recorder->record($locked, 'status_changed', [
                'from' => 'in_internal_review', 'to' => $to, 'action' => $decision === 'approved' ? 'internal_approve' : 'internal_request_changes',
            ], $admin, $decision === 'approved' ? 'public' : 'internal');
            $this->recorder->record($locked, 'internal_review', ['decision' => $decision, 'version' => $submission->version], $admin, 'internal');

            return $review;
        });
    }

    /** The agency's decision: it is the existing approve / request-changes step, plus a record of the review. */
    public function agencyReview(Ticket $ticket, User $user, string $decision, ?string $feedback, array $items = []): TicketReview
    {
        return DB::transaction(function () use ($ticket, $user, $decision, $feedback, $items): TicketReview {
            $this->workflow->transition($ticket, $decision === 'approved' ? 'approve' : 'request_changes', $feedback, $user);
            $submission = TicketSubmission::query()->where('ticket_id', $ticket->id)->where('visible_to_agency', true)->orderByDesc('version')->first();
            abort_unless($submission !== null, 422, 'There is no submission to review.');

            return TicketReview::create([
                'submission_id' => $submission->id, 'stage' => 'agency', 'reviewer_id' => $user->id,
                'decision' => $decision, 'feedback' => $feedback, 'items' => $items ?: null,
            ]);
        });
    }

    /**
     * The thread for one viewer. Our team and the assigned employee see everything; the agency sees only the
     * submissions sent on to it and its own reviews, never the internal feedback.
     *
     * @return list<array<string, mixed>>
     */
    public function thread(Ticket $ticket, User $viewer): array
    {
        $agency = $viewer->role->isAgencyRole();
        $query = TicketSubmission::query()->where('ticket_id', $ticket->id)->with(['submitter:id,name', 'reviews.reviewer:id,name,role'])->orderBy('version');
        if ($agency) {
            $query->where('visible_to_agency', true);
        }

        return $query->get()->map(function (TicketSubmission $s) use ($agency): array {
            $reviews = $s->reviews->filter(fn (TicketReview $r): bool => ! $agency || $r->stage === 'agency');

            return [
                'id' => $s->id, 'version' => $s->version, 'submitted_by' => $s->submitter?->name, 'created_at' => $s->created_at?->toISOString(),
                'summary' => $s->summary, 'links' => $s->links ?? [], 'ghl_changes' => $s->ghl_changes, 'test_steps' => $s->test_steps,
                'limitations' => $s->limitations, 'access_note' => $s->access_note, 'time_minutes' => $s->time_minutes,
                'checklist_done' => $s->checklist_done, 'checklist_total' => $s->checklist_total,
                'reviews' => $reviews->map(fn (TicketReview $r): array => [
                    'id' => $r->id, 'stage' => $r->stage, 'decision' => $r->decision, 'feedback' => $r->feedback, 'items' => $r->items ?? [],
                    'reviewer' => $agency && $r->stage !== 'agency' ? null : $r->reviewer?->name, 'created_at' => $r->created_at?->toISOString(),
                ])->values()->all(),
            ];
        })->values()->all();
    }
}