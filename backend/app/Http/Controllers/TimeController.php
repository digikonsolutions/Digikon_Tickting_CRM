<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketAllocation;
use App\Models\TimeEntry;
use App\Services\TicketAccess;
use App\Services\TimeTracker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TimeController extends Controller
{
    public function __construct(private TimeTracker $time, private TicketAccess $access) {}

    public function start(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        $this->access->authorizeView($user, $ticket);
        $result = $this->time->start($user, $ticket);

        return response()->json([
            'entry' => $this->entry($result['started'], $ticket, $user),
            'stopped' => $result['stopped'] ? $this->entry($result['stopped'], null, $user) : null,
        ], 201);
    }

    public function stop(Request $request): JsonResponse
    {
        $stopped = $this->time->stop($request->user());

        return response()->json(['entry' => $this->entry($stopped, null, $request->user())]);
    }

    public function active(Request $request): JsonResponse
    {
        $running = $this->time->runningFor($request->user());

        return response()->json([
            'entry' => $running ? $this->entry($running, $running->ticket()->with('agency')->first(), $request->user()) : null,
            'server_time' => CarbonImmutable::now()->toISOString(),
        ]);
    }

    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        $this->access->authorizeView($user, $ticket);
        $data = $request->validate([
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'minutes' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $entry = $this->time->logManual($user, $ticket, $data['entry_date'], (int) $data['minutes'], $data['note'] ?? null);

        return response()->json(['entry' => $this->entry($entry, $ticket, $user)], 201);
    }

    public function update(Request $request, TimeEntry $entry): JsonResponse
    {
        $data = $request->validate([
            'entry_date' => ['sometimes', 'date_format:Y-m-d'],
            'minutes' => ['sometimes', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $updated = $this->time->update($entry, $request->user(), $data, $data['reason'] ?? null);

        return response()->json(['entry' => $this->entry($updated, null, $request->user())]);
    }

    public function destroy(Request $request, TimeEntry $entry): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $this->time->delete($entry, $request->user(), $data['reason'] ?? null);

        return response()->json(['message' => 'The time entry was removed.']);
    }

    /** Entries and totals for one ticket. Agency users see entries only when config('time.agency_sees_entries'). */
    public function ticket(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        $this->access->authorizeView($user, $ticket);
        $showEntries = $this->access->canSeeInternal($user, $ticket) || (bool) config('time.agency_sees_entries');

        $entries = $ticket->timeEntries()->with('user')->whereNotNull('minutes')
            ->orderByDesc('entry_date')->orderByDesc('id')->get();
        $minutes = (int) $entries->sum('minutes');

        return response()->json([
            'entries' => $showEntries ? $entries->map(fn (TimeEntry $e): array => $this->time->payload($e, $user))->values() : [],
            'entries_visible' => $showEntries,
            'totals' => $this->totals($ticket, $minutes),
        ]);
    }

    /** The employee's timesheet for a Monday-to-Saturday week: logged versus scheduled, per day and per ticket. */
    public function week(Request $request): JsonResponse
    {
        $data = $request->validate(['week' => ['sometimes', 'date_format:Y-m-d']]);
        $user = $request->user();
        $tz = config('app.timezone');
        $weekStart = CarbonImmutable::parse($data['week'] ?? CarbonImmutable::now($tz)->toDateString(), $tz)->startOfWeek(CarbonImmutable::MONDAY);
        $workingDays = config('scheduling.working_days', [1, 2, 3, 4, 5, 6]);
        $weekEnd = $weekStart->addDays(max($workingDays) - 1);

        $logged = TimeEntry::query()
            ->where('user_id', $user->id)->whereNotNull('minutes')
            ->whereDate('entry_date', '>=', $weekStart->toDateString())->whereDate('entry_date', '<=', $weekEnd->toDateString())
            ->selectRaw('ticket_id, entry_date, SUM(minutes) AS minutes')
            ->groupBy('ticket_id', 'entry_date')->get();
        $scheduled = TicketAllocation::query()
            ->join('tickets', 'tickets.id', '=', 'ticket_allocations.ticket_id')
            ->join('work_items', 'work_items.id', '=', 'tickets.work_item_id')
            ->where('work_items.employee_id', $user->id)->where('tickets.status', '!=', 'cancelled')
            ->whereDate('ticket_allocations.work_date', '>=', $weekStart->toDateString())
            ->whereDate('ticket_allocations.work_date', '<=', $weekEnd->toDateString())
            ->selectRaw('ticket_allocations.ticket_id AS ticket_id, ticket_allocations.work_date AS work_date, SUM(ticket_allocations.hours) AS hours')
            ->groupBy('ticket_allocations.ticket_id', 'ticket_allocations.work_date')->get();

        $days = [];
        foreach ($workingDays as $isoDay) {
            $date = $weekStart->addDays($isoDay - 1)->toDateString();
            $days[] = [
                'date' => $date,
                'logged_hours' => TimeTracker::hours($logged->filter(fn ($row) => substr((string) $row->entry_date, 0, 10) === $date)->sum('minutes')),
                'scheduled_hours' => round((float) $scheduled->filter(fn ($row) => substr((string) $row->work_date, 0, 10) === $date)->sum('hours'), 2),
            ];
        }

        $ticketIds = $logged->pluck('ticket_id')->merge($scheduled->pluck('ticket_id'))->unique()->values();
        $tickets = Ticket::query()->with('agency')->whereIn('id', $ticketIds)->get()->map(fn (Ticket $ticket): array => [
            'ticket_id' => $ticket->id,
            'key' => $ticket->key,
            'title' => $ticket->title,
            'logged_hours' => TimeTracker::hours($logged->where('ticket_id', $ticket->id)->sum('minutes')),
            'scheduled_hours' => round((float) $scheduled->where('ticket_id', $ticket->id)->sum('hours'), 2),
        ])->sortBy('key', SORT_NATURAL)->values();

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'days' => $days,
            'tickets' => $tickets,
            'totals' => [
                'logged_hours' => round(collect($days)->sum('logged_hours'), 2),
                'scheduled_hours' => round(collect($days)->sum('scheduled_hours'), 2),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(TimeEntry $entry, ?Ticket $ticket, $viewer): array
    {
        $entry->loadMissing('user');
        $payload = $this->time->payload($entry, $viewer);
        if ($ticket) {
            $payload['ticket'] = ['id' => $ticket->id, 'key' => $ticket->key, 'title' => $ticket->title];
        }

        return $payload;
    }

    /**
     * @return array{logged_hours: float, estimated_hours: float, over_estimate: bool, percent: int|null}
     */
    private function totals(Ticket $ticket, int $minutes): array
    {
        $estimated = (float) $ticket->estimated_hours;
        $logged = TimeTracker::hours($minutes);

        return [
            'logged_hours' => $logged,
            'estimated_hours' => $estimated,
            'over_estimate' => $estimated > 0 && $logged > $estimated,
            'percent' => $estimated > 0 ? (int) round($logged / $estimated * 100) : null,
        ];
    }
}
