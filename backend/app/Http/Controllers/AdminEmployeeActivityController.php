<?php

namespace App\Http\Controllers;

use App\Enums\ActivityEvent;
use App\Enums\Role;
use App\Models\EmployeeActivityLog;
use App\Models\EmployeeAssignment;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Views of one employee's recorded work activity. Routes limit this to admins and agency owners:
 * admins see any employee; an owner sees only an employee currently assigned to the owner's own
 * agency, and only what was recorded since that assignment started. Anyone else gets 404.
 */
class AdminEmployeeActivityController extends Controller
{
    /** Start of the visible period for the viewer, or null for no limit. */
    private ?CarbonImmutable $since = null;

    private function employee(Request $request, int $id): User
    {
        $viewer = $request->user();
        $query = User::query()->where('role', Role::Employee->value);

        if ($viewer->role === Role::Admin) {
            return $query->findOrFail($id);
        }

        abort_unless($viewer->role === Role::AgencyOwner && $viewer->agency_id !== null, 403);
        $assignment = EmployeeAssignment::query()
            ->where('employee_id', $id)
            ->where('agency_id', $viewer->agency_id)
            ->where('status', 'active')
            ->latest('started_at')
            ->first();
        abort_if($assignment === null, 404);
        $this->since = CarbonImmutable::instance($assignment->started_at);

        return $query->findOrFail($id);
    }

    private function showsConnection(Request $request): bool
    {
        return $request->user()->role === Role::Admin;
    }

    private function tz(): string
    {
        return (string) config('app.timezone');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request, int $defaultDays, int $maxDays = 93): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = CarbonImmutable::parse($data['to'] ?? 'today', $this->tz())->endOfDay();
        $from = CarbonImmutable::parse($data['from'] ?? $to->subDays($defaultDays - 1)->toDateString(), $this->tz())->startOfDay();
        if ($this->since !== null && $from->lessThan($this->since)) {
            $from = $this->since;
        }
        abort_if($from->diffInDays($to) > $maxDays, 422, "The date range may not exceed {$maxDays} days.");

