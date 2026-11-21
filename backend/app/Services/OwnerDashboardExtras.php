<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The parts of the agency owner's dashboard that need tickets, activity and a merged "needs attention" list.
 * Everything is filtered by the agency id, so one agency's rows cannot reach another's dashboard.
 */
class OwnerDashboardExtras
{
    private const OPEN = ['scheduled', 'in_progress'];

    /** Statuses where the next move is the agency's: the employee asked a question, or finished and awaits review. */
    public const WAITING_ON_AGENCY = ['waiting_on_client', 'in_review'];

    /** @return array{open_projects: int, tickets_in_progress: int, tickets_waiting_on_you: int, clients: int} */
    public function kpis(int $agencyId): array
    {
        $tickets = DB::table('tickets as t')->join('work_items as w', 'w.id', '=', 't.work_item_id')->where('w.agency_id', $agencyId);
        $byStatus = (clone $tickets)->selectRaw('t.status, COUNT(*) as n')->groupBy('t.status')->pluck('n', 'status');

        return [
            'open_projects' => (int) DB::table('work_items')->where('agency_id', $agencyId)->whereIn('status', self::OPEN)->count(),
            'tickets_in_progress' => (int) ($byStatus['in_progress'] ?? 0),
            'tickets_waiting_on_you' => (int) collect(self::WAITING_ON_AGENCY)->sum(fn (string $s): int => (int) ($byStatus[$s] ?? 0)),
            'clients' => (int) User::query()->where('agency_id', $agencyId)->where('role', Role::AgencyClient->value)->where('is_active', true)->count(),
        ];
    }

    /**
     * Top open projects, with progress measured in ticket hours (done estimate / all non-cancelled estimate).
     *
     * @return list<array<string, mixed>>
     */
    public function activeProjects(int $agencyId, int $limit = 5): array
    {
        $rows = DB::table('work_items as w')
            ->leftJoin('users as e', 'e.id', '=', 'w.employee_id')
            ->leftJoin('users as c', 'c.id', '=', 'w.client_id')
            ->where('w.agency_id', $agencyId)->whereIn('w.status', self::OPEN)
            ->orderByRaw('w.due_date IS NULL')->orderBy('w.due_date')->orderBy('w.id')->limit($limit)
            ->get(['w.id', 'w.title', 'w.status', 'w.due_date', 'w.estimated_hours', 'e.id as employee_id', 'e.name as employee_name', 'c.name as client_name']);
        $progress = DB::table('tickets')->whereIn('work_item_id', $rows->pluck('id'))->where('status', '<>', 'cancelled')
            ->selectRaw("work_item_id, SUM(estimated_hours) as total, SUM(CASE WHEN status = 'done' THEN estimated_hours ELSE 0 END) as done")
            ->groupBy('work_item_id')->get()->keyBy('work_item_id');
        $today = AgencyInsights::today()->toDateString();

        return $rows->map(function (object $row) use ($progress, $today): array {
            $p = $progress[$row->id] ?? null;
            $total = $p ? (float) $p->total : 0.0;
            $due = $row->due_date ? CarbonImmutable::parse($row->due_date)->toDateString() : null;

            return [
                'id' => (int) $row->id,
                'title' => $row->title,
                'status' => $row->status,
                'client_name' => $row->client_name,
                'employee' => $row->employee_id ? ['id' => (int) $row->employee_id, 'name' => $row->employee_name] : null,
                'due_date' => $due,
                'estimated_hours' => (float) $row->estimated_hours,
                'ticket_hours_total' => round($total, 2),
                'ticket_hours_done' => round($p ? (float) $p->done : 0.0, 2),
                'progress_percent' => $total > 0 ? (int) round(((float) $p->done) / $total * 100) : 0,
                'health' => $due !== null && $due < $today ? 'overdue' : ($due !== null && $due <= CarbonImmutable::parse($today)->addDays(2)->toDateString() ? 'at_risk' : 'on_track'),
            ];
        })->values()->all();
    }

