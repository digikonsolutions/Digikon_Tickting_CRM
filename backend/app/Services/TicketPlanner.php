<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketPlanner
{
    public function __construct(private ?TicketEventRecorder $recorder = null)
    {
        $this->recorder ??= app(TicketEventRecorder::class);
    }

    /**
     * @param  array{type?: string, ticket_id?: int, ticket_key?: string, actor?: User|null, actor_id?: int|null, actor_name?: string|null}|null  $cause
     * @return list<array{key: string|null, from: array{start: string|null, due: string|null}, to: array{start: string|null, due: string|null}, reason: string}>
     */
    public function plan(WorkItem $workItem, ?int $changedTicketId = null, ?array $cause = null): array
    {
        return DB::transaction(function () use ($workItem, $changedTicketId, $cause): array {
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $tickets = $lockedWorkItem->tickets()
                ->with('agency')
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            $replannableTickets = $tickets->where('status', '!=', 'done');

            $snapshots = [];
            foreach ($replannableTickets as $ticket) {
                $snapshots[$ticket->id] = [
                    'start_date' => $ticket->start_date?->toDateString(),
                    'due_date' => $ticket->due_date?->toDateString(),
                ];
            }

            DB::table('ticket_allocations')
                ->whereIn('ticket_id', $replannableTickets->pluck('id'))
                ->delete();

            foreach ($replannableTickets as $ticket) {
                $ticket->forceFill(['start_date' => null, 'due_date' => null])->save();
            }

            $activeTickets = $tickets
                ->whereNotIn('status', ['cancelled', 'done'])
                ->values();

            if ($activeTickets->isEmpty()) {
                return [];
            }

            $projectUnits = (int) round((float) $lockedWorkItem->estimated_hours * 100);
            $ticketUnits = $tickets->where('status', '!=', 'cancelled')->sum(
                fn (Ticket $ticket): int => (int) round((float) $ticket->estimated_hours * 100)
            );

            if ($ticketUnits > $projectUnits) {
                $changedTicket = $changedTicketId === null
                    ? null
                    : $activeTickets->firstWhere('id', $changedTicketId);
                $changedTicketUnits = (int) round((float) ($changedTicket?->estimated_hours ?? 0) * 100);
                $remainingUnits = max(0, $projectUnits - ($ticketUnits - $changedTicketUnits));

                throw ValidationException::withMessages([
                    'estimated_hours' => "Ticket hours exceed the project's hours. Remaining: ".number_format($remainingUnits / 100, 2, '.', '').' h.',
                ]);
            }

            $pool = $lockedWorkItem->allocations()
                ->orderBy('work_date')
                ->get(['work_date', 'hours'])
                ->map(fn ($allocation): array => [
                    'work_date' => $allocation->work_date->toDateString(),
                    'remaining' => (int) round((float) $allocation->hours * 100),
                ])
                ->all();

            $poolIndexesByDate = [];
            foreach ($pool as $index => $allocation) {
                $poolIndexesByDate[$allocation['work_date']] = $index;
            }

            $doneAllocations = DB::table('ticket_allocations')
                ->join('tickets', 'tickets.id', '=', 'ticket_allocations.ticket_id')
                ->where('tickets.work_item_id', $lockedWorkItem->id)
                ->where('tickets.status', 'done')
                ->selectRaw('ticket_allocations.work_date, SUM(ticket_allocations.hours) AS hours')
                ->groupBy('ticket_allocations.work_date')
                ->get();

            foreach ($doneAllocations as $doneAllocation) {
                $date = (string) $doneAllocation->work_date;
                if (! isset($poolIndexesByDate[$date])) {
                    continue;
                }

                $poolIndex = $poolIndexesByDate[$date];
                $usedUnits = (int) round((float) $doneAllocation->hours * 100);
                $pool[$poolIndex]['remaining'] = max(0, $pool[$poolIndex]['remaining'] - $usedUnits);
            }

            if (array_sum(array_column($pool, 'remaining')) < $activeTickets->sum(
                fn (Ticket $ticket): int => (int) round((float) $ticket->estimated_hours * 100)
            )) {
                throw ValidationException::withMessages([
                    'estimated_hours' => 'Ticket hours exceed the project work allocation capacity.',
                ]);
            }

            $poolIndex = 0;
            foreach ($activeTickets as $ticket) {
                $remainingUnits = (int) round((float) $ticket->estimated_hours * 100);
                $ticketDates = [];

                while ($remainingUnits > 0) {
                    while (isset($pool[$poolIndex]) && $pool[$poolIndex]['remaining'] === 0) {
                        $poolIndex++;
                    }

                    if (! isset($pool[$poolIndex])) {
                        throw ValidationException::withMessages([
                            'estimated_hours' => 'Ticket hours exceed the project work allocation capacity.',
                        ]);
                    }

                    $allocatedUnits = min($remainingUnits, $pool[$poolIndex]['remaining']);
                    $workDate = $pool[$poolIndex]['work_date'];
                    DB::table('ticket_allocations')->insert([
                        'ticket_id' => $ticket->id,
                        'work_date' => $workDate,
                        'hours' => WorkItemScheduler::unitsToHours($allocatedUnits),
                    ]);

                    $pool[$poolIndex]['remaining'] -= $allocatedUnits;
                    $remainingUnits -= $allocatedUnits;
                    $ticketDates[] = $workDate;
                }

                $ticket->forceFill([
                    'start_date' => $ticketDates[0],
                    'due_date' => $ticketDates[array_key_last($ticketDates)],
                ])->save();
            }

            // Check for rescheduled events
            $rescheduled = [];
            $actor = $cause['actor'] ?? (isset($cause['actor_id']) ? User::find($cause['actor_id']) : null);
            $causeTicketKey = $cause['ticket_key'] ?? (isset($cause['ticket_id']) ? Ticket::find($cause['ticket_id'])?->key : null);
            $causeActorName = $cause['actor_name'] ?? $actor?->name;

            foreach ($activeTickets as $ticket) {
                $oldStart = $snapshots[$ticket->id]['start_date'] ?? null;
                $oldDue = $snapshots[$ticket->id]['due_date'] ?? null;
                $newStart = $ticket->start_date?->toDateString();
                $newDue = $ticket->due_date?->toDateString();

                // Never record for brand new ticket first scheduling
                if ($oldStart === null && $oldDue === null) {
                    continue;
                }

                // Never record if dates did not change
                if ($oldStart === $newStart && $oldDue === $newDue) {
                    continue;
                }

                $reason = $this->buildReason($oldStart, $oldDue, $newStart, $newDue, $cause, $causeTicketKey, $causeActorName);

                $this->recorder->record(
                    $ticket,
                    'rescheduled',
                    [
                        'from' => ['start' => $oldStart, 'due' => $oldDue],
                        'to' => ['start' => $newStart, 'due' => $newDue],
                        'cause' => [
                            'type' => $cause['type'] ?? 'unknown',
                            'ticket_key' => $causeTicketKey,
                            'actor_name' => $causeActorName,
                        ],
                        'reason' => $reason,
                    ],
                    $actor
                );

                $rescheduled[] = [
                    'key' => $ticket->key,
                    'from' => ['start' => $oldStart, 'due' => $oldDue],
                    'to' => ['start' => $newStart, 'due' => $newDue],
                    'reason' => $reason,
                ];
            }

            return $rescheduled;
        });
    }

    private function buildReason(
        ?string $oldStart,
        ?string $oldDue,
        ?string $newStart,
        ?string $newDue,
        ?array $cause,
        ?string $causeTicketKey,
        ?string $causeActorName
    ): string {
        if (! $cause || empty($cause['type'])) {
            return 'Schedule recalculated';
        }

        $fromDate = $oldDue ?? $oldStart;
        $toDate = $newDue ?? $newStart;
        if (! $fromDate || ! $toDate) {
            return 'Schedule recalculated';
        }

        $fromFormatted = CarbonImmutable::parse($fromDate)->format('M j');
        $toFormatted = CarbonImmutable::parse($toDate)->format('M j');

        $type = $cause['type'];
        $because = match ($type) {
            'reordered' => $causeTicketKey && $causeActorName
                ? "because {$causeTicketKey} was prioritised by {$causeActorName}"
                : ($causeTicketKey ? "because {$causeTicketKey} was prioritised" : 'because tickets were reordered'),
            'ticket_cancelled' => $causeTicketKey
                ? "because {$causeTicketKey} was cancelled"
                : 'because a ticket was cancelled',
            'ticket_created' => $causeTicketKey && $causeActorName
                ? "because {$causeTicketKey} was added by {$causeActorName}"
                : ($causeTicketKey ? "because {$causeTicketKey} was added" : 'because a ticket was added'),
            'ticket_updated' => $causeTicketKey && $causeActorName
                ? "because {$causeTicketKey} was updated by {$causeActorName}"
                : ($causeTicketKey ? "because {$causeTicketKey} was updated" : 'because a ticket was updated'),
            'status_changed' => $causeTicketKey
                ? "because {$causeTicketKey} status changed"
                : 'because ticket status changed',
            default => 'because of schedule changes',
        };

        return "Moved from {$fromFormatted} to {$toFormatted} {$because}";
    }
}
