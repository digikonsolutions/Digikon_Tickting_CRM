<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Controllers\Concerns\GuardsProjectAccess;
use App\Http\Requests\ToggleChecklistItemRequest;
use App\Http\Requests\UpdateInternalNotesRequest;
use App\Http\Requests\UpdateTicketBlockedRequest;
use App\Http\Requests\UpdateTicketDeliveryRequest;
use App\Models\Ticket;
use App\Models\TicketChecklistItem;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\TicketDetailsService;
use App\Services\TicketTemplateRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The parts of a ticket's work order that are not written with the ticket itself: the assigned employee's delivery and
 * blocked note, ticking the checklist, internal notes, and the template list. None of this changes the status, the
 * hours or the schedule.
 */
class TicketDetailsController extends Controller
{
    use GuardsProjectAccess;

    public function templates(TicketTemplateRegistry $templates): JsonResponse
    {
        return response()->json(['templates' => $templates->all()]);
    }

    public function delivery(UpdateTicketDeliveryRequest $request, Ticket $ticket, TicketDetailsService $details): JsonResponse
    {
        $this->authorizeAssignedEmployee($request->user(), $ticket);
        DB::transaction(fn () => $details->deliver($ticket, $request->validated()));

        return $this->respond($ticket, $request->user(), $details);
    }

    public function blocked(UpdateTicketBlockedRequest $request, Ticket $ticket, TicketDetailsService $details): JsonResponse
    {
        $this->authorizeAssignedEmployee($request->user(), $ticket);
        $data = $request->validated();
        DB::transaction(fn () => $details->setBlocked($ticket, (bool) $data['blocked'], $data['reason'] ?? null, $data['waiting_on'] ?? null));

        return $this->respond($ticket, $request->user(), $details);
    }

    /** Tick or untick one checklist item: the assigned employee, or the agency that owns the ticket. */
    public function toggleChecklist(ToggleChecklistItemRequest $request, Ticket $ticket, TicketChecklistItem $item, TicketDetailsService $details): JsonResponse
    {
        $user = $request->user();
        abort_unless($item->ticket_id === $ticket->id, 404);
        if ($user->role === Role::Employee) {
            $this->authorizeAssignedEmployee($user, $ticket);
        } else {
            $workItem = $ticket->workItem;
            $this->authorizeProject($user, $workItem, requireOpen: true, requireActiveAssignment: true);
            abort_unless($ticket->agency_id === $user->agency_id, 404);
            abort_if(in_array($ticket->status, ['done', 'cancelled'], true), 422, 'This ticket is already closed.');
        }
        DB::transaction(fn () => $details->toggle($ticket, $item, (bool) $request->validated('done'), $user));

        return $this->respond($ticket, $user, $details);
    }

    /** Internal notes on a ticket: administrators only write them. */
    public function updateInternalNotes(UpdateInternalNotesRequest $request, Ticket $ticket, TicketDetailsService $details): JsonResponse
    {
        $details->saveInternalNotes($ticket, $request->validated('notes'));

        return $this->respond($ticket, $request->user(), $details);
    }

    /** The assigned employee of an open project with an active assignment; anyone else gets a 404. */
    private function authorizeAssignedEmployee(User $user, Ticket $ticket): void
    {
        $this->ensureActiveEmployeeAgency($user);
        $workItem = $ticket->workItem;
        abort_unless($workItem instanceof WorkItem && $workItem->employee_id === $user->id, 404);
        $this->ensureOpenProject($workItem);
        $this->ensureActiveProjectAssignment($workItem);
        abort_if(in_array($ticket->status, ['done', 'cancelled'], true), 422, 'This ticket is already closed.');
    }

    private function respond(Ticket $ticket, User $viewer, TicketDetailsService $details): JsonResponse
    {
        return response()->json(['work_order' => $details->full($ticket->unsetRelation('detail')->load('detail'), $viewer)]);
    }
}
