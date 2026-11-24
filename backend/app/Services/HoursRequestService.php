<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\HoursRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\HoursRequestNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "I need more hours on this ticket": the assigned employee asks, the agency (owner or client) approves or declines.
 * Approving adds the hours to the ticket's estimate through the same planner as any ticket edit, so the schedule and
 * the project's hour cap are respected; if the project has no hours left the approval fails and nothing changes.
 */
class HoursRequestService
{
    public function __construct(private TicketPlanner $planner, private TicketEventRecorder $recorder, private TicketNotifier $notifier) {}

    public function request(Ticket $ticket, User $employee, float $hours, string $reason): HoursRequest
    {
        $request = DB::transaction(function () use ($ticket, $employee, $hours, $reason): HoursRequest {
            $workItem = WorkItem::query()->lockForUpdate()->findOrFail($ticket->work_item_id);
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($workItem->employee_id !== $employee->id) {
                abort(404);
            }
            $this->assertOpen($locked, $workItem);
            if (HoursRequest::query()->where('ticket_id', $locked->id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['hours' => 'You already have a request open on this ticket. Wait for the answer or cancel it first.']);
            }

            return HoursRequest::create([
                'ticket_id' => $locked->id, 'work_item_id' => $workItem->id, 'agency_id' => $locked->agency_id,
                'requested_by' => $employee->id, 'hours' => $hours, 'reason' => $reason, 'status' => 'pending',
            ]);
        });

        $this->tellAgency($request->load(['ticket.agency', 'requester']));

        return $request;
    }

    public function cancel(HoursRequest $request, User $employee): HoursRequest
    {
        return DB::transaction(function () use ($request, $employee): HoursRequest {
            $locked = HoursRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($locked->requested_by === $employee->id, 404);
            $this->assertPending($locked);
            $locked->forceFill(['status' => 'cancelled', 'decided_at' => now()])->save();

            return $locked;
        });
    }

    public function approve(HoursRequest $request, User $decider): HoursRequest
    {
        $approved = DB::transaction(function () use ($request, $decider): HoursRequest {
            $locked = HoursRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertPending($locked);
            $workItem = WorkItem::query()->lockForUpdate()->findOrFail($locked->work_item_id);
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($locked->ticket_id);
            $this->assertOpen($ticket, $workItem);

            $from = number_format((float) $ticket->estimated_hours, 2, '.', '');
            $to = number_format((float) $ticket->estimated_hours + (float) $locked->hours, 2, '.', '');
            $ticket->update(['estimated_hours' => $to]);
            // Throws (and rolls everything back) when the project has no hours left for this.
            $this->planner->plan($workItem, $ticket->id, [
                'type' => 'ticket_updated', 'ticket_id' => $ticket->id, 'ticket_key' => $ticket->key, 'actor' => $decider, 'actor_name' => $decider->name,
            ]);
            $this->recorder->record($ticket, 'updated', ['changes' => ['estimated_hours' => ['from' => $from, 'to' => $to]], 'cause' => 'hours_request'], $decider);
            $locked->forceFill(['status' => 'approved', 'decided_by' => $decider->id, 'decided_at' => now()])->save();

            return $locked;
        });
        $this->tellEmployee($approved->load(['ticket.agency', 'requester']), 'approved');

        return $approved;
    }

    public function decline(HoursRequest $request, User $decider, ?string $note): HoursRequest
    {
        $declined = DB::transaction(function () use ($request, $decider, $note): HoursRequest {
            $locked = HoursRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertPending($locked);
            $locked->forceFill(['status' => 'declined', 'decided_by' => $decider->id, 'decision_note' => $note, 'decided_at' => now()])->save();

            return $locked;
        });
        $this->tellEmployee($declined->load(['ticket.agency', 'requester']), 'declined');

        return $declined;
    }

    private function assertPending(HoursRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This request has already been answered or withdrawn.']);
        }
    }

    private function assertOpen(Ticket $ticket, WorkItem $workItem): void
    {
        if (in_array($ticket->status, ['done', 'cancelled'], true) || ! in_array($workItem->status, ['scheduled', 'in_progress'], true)) {
            throw ValidationException::withMessages(['status' => 'Hours can only be asked for on open work.']);
        }
        if ($workItem->assignment?->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'This work is no longer assigned.']);
        }
    }

    private function hoursLabel(HoursRequest $request): string
    {
        return rtrim(rtrim(number_format((float) $request->hours, 2, '.', ''), '0'), '.').' h';
    }

    /** The agency owner(s) and the ticket's creator hear about it, in the agency's own words and brand. */
    private function tellAgency(HoursRequest $request): void
    {
        $ticket = $request->ticket;
        $recipients = User::query()
            ->where('agency_id', $request->agency_id)->where('is_active', true)
            ->where(fn ($query) => $query->where('role', Role::AgencyOwner->value)->orWhere('id', $ticket->created_by))
            ->whereIn('role', [Role::AgencyOwner->value, Role::AgencyClient->value])
            ->get();
        foreach ($recipients as $user) {
            $this->notifier->send($user, HoursRequestNotice::class, [
                'ticket_id' => $ticket->id, 'ticket_key' => $ticket->key, 'project_id' => $ticket->work_item_id,
                'title' => "More hours requested on {$ticket->key}",
                'body' => "{$request->requester->name} asks for {$this->hoursLabel($request)} more on “{$ticket->title}”: {$request->reason}",
                'url_path' => "/tickets/{$ticket->key}", 'actor_name' => $request->requester->name, 'action_text' => 'Review request',
            ]);
        }
    }

    private function tellEmployee(HoursRequest $request, string $outcome): void
    {
        $ticket = $request->ticket;
        $body = "Your request for {$this->hoursLabel($request)} more on “{$ticket->title}” was {$outcome}.";
        if ($outcome === 'declined' && filled($request->decision_note)) {
            $body .= " {$request->decision_note}";
        }
        $this->notifier->send($request->requester, HoursRequestNotice::class, [
            'ticket_id' => $ticket->id, 'ticket_key' => $ticket->key, 'project_id' => $ticket->work_item_id,
            'title' => 'Hours request '.$outcome, 'body' => $body, 'url_path' => "/tickets/{$ticket->key}",
        ]);
    }
}