    /**
     * Hours booked per working day of this week for each held employee, keyed by assignment id.
     *
     * @param  list<array<string, mixed>>  $employees
     * @return list<array<string, mixed>>
     */
    public function withDailyHours(int $agencyId, array $employees): array
    {
        $week = AgencyInsights::weekBounds();
        $byDay = DB::table('work_item_allocations as al')->join('work_items as w', 'w.id', '=', 'al.work_item_id')
            ->where('w.agency_id', $agencyId)->where('w.status', '<>', 'cancelled')
            ->whereIn('w.assignment_id', array_column($employees, 'assignment_id'))
            ->whereDate('al.work_date', '>=', $week['week_start'])->whereDate('al.work_date', '<=', $week['week_end'])
            ->selectRaw('w.assignment_id, DATE(al.work_date) as d, SUM(al.hours) as hours')->groupBy('w.assignment_id')->groupByRaw('DATE(al.work_date)')->get()
            ->groupBy('assignment_id');
        $start = CarbonImmutable::parse($week['week_start']);
        $dayCount = (int) $start->diffInDays(CarbonImmutable::parse($week['week_end'])) + 1;

        return array_map(function (array $employee) use ($byDay, $start, $dayCount): array {
            $mine = ($byDay[$employee['assignment_id']] ?? collect())->pluck('hours', 'd');
            $employee['days'] = array_map(fn (int $i): array => [
                'date' => $start->addDays($i)->toDateString(),
                'hours' => round((float) ($mine[$start->addDays($i)->toDateString()] ?? 0), 2),
            ], range(0, $dayCount - 1));

            return $employee;
        }, $employees);
    }

