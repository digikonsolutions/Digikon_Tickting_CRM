<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\Reports\EmployeeUtilizationReport;
use App\Services\Reports\OverviewReport;
use App\Services\Reports\ProjectReport;
use App\Services\Reports\ReportScope;
use Illuminate\Support\Facades\DB;

/** Agency owner questions: limited to the owner's own agency. */
class OwnerAnswers extends AnswerSet
{
    public function questions(User $user): array
    {
        return [
            ['key' => 'owner_agency_overview', 'label' => 'How is my agency doing?', 'description' => 'Open and overdue projects, completion rate and specialists held (last 30 days).', 'category' => 'My agency'],
            [
                'key' => 'owner_employee_capacity',
                'label' => 'How busy are my specialists this week?',
                'description' => 'Scheduled hours against the 36 h week, for one specialist or all of them.',
                'category' => 'Specialists',
                'params' => [[
                    'name' => 'employee_id',
                    'label' => 'Specialist',
                    'type' => 'select',
                    'required' => false,
                    'placeholder' => 'All my specialists',
                    'options' => $this->heldEmployees($user),
                ]],
            ],
            ['key' => 'owner_overdue_projects', 'label' => 'Which of our projects are overdue or at risk?', 'description' => 'Open projects past due, or due within two days with open tickets.', 'category' => 'Projects'],
            ['key' => 'owner_waiting_on_client', 'label' => 'Which tickets are waiting on a client?', 'description' => 'Tickets paused until a client answers, longest wait first.', 'category' => 'Tickets'],
            ['key' => 'owner_client_workload', 'label' => 'How much work does each client have?', 'description' => 'Open projects and scheduled hours this month, per client.', 'category' => 'Clients'],
        ];
    }

    /** @return list<array{value: int, label: string}> employees with an active assignment in this agency */
    public static function heldEmployees(User $user): array
    {
        return DB::table('employee_assignments as ea')
            ->join('users as u', 'u.id', '=', 'ea.employee_id')
            ->where('ea.agency_id', $user->agency_id)
            ->where('ea.status', 'active')
            ->orderBy('u.name')
            ->get(['u.id', 'u.name'])
            ->map(fn (object $r): array => ['value' => (int) $r->id, 'label' => $r->name])
            ->all();
    }

    public function answer(string $key, User $user, array $params): ?array
    {
        $scope = ReportScope::fromUser($user);

        return match ($key) {
            'owner_agency_overview' => $this->overview($scope, $user),
            'owner_employee_capacity' => $this->capacity($scope, isset($params['employee_id']) ? (int) $params['employee_id'] : null),
            'owner_overdue_projects' => $this->overdue($scope),
            'owner_waiting_on_client' => $this->waiting($user),
            'owner_client_workload' => $this->clients($user),
            default => null,
        };
    }

    private function overview(ReportScope $scope, User $user): array
    {
        $today = $this->today();
        $o = (new OverviewReport())->run($this->filters($today->subDays(29), $today), $scope);
        $open = $o['projects']['scheduled'] + $o['projects']['in_progress'];

        return [
            'title' => 'Your agency at a glance',
            'blocks' => [
                $this->stats('Last 30 days', [
                    ['label' => 'Open projects', 'value' => $open, 'hint' => $o['projects']['completed'].' completed'],
                    ['label' => 'Overdue projects', 'value' => $o['overdue_projects'], 'tone' => $o['overdue_projects'] > 0 ? 'danger' : 'success'],
                    ['label' => 'Completion rate', 'value' => $this->percent($o['completion_rate'])],
                    ['label' => 'Specialists held', 'value' => $o['employees']['held']],
                    ['label' => 'Waiting on a client', 'value' => $o['tickets']['waiting_on_client'], 'tone' => $o['tickets']['waiting_on_client'] > 0 ? 'warning' : null],
                    ['label' => 'Tickets done', 'value' => $o['tickets']['done']],
                ]),
            ],
            'empty_message' => $open + $o['projects']['completed'] === 0 ? 'No projects in the last 30 days yet. Create one from Projects.' : null,
        ];
    }