        return [$from, $to];
    }

    public function activity(Request $request, int $id): JsonResponse
    {
        $employee = $this->employee($request, $id);
        [$from, $to] = $this->range($request, 14, 366);
        $connection = $this->showsConnection($request);
        $filters = $request->validate([
            'event_type' => ['sometimes', Rule::in(ActivityEvent::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = EmployeeActivityLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->when($filters['event_type'] ?? null, fn ($query, $type) => $query->where('event_type', $type))
            ->with(['ticket:id,agency_id,number,title', 'ticket.agency:id,ticket_prefix', 'workItem:id,title'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->name],
            'events' => $page->getCollection()->map(fn (EmployeeActivityLog $log): array => $this->eventPayload($log, $connection))->values(),
            'pagination' => $this->paginationPayload($page),
        ]);
    }

    /**
     * The same recorded events as `activity()`, filtered to one project (its ticket updates and
     * its own project-status changes). This is the "employee timeline" embedded in Project Details
     * on the Agency Owner Dashboard: agency owners only, their own agency, and only while the
     * project's employee is currently assigned to that agency.
     */
    public function projectActivity(Request $request, WorkItem $workItem): JsonResponse
    {
        $viewer = $request->user();
        abort_unless(
            $viewer->role === Role::AgencyOwner && $viewer->agency_id !== null && $workItem->agency_id === $viewer->agency_id,
            404
        );
        abort_if($workItem->employee_id === null, 404);

        $assignment = EmployeeAssignment::query()
            ->where('employee_id', $workItem->employee_id)
            ->where('agency_id', $viewer->agency_id)
            ->where('status', 'active')
            ->latest('started_at')
            ->first();
        abort_if($assignment === null, 404);
        $this->since = CarbonImmutable::instance($assignment->started_at);

        [$from, $to] = $this->range($request, 30, 366);
        $filters = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $employee = $workItem->employee()->firstOrFail();

        $page = EmployeeActivityLog::query()
            ->where('employee_id', $employee->id)
            ->where('work_item_id', $workItem->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->with(['ticket:id,agency_id,number,title', 'ticket.agency:id,ticket_prefix'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'photo_url' => $employee->photoUrl()],
            'project' => ['id' => $workItem->id, 'title' => $workItem->title],
            'events' => $page->getCollection()->map(fn (EmployeeActivityLog $log): array => $this->eventPayload($log, false))->values(),
            'pagination' => $this->paginationPayload($page),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(EmployeeActivityLog $log, bool $withConnection): array
    {
        return [
            'id' => $log->id,
            'event_type' => $log->event_type,
            'occurred_at' => $log->occurred_at->toISOString(),
            'ticket' => $log->ticket ? ['id' => $log->ticket->id, 'key' => $log->ticket->key, 'title' => $log->ticket->title] : null,
            'project' => $log->workItem ? ['id' => $log->workItem->id, 'title' => $log->workItem->title] : null,
            'metadata' => $log->metadata,
            // Connection details are for administrators only.
            'ip_address' => $withConnection ? $log->ip_address : null,
            'user_agent' => $withConnection ? $log->user_agent : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paginationPayload($page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    public function performance(Request $request, int $id): JsonResponse
    {
        $employee = $this->employee($request, $id);
        [$from, $to] = $this->range($request, 14);
        $tz = $this->tz();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        // Active and idle seconds per day (a session counts on the day it started).
        $sessions = WorkSession::query()->where('employee_id', $employee->id)
            ->whereBetween('started_at', [$from, $to])->get(['started_at', 'active_seconds', 'idle_seconds']);
        $active = $idle = [];
        foreach ($sessions as $session) {
            $day = $session->started_at->setTimezone($tz)->toDateString();
            $active[$day] = ($active[$day] ?? 0) + $session->active_seconds;
            $idle[$day] = ($idle[$day] ?? 0) + $session->idle_seconds;
        }

        // Hours logged through time tracking.
        $logged = DB::table('time_entries')->where('user_id', $employee->id)->whereNull('deleted_at')->whereNotNull('minutes')
            ->whereDate('entry_date', '>=', $fromDate)->whereDate('entry_date', '<=', $toDate)
            ->selectRaw('entry_date AS day, SUM(minutes) AS minutes')->groupBy('entry_date')->pluck('minutes', 'day');

        // Hours scheduled by the planner for this employee's non-cancelled tickets.
        $scheduled = DB::table('ticket_allocations as ta')
            ->join('tickets as t', 't.id', '=', 'ta.ticket_id')
            ->join('work_items as wi', 'wi.id', '=', 't.work_item_id')
            ->where('wi.employee_id', $employee->id)->where('t.status', '!=', 'cancelled')
            ->whereDate('ta.work_date', '>=', $fromDate)->whereDate('ta.work_date', '<=', $toDate)
            ->selectRaw('ta.work_date AS day, SUM(ta.hours) AS hours')->groupBy('ta.work_date')->pluck('hours', 'day');

        // First login per day against the configured start of the working day.
        $firstLogins = [];
        EmployeeActivityLog::query()->where('employee_id', $employee->id)->where('event_type', ActivityEvent::Login->value)
            ->whereBetween('occurred_at', [$from, $to])->orderBy('occurred_at')->get(['occurred_at'])
            ->each(function ($log) use (&$firstLogins, $tz): void {
                $local = $log->occurred_at->setTimezone($tz);
                $firstLogins[$local->toDateString()] ??= $local;
            });
        $grace = (int) config('activity.login_grace_minutes');

        $days = [];
        $cursor = $from;
        while ($cursor->lessThanOrEqualTo($to)) {
            $date = $cursor->toDateString();
            $key = substr($date, 0, 10);
            $loggedMinutes = (float) collect($logged)->first(fn ($value, $day) => substr((string) $day, 0, 10) === $key, 0);
            $scheduledHours = (float) collect($scheduled)->first(fn ($value, $day) => substr((string) $day, 0, 10) === $key, 0);
            $login = $firstLogins[$date] ?? null;
            $entry = [
                'date' => $date,
                'active_hours' => round(($active[$date] ?? 0) / 3600, 2),
                'idle_hours' => round(($idle[$date] ?? 0) / 3600, 2),
                'logged_hours' => round($loggedMinutes / 60, 2),
                'scheduled_hours' => round($scheduledHours, 2),
                'first_login' => $login?->format('H:i'),
                'login_status' => null,
                'login_offset_minutes' => null,
            ];
            if ($login) {
                $start = CarbonImmutable::parse($date.' '.config('activity.workday_start'), $tz);
                $offset = (int) round($start->diffInMinutes($login, false));
                $entry['login_offset_minutes'] = $offset;
                $entry['login_status'] = $offset > $grace ? 'late' : ($offset < -$grace ? 'early' : 'on_time');
            }
            $days[] = $entry;
            $cursor = $cursor->addDay();
        }

        // Weeks start on Monday.
        $weeks = collect($days)->groupBy(fn (array $day): string => CarbonImmutable::parse($day['date'])->startOfWeek()->toDateString())
            ->map(fn ($items, string $week): array => [
                'week_start' => $week,
                'active_hours' => round($items->sum('active_hours'), 2),
                'logged_hours' => round($items->sum('logged_hours'), 2),
                'scheduled_hours' => round($items->sum('scheduled_hours'), 2),
            ])->values();

        // Tickets that reached "done" in the range on this employee's projects.
        $doneEvents = TicketEvent::query()
            ->join('work_items', 'work_items.id', '=', 'ticket_events.work_item_id')
            ->where('work_items.employee_id', $employee->id)->where('ticket_events.type', 'status_changed')
            ->whereBetween('ticket_events.created_at', [$from, $to])
            ->get(['ticket_events.ticket_id', 'ticket_events.payload'])
            ->filter(fn ($event): bool => ($event->payload['to'] ?? null) === 'done');
        $doneIds = $doneEvents->pluck('ticket_id')->unique()->values();
        $loggedOnDone = $doneIds->isEmpty() ? 0 : (int) DB::table('time_entries')->whereIn('ticket_id', $doneIds)->whereNull('deleted_at')->sum('minutes');

        $totals = [
            'active_hours' => round(collect($days)->sum('active_hours'), 2),
            'idle_hours' => round(collect($days)->sum('idle_hours'), 2),
            'logged_hours' => round(collect($days)->sum('logged_hours'), 2),
            'scheduled_hours' => round(collect($days)->sum('scheduled_hours'), 2),
            'tickets_completed' => $doneIds->count(),
            'avg_logged_hours_per_ticket' => $doneIds->isEmpty() ? null : round($loggedOnDone / 60 / $doneIds->count(), 2),
            'late_logins' => collect($days)->where('login_status', 'late')->count(),
            'early_logins' => collect($days)->where('login_status', 'early')->count(),
        ];

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->name],
            'from' => $fromDate,
            'to' => $toDate,
            'workday_start' => config('activity.workday_start'),
            'days' => $days,
            'weeks' => $weeks,
            'totals' => $totals,
        ]);
    }
}
