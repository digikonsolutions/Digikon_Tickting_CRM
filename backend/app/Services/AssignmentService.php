<?php

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public function __construct(private ?TicketEventRecorder $recorder = null)
    {
        $this->recorder ??= app(TicketEventRecorder::class);
    }

    public function end(EmployeeAssignment $assignment, ?User $actor = null): void
    {
        $released = collect();
        DB::transaction(function () use ($assignment, $actor, &$released): void {
            $locked = EmployeeAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($locked->status === 'ended') {
                return;
            }

            // Projects still waiting for a timeline review are released with the assignment; admins and owners are told.
            $released = $locked->workItems()->with('agency')->whereIn('status', ['scheduled', 'in_progress'])
                ->whereIn('timeline_status', TimelineReview::PENDING)->get();

            $workItems = $locked->workItems()->whereIn('status', ['scheduled', 'in_progress']);
            $workItemIds = $workItems->pluck('id');
            $removedItems = $workItems->get();
            $workItems->update(['status' => 'cancelled', 'queue_position' => 0, 'updated_at' => now()]);
            app(EmployeeQueuePlanner::class)->removed($removedItems, $actor ?? auth()->user(), 'assignment_ended');
            $today = now(config('app.timezone'))->toDateString();
            DB::table('work_item_allocations')
                ->whereIn('work_item_id', $workItemIds)
                ->whereDate('work_date', '>=', $today)
                ->delete();

            $openTickets = Ticket::query()
                ->whereIn('work_item_id', $workItemIds)
                ->whereNotIn('status', ['done', 'cancelled'])
                ->get();

            DB::table('ticket_allocations')
                ->whereIn('ticket_id', $openTickets->pluck('id'))
                ->whereDate('work_date', '>=', $today)
                ->delete();

            app(TimeTracker::class)->stopForTickets($openTickets->pluck('id'), 'assignment_ended');

            foreach ($openTickets as $ticket) {
                $ticket->update(['status' => 'cancelled', 'updated_at' => now()]);
                $this->recorder->record($ticket, 'cancelled', ['cause' => 'assignment_ended'], null);
            }

            $locked->update([
                'status' => 'ended',
                'active_employee_id' => null,
                'ended_at' => now(config('app.timezone')),
            ]);
        });

        foreach ($released as $project) {
            app(TimelineNotifier::class)->released($project);
        }

        app(WorkflowNotificationService::class)->assignmentEnded(
            $assignment->fresh()->load(['employee', 'holder', 'agency']),
            $actor ?? auth()->user()
        );
    }
}