    private function capacity(ReportScope $scope, ?int $employeeId): array
    {
        [$from, $to] = $this->week();
        $extra = ['sort' => 'scheduled_hours', 'direction' => 'desc'];
        if ($employeeId !== null) {
            $extra['employee_id'] = $employeeId;
        }
        $rows = (new EmployeeUtilizationReport())->run($this->filters($from, $to, $extra), $scope)['rows'];

        return [
            'title' => $employeeId ? 'This week for '.($rows[0]['name'] ?? 'this specialist') : 'Your specialists this week',
            'blocks' => [
                $this->progress($this->weekLabel().' · hours scheduled on your projects, not hours worked', array_map(
                    fn (array $r): array => $this->progressItem($r['name'], $r['scheduled_hours'], $r['capacity_hours']),
                    $rows
                )),
            ],
            'empty_message' => $rows === [] ? 'You are not holding any specialist right now. Book one from My specialists.' : null,
        ];
    }

    private function overdue(ReportScope $scope): array
    {
        $today = $this->today();
        $rows = (new ProjectReport())->run($this->filters($today->subDays(365), $today->addDays(60), ['sort' => 'due_date']), $scope)['rows'];
        $flagged = array_values(array_filter($rows, fn (array $r): bool => $r['flag'] !== null));
        usort($flagged, fn (array $a, array $b): int => [$a['flag'] === 'overdue' ? 0 : 1, $a['due_date']] <=> [$b['flag'] === 'overdue' ? 0 : 1, $b['due_date']]);

        return [
            'title' => 'Overdue and at-risk projects',
            'blocks' => [$this->listBlock('Overdue first', array_map(fn (array $r): array => [
                'title' => $r['title'],
                'subtitle' => ($r['client'] ?? 'No client').' · '.($r['employee'] ?? 'Unassigned'),
                'meta' => $r['flag'] === 'overdue' ? $r['days_late'].' days late' : 'Due '.$this->date($r['due_date']),
                'tone' => $r['flag'] === 'overdue' ? 'danger' : 'warning',
                'href' => "/agency/projects/{$r['id']}",
            ], array_slice($flagged, 0, 10)))],
            'empty_message' => $flagged === [] ? 'Nothing is overdue or at risk. Nice work.' : null,
        ];
    }

    private function waiting(User $user): array
    {
        $rows = DB::table('tickets as t')
            ->join('work_items as wi', 'wi.id', '=', 't.work_item_id')
            ->leftJoin('agencies as ag', 'ag.id', '=', 't.agency_id')
            ->leftJoin('users as cl', 'cl.id', '=', 'wi.client_id')
            ->where('wi.agency_id', $user->agency_id)
            ->where('t.status', 'waiting_on_client')
            ->whereIn('wi.status', \App\Services\Reports\ProjectReport::OPEN)
            ->orderBy('t.updated_at')
            ->limit(10)
            ->get(['t.title', 't.number', 't.updated_at', 'ag.ticket_prefix', 'wi.title as project', 'cl.name as client']);

        return [
            'title' => 'Tickets waiting on a client',
            'blocks' => [$this->listBlock('Longest wait first', $rows->map(function (object $r): array {
                $key = $r->ticket_prefix && $r->number ? "{$r->ticket_prefix}-{$r->number}" : null;

                return [
                    'title' => ($key ? "{$key} · " : '').$r->title,
                    'subtitle' => $r->project.' · '.($r->client ?? 'No client'),
                    'meta' => 'Waiting since '.$this->date((string) $r->updated_at),
                    'tone' => 'warning',
                    'href' => $key ? "/tickets/{$key}" : null,
                ];
            })->all())],
            'empty_message' => $rows->isEmpty() ? 'No ticket is waiting on a client.' : null,
        ];
    }

    private function clients(User $user): array
    {
        $today = $this->today();
        $rows = DB::table('users as cl')
            ->where('cl.agency_id', $user->agency_id)
            ->where('cl.role', 'agency_client')
            ->selectRaw(
                "cl.name AS name, (SELECT COUNT(*) FROM work_items wi WHERE wi.client_id = cl.id AND wi.status IN ('scheduled', 'in_progress')) AS open_projects, "
                ."COALESCE((SELECT SUM(a.hours) FROM work_item_allocations a JOIN work_items wi ON wi.id = a.work_item_id WHERE wi.client_id = cl.id AND wi.status <> 'cancelled' AND DATE(a.work_date) >= ? AND DATE(a.work_date) <= ?), 0) AS hours",
                [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()]
            )
            ->orderByDesc('hours')->orderBy('cl.name')
            ->get();

        return [
            'title' => 'Workload by client',
            'blocks' => [$this->table('This month', ['Client', 'Open projects', 'Scheduled'], $rows->map(fn (object $r): array => [$r->name, (int) $r->open_projects, $this->hours((float) $r->hours)])->all())],
            'empty_message' => $rows->isEmpty() ? 'Your agency has no client accounts yet. Add one from Agency accounts.' : null,
        ];
    }
}
