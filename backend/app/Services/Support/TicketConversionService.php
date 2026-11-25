<?php

namespace App\Services\Support;

use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\EmployeeQueuePlanner;
use App\Services\TicketEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a ticket into a project (or marks it as a change request needing an estimate) without losing anything: the ticket
 * keeps its whole history, both records point at each other, and converting twice returns the same project.
 */
class TicketConversionService
{
    public function __construct(private TicketEventRecorder $recorder, private EmployeeQueuePlanner $queue) {}

    /**
     * @param  array{assignment_id: int, title?: string|null}  $data
     */
    public function toProject(Ticket $ticket, User $actor, array $data): WorkItem
    {
        abort_unless(StaffCapability::manages($actor), 403, 'Only triage or a delivery manager can convert a ticket.');

        return DB::transaction(function () use ($ticket, $actor, $data): WorkItem {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->converted_to_type === 'project' && $locked->converted_to_id) {
                return WorkItem::query()->findOrFail($locked->converted_to_id);
            }
            $assignment = EmployeeAssignment::query()->whereKey($data['assignment_id'])->where('agency_id', $locked->agency_id)->where('status', 'active')->first();
            if ($assignment === null) {
                throw ValidationException::withMessages(['assignment_id' => 'Choose an active employee assignment of this agency.']);
            }
            $employee = User::query()->lockForUpdate()->findOrFail($assignment->employee_id);
            $start = CarbonImmutable::now(config('app.timezone'))->startOfDay();
            $description = trim($locked->description."\n\n".($locked->expected_result ? "Expected result:\n{$locked->expected_result}\n\n" : '')."Created from ticket {$locked->ref}.");

            $project = WorkItem::create([
                'agency_id' => $locked->agency_id,
                'client_id' => $assignment->holder_id,
                'assignment_id' => $assignment->id,
                'employee_id' => $employee->id,
                'title' => $data['title'] ?? $locked->title,
                'description' => mb_substr($description, 0, 5000),
                'estimated_hours' => 0,
                'start_date' => $start->toDateString(),
                'due_date' => $start->addDays((int) config('scheduling.provisional_window_days', 14))->toDateString(),
                'status' => 'scheduled',
                'awaiting_schedule' => true,
                'queue_position' => $this->queue->nextPosition($employee),
                'created_from_ticket_id' => $locked->id,
            ]);

            $locked->forceFill(['converted_to_type' => 'project', 'converted_to_id' => $project->id])->save();
            $this->recorder->record($locked, 'converted', ['to' => 'project', 'project_id' => $project->id, 'project_title' => $project->title], $actor, 'public');
            // The ticket closes with the right category; its history stays exactly as it was.
            if (! in_array($locked->status, ['closed', 'rejected'], true)) {
                $from = $locked->status;
                $locked->forceFill(['status' => 'closed', 'closed_at' => now(), 'resolved_at' => $locked->resolved_at ?? now(),
                    'resolution_summary' => "Converted to project: {$project->title}", 'resolution_category' => 'converted_to_project'])->save();
                $this->recorder->record($locked, 'status_changed', ['from' => $from, 'to' => 'closed', 'from_label' => \App\Support\TicketStatus::label($from), 'to_label' => 'Closed', 'reason' => 'Converted to a project'], $actor, 'public');
            }

            return $project;
        });
    }

    /** New scope: the ticket becomes a change request that needs an estimate and an approval before work starts. */
    public function toChangeRequest(Ticket $ticket, User $actor): Ticket
    {
        abort_unless(StaffCapability::manages($actor), 403, 'Only triage or a delivery manager can convert a ticket.');
        if ($ticket->kind === 'change_request') {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $actor): Ticket {
            $ticket->forceFill(['kind' => 'change_request', 'scope_classification' => 'new_scope', 'billing_status' => 'billable', 'converted_to_type' => 'change_request'])->save();
            $this->recorder->record($ticket, 'converted', ['to' => 'change_request'], $actor, 'public');

            return $ticket;
        });
    }
}
