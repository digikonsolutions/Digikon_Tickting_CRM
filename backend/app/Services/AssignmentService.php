<?php

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public function __construct(private ?TicketEventRecorder $recorder = null)
    {
        $this->recorder ??= app(TicketEventRecorder::class);
    }

    public function end(EmployeeAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment): void {
            $locked = EmployeeAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($locked->status === 'ended') {
                return;
            }

            $workItems = $locked->workItems()->whereIn('status', ['scheduled', 'in_progress']);
            $workItemIds = $workItems->pluck('id');
            $workItems->update(['status' => 'cancelled', 'updated_at' => now()]);
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
    }
}
