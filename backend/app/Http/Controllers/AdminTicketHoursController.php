<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\WorkItem;
use App\Services\ProjectHours;
use App\Services\TicketEventRecorder;
use App\Services\TicketPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An administrator changes the hours of one ticket after the agency has seen them. The change needs a reason, stays
 * inside the project's total and above the time already logged, replans the project and tells the agency and employee.
 */
class AdminTicketHoursController extends Controller
{
    public function update(Request $request, Ticket $ticket, TicketPlanner $planner, TicketEventRecorder $recorder, ProjectHours $hours): JsonResponse
    {
        $data = $request->validate([
            'estimated_hours' => ['required', 'numeric', 'decimal:0,2', 'min:0.25', 'max:'.config('scheduling.max_project_hours', 180)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        abort_if($ticket->work_item_id === null, 404);

        $result = $this->apply($ticket, (float) $data['estimated_hours'], $data['reason'], $request->user(), $planner, $recorder, $hours);

        return response()->json($result);
    }

    /** Moves hours from one ticket to another of the same project; both changes happen together or not at all. */
    public function move(Request $request, WorkItem $workItem, TicketPlanner $planner, TicketEventRecorder $recorder, ProjectHours $hours): JsonResponse
    {
        $data = $request->validate([
            'from_ticket_id' => ['required', 'integer', 'different:to_ticket_id'],
            'to_ticket_id' => ['required', 'integer'],
            'hours' => ['required', 'numeric', 'decimal:0,2', 'min:0.25'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $from = $workItem->tickets()->findOrFail($data['from_ticket_id']);
        $to = $workItem->tickets()->findOrFail($data['to_ticket_id']);
        $moved = (float) $data['hours'];
        $user = $request->user();

        $result = DB::transaction(function () use ($from, $to, $moved, $data, $user, $planner, $recorder, $hours): array {
            // The giving ticket shrinks first, so the project never holds more than its total in between.
            $this->apply($from, round((float) $from->estimated_hours - $moved, 2), $data['reason'], $user, $planner, $recorder, $hours, field: 'hours');
            $last = $this->apply($to->fresh(), round((float) $to->estimated_hours + $moved, 2), $data['reason'], $user, $planner, $recorder, $hours, field: 'hours');

            return $last;
        });

        return response()->json($result);
    }

    private function apply(Ticket $ticket, float $newHours, string $reason, $user, TicketPlanner $planner, TicketEventRecorder $recorder, ProjectHours $hours, string $field = 'estimated_hours'): array
    {
        return DB::transaction(function () use ($ticket, $newHours, $reason, $user, $planner, $recorder, $hours, $field): array {
            $project = WorkItem::query()->lockForUpdate()->findOrFail($ticket->work_item_id);
            $locked = $project->tickets()->lockForUpdate()->findOrFail($ticket->id);
            if (in_array($locked->status, ['done', 'cancelled'], true)) {
                throw ValidationException::withMessages([$field => 'This ticket is closed, so its hours cannot change.']);
            }
            if (in_array($project->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([$field => 'This project is closed, so ticket hours cannot change.']);
            }
            if ($newHours < 0.25) {
                throw ValidationException::withMessages([$field => 'A ticket needs at least 0.25 h.']);
            }
            $before = (float) $locked->estimated_hours;
            if (abs($before - $newHours) < 0.005) {
                return ['ticket_id' => $locked->id, 'estimated_hours' => number_format($before, 2, '.', ''), 'changed' => false, 'hours' => $hours->summary($project)];
            }
            try {
                $hours->validateChange($locked, $project, $newHours);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([$field => $e->errors()['estimated_hours'][0] ?? 'Those hours do not fit.']);
            }

            $locked->update(['estimated_hours' => \App\Services\WorkItemScheduler::unitsToHours((int) round($newHours * 100))]);
            $recorder->record($locked, 'hours_adjusted', [
                'from' => number_format($before, 2, '.', ''), 'to' => number_format($newHours, 2, '.', ''), 'reason' => $reason,
            ], $user);
            $planner->plan($project, $locked->id, [
                'type' => 'ticket_updated', 'ticket_id' => $locked->id, 'ticket_key' => $locked->key, 'actor' => $user, 'actor_name' => $user->name,
            ]);

            return ['ticket_id' => $locked->id, 'estimated_hours' => number_format($newHours, 2, '.', ''), 'changed' => true, 'hours' => $hours->summary($project->fresh())];
        });
    }
}