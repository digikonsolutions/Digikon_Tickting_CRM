<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What an employee was working on, and when: the ticket they had "in progress" (from its status history), or the
 * project when it is worked without tickets. Browser history is only kept for these periods and is linked to them.
 */
class WorkContext
{
    /**
     * What the employee is working on right now, or null.
     *
     * @return array{ticket_id: int|null, ticket_key: string|null, title: string, work_item_id: int, project_title: string}|null
     */
    public function current(User $employee): ?array
    {
        $ticket = Ticket::query()->with(['agency', 'workItem'])
            ->where('status', 'in_progress')
            ->whereHas('workItem', fn ($query) => $query->where('employee_id', $employee->id)->whereIn('status', ['scheduled', 'in_progress']))
            ->orderByDesc('started_at')->orderByDesc('id')
            ->first();
        if ($ticket !== null) {
            return ['ticket_id' => $ticket->id, 'ticket_key' => $ticket->key, 'title' => $ticket->title, 'work_item_id' => $ticket->work_item_id, 'project_title' => $ticket->workItem->title];
        }

        $project = WorkItem::query()->where('employee_id', $employee->id)->where('status', 'in_progress')
            ->whereDoesntHave('tickets', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->orderByDesc('started_at')->first();

        return $project === null ? null : ['ticket_id' => null, 'ticket_key' => null, 'title' => $project->title, 'work_item_id' => $project->id, 'project_title' => $project->title];
    }

    /**
     * Periods of work between two moments: [start, end|null, ticket_id|null, work_item_id], tickets first.
     *
     * @return Collection<int, array{start: CarbonImmutable, end: CarbonImmutable|null, ticket_id: int|null, work_item_id: int}>
     */
    public function periods(User $employee, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $tickets = Ticket::query()
            ->whereHas('workItem', fn ($query) => $query->where('employee_id', $employee->id))
            ->where(fn ($query) => $query->where('status', 'in_progress')->orWhereNotNull('started_at'))
            ->get(['id', 'work_item_id', 'status', 'started_at']);

        $events = TicketEvent::query()
            ->whereIn('ticket_id', $tickets->pluck('id'))
            ->where('type', 'status_changed')
            ->where('created_at', '<=', $to)
            ->orderBy('created_at')->orderBy('id')
            ->get(['ticket_id', 'payload', 'created_at'])
            ->groupBy('ticket_id');

        $periods = collect();
        foreach ($tickets as $ticket) {
            $start = null;
            $history = $events->get($ticket->id, collect());
            foreach ($history as $event) {
                $at = CarbonImmutable::parse($event->created_at);
                if (($event->payload['to'] ?? null) === 'in_progress') {
                    $start ??= $at;
                } elseif (($event->payload['from'] ?? null) === 'in_progress' && $start !== null) {
                    $periods->push(['start' => $start, 'end' => $at, 'ticket_id' => $ticket->id, 'work_item_id' => $ticket->work_item_id]);
                    $start = null;
                }
            }
            // Still in progress: from the last start (or when work first began, for tickets without a status history).
            if ($ticket->status === 'in_progress') {
                $start ??= $ticket->started_at ? CarbonImmutable::parse($ticket->started_at) : null;
                if ($start !== null) {
                    $periods->push(['start' => $start, 'end' => null, 'ticket_id' => $ticket->id, 'work_item_id' => $ticket->work_item_id]);
                }
            }
        }

        // Projects worked directly, without tickets.
        WorkItem::query()->where('employee_id', $employee->id)->whereNotNull('started_at')
            ->whereDoesntHave('tickets', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->get(['id', 'status', 'started_at', 'completed_at', 'updated_at'])
            ->each(function (WorkItem $project) use ($periods): void {
                $end = $project->completed_at ?? ($project->status === 'cancelled' ? $project->updated_at : null);
                $periods->push(['start' => CarbonImmutable::parse($project->started_at), 'end' => $end ? CarbonImmutable::parse($end) : null, 'ticket_id' => null, 'work_item_id' => $project->id]);
            });

        return $periods
            ->filter(fn (array $period): bool => $period['start']->lessThanOrEqualTo($to) && ($period['end'] === null || $period['end']->greaterThanOrEqualTo($from)))
            ->values();
    }

    /**
     * The work a moment belongs to (the most recently started ticket wins), or null outside working time.
     *
     * @param  Collection<int, array{start: CarbonImmutable, end: CarbonImmutable|null, ticket_id: int|null, work_item_id: int}>  $periods
     * @return array{ticket_id: int|null, work_item_id: int}|null
     */
    public function at(Collection $periods, CarbonImmutable $moment): ?array
    {
        $match = $periods
            ->filter(fn (array $period): bool => $period['start']->lessThanOrEqualTo($moment) && ($period['end'] === null || $period['end']->greaterThan($moment)))
            ->sortByDesc(fn (array $period): string => ($period['ticket_id'] !== null ? '1' : '0').$period['start']->format('Y-m-d H:i:s.u'))
            ->first();

        return $match === null ? null : ['ticket_id' => $match['ticket_id'], 'work_item_id' => $match['work_item_id']];
    }
}
