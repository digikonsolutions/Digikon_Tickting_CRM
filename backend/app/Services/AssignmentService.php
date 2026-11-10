<?php

namespace App\Services;

use App\Models\EmployeeAssignment;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
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

            $ticketIds = DB::table('tickets')->whereIn('work_item_id', $workItemIds)->pluck('id');
            DB::table('ticket_allocations')
                ->whereIn('ticket_id', $ticketIds)
                ->whereDate('work_date', '>=', $today)
                ->delete();
            DB::table('tickets')
                ->whereIn('id', $ticketIds)
                ->whereNotIn('status', ['done', 'cancelled'])
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            $locked->update([
                'status' => 'ended',
                'active_employee_id' => null,
                'ended_at' => now(config('app.timezone')),
            ]);
        });
    }
}