    /**
     * One merged list for the "Needs attention" card. Each item has a plain sentence, a count, a severity and one action.
     *
     * @param  array<string, mixed>|null  $contract
     * @param  list<array<string, mixed>>  $employees
     * @return list<array<string, mixed>>
     */
    public function needsAttention(int $agencyId, ?array $contract, float $hoursUsed, array $employees, int $seatsUsed, ?int $seats): array
    {
        $items = [];
        $plural = fn (int $n, string $one, string $many): string => $n.' '.($n === 1 ? $one : $many);

        foreach (AgencyInsights::contractAttention($contract, $hoursUsed) as $item) {
            $isHours = str_starts_with($item['type'], 'hours');
            $items[] = [
                ...$item,
                'label' => $item['type'] === 'hours_nearly_used' ? 'Fewer than 20% of your included hours are left' : $item['label'],
                'action' => $isHours ? ['label' => 'Request more hours', 'request' => 'more_hours'] : ['label' => 'Request renewal', 'request' => 'renewal'],
            ];
        }

        $waiting = $this->kpis($agencyId)['tickets_waiting_on_you'];
        if ($waiting > 0) {
            $items[] = ['type' => 'tickets_waiting', 'severity' => 'warning', 'count' => $waiting, 'label' => $plural($waiting, 'ticket is', 'tickets are').' waiting for your review or answer', 'action' => ['label' => 'Open tickets', 'to' => '/agency/tickets?waiting=you']];
        }

        $today = AgencyInsights::today();
        $open = "'".implode("','", self::OPEN)."'";
        $row = DB::table('work_items')->where('agency_id', $agencyId)->selectRaw(
            "SUM(CASE WHEN status IN ({$open}) AND DATE(due_date) < ? THEN 1 ELSE 0 END) AS overdue,
             SUM(CASE WHEN status IN ({$open}) AND DATE(due_date) >= ? AND DATE(due_date) <= ? THEN 1 ELSE 0 END) AS due_soon",
            [$today->toDateString(), $today->toDateString(), $today->addDays(2)->toDateString()]
        )->first();
        if ((int) $row->overdue > 0) {
            $items[] = ['type' => 'overdue_projects', 'severity' => 'danger', 'count' => (int) $row->overdue, 'label' => $plural((int) $row->overdue, 'project is', 'projects are').' overdue', 'action' => ['label' => 'Review projects', 'to' => '/agency/projects?health=overdue']];
        }
        if ((int) $row->due_soon > 0) {
            $items[] = ['type' => 'due_soon_projects', 'severity' => 'warning', 'count' => (int) $row->due_soon, 'label' => $plural((int) $row->due_soon, 'project is', 'projects are').' due within 48 hours', 'action' => ['label' => 'Review projects', 'to' => '/agency/projects?health=at_risk']];
        }

        if ($employees === []) {
            $items[] = ['type' => 'no_employee', 'severity' => 'warning', 'count' => 1, 'label' => $seats !== null && $seatsUsed < $seats ? 'No employee is assigned to your agency yet' : 'Your agency has no employee assigned', 'action' => ['label' => 'Request an employee', 'to' => '/agency/employees']];
        }
        $full = array_filter($employees, fn (array $e): bool => $e['utilization_percent'] >= 100);
        $busy = array_filter($employees, fn (array $e): bool => $e['utilization_percent'] >= 90 && $e['utilization_percent'] < 100);
        if ($full !== []) {
            $items[] = ['type' => 'employee_at_capacity', 'severity' => 'danger', 'count' => count($full), 'label' => $plural(count($full), 'employee is', 'employees are').' fully booked this week', 'action' => ['label' => 'View employees', 'to' => '/agency/employees']];
        } elseif ($busy !== []) {
            $items[] = ['type' => 'employee_near_capacity', 'severity' => 'warning', 'count' => count($busy), 'label' => $plural(count($busy), 'employee is', 'employees are').' almost fully booked this week', 'action' => ['label' => 'View employees', 'to' => '/agency/employees']];
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn (array $a, array $b): int => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return $items;
    }

    /**
     * The last events across the agency: ticket changes and comments (public ones only) and new clients.
     *
     * @return list<array<string, mixed>>
     */
    public function activity(int $agencyId, int $limit = 10): array
    {
        $labels = ['created' => 'created', 'updated' => 'updated', 'cancelled' => 'cancelled', 'status_changed' => 'moved', 'comment' => 'commented on', 'attachment_added' => 'attached a file to'];
        $events = DB::table('ticket_events as e')->join('tickets as t', 't.id', '=', 'e.ticket_id')->join('work_items as w', 'w.id', '=', 'e.work_item_id')->join('agencies as a', 'a.id', '=', 'w.agency_id')
            ->where('w.agency_id', $agencyId)->where('e.visibility', 'public')->whereNotIn('e.type', ['reordered', 'time_logged', 'attachment_removed'])
            ->orderByDesc('e.id')->limit($limit)
            ->get(['e.id', 'e.type', 'e.actor_name', 'e.created_at', 't.number', 'a.ticket_prefix', 't.title'])
            ->map(fn (object $e): array => [
                'key' => 'event-'.$e->id,
                'type' => $e->type,
                'text' => ($e->actor_name ?? 'Someone').' '.($labels[$e->type] ?? 'updated').' “'.$e->title.'”',
                'at' => CarbonImmutable::parse($e->created_at)->toISOString(),
                'to' => $e->ticket_prefix && $e->number ? "/tickets/{$e->ticket_prefix}-{$e->number}" : null,
            ]);
        $clients = User::query()->where('agency_id', $agencyId)->where('role', Role::AgencyClient->value)->latest('id')->limit($limit)->get(['id', 'name', 'created_at'])
            ->map(fn (User $c): array => ['key' => 'client-'.$c->id, 'type' => 'client_added', 'text' => $c->name.' was added as a client', 'at' => $c->created_at->toISOString(), 'to' => "/agency/clients?client={$c->id}"]);

        return $events->concat($clients)->sortByDesc('at')->take($limit)->values()->all();
    }
}
