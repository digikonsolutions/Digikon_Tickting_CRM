<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketPlanner
{
    public function plan(WorkItem $workItem, ?int $changedTicketId = null): void
    {
        DB::transaction(function () use ($workItem, $changedTicketId): void {
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $tickets = $lockedWorkItem->tickets()
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            DB::table('ticket_allocations')
                ->whereIn('ticket_id', $tickets->pluck('id'))
                ->delete();

            foreach ($tickets as $ticket) {
                $ticket->forceFill(['start_date' => null, 'due_date' => null])->save();
            }

            $activeTickets = $tickets
                ->whereNotIn('status', ['cancelled', 'done'])
                ->values();

            if ($activeTickets->isEmpty()) {
                return;
            }

            $projectUnits = (int) round((float) $lockedWorkItem->estimated_hours * 100);
            $ticketUnits = $activeTickets->sum(
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

            if (array_sum(array_column($pool, 'remaining')) < $ticketUnits) {
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
        });
    }
}
