<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\Reports\AgencySummaryReport;
use App\Services\Reports\AssignmentReport;
use App\Services\Reports\EmployeeUtilizationReport;
use App\Services\Reports\OverviewReport;
use App\Services\Reports\ProjectReport;
use App\Services\Reports\ReportScope;
use Illuminate\Support\Facades\DB;

/** Administrator questions: the whole platform. */
class AdminAnswers extends AnswerSet
{
    public function questions(User $user): array
    {
        return [
            ['key' => 'admin_platform_overview', 'label' => 'How is the platform doing?', 'description' => 'Agencies, open and overdue projects, completion rate and free employees (last 30 days).', 'category' => 'Overview'],
            ['key' => 'admin_overdue_projects', 'label' => 'Which projects are overdue?', 'description' => 'Open projects past their due date, most late first.', 'category' => 'Projects'],
            ['key' => 'admin_unassigned_employees', 'label' => 'Which employees are unassigned?', 'description' => 'Active employees that no agency holds right now.', 'category' => 'People'],
            ['key' => 'admin_over_capacity', 'label' => 'Who is over capacity this week?', 'description' => 'Employees scheduled above 90% of their 36 h week.', 'category' => 'People'],
            ['key' => 'admin_top_agencies', 'label' => 'Which agencies have the most scheduled work this month?', 'description' => 'Top five agencies by scheduled hours.', 'category' => 'Agencies'],
        ];
    }

    public function answer(string $key, User $user, array $params): ?array
    {
        $scope = ReportScope::fromUser($user);

        return match ($key) {
            'admin_platform_overview' => $this->overview($scope),
            'admin_overdue_projects' => $this->overdue(),
            'admin_unassigned_employees' => $this->unassigned(),
            'admin_over_capacity' => $this->overCapacity($scope),
            'admin_top_agencies' => $this->topAgencies($scope),
            default => null,
        };
    }

    private function overview(ReportScope $scope): array
    {
        $today = $this->today();
        $o = (new OverviewReport())->run($this->filters($today->subDays(29), $today), $scope);
        $open = $o['projects']['scheduled'] + $o['projects']['in_progress'];
        $inFlight = array_sum(array_diff_key($o['tickets'], array_flip(['done', 'cancelled'])));

        return [
            'title' => 'Platform overview',
            'blocks' => [
                $this->stats('Last 30 days', [
                    ['label' => 'Active agencies', 'value' => $o['agencies']['active'] ?? 0, 'hint' => ($o['agencies']['suspended'] ?? 0).' suspended'],
                    ['label' => 'Open projects', 'value' => $open, 'hint' => $o['projects']['completed'].' completed'],
                    ['label' => 'Overdue projects', 'value' => $o['overdue_projects'], 'tone' => $o['overdue_projects'] > 0 ? 'danger' : 'success'],
                    ['label' => 'Completion rate', 'value' => $this->percent($o['completion_rate'])],
                    ['label' => 'Tickets in flight', 'value' => $inFlight],
                    ['label' => 'Available employees', 'value' => $o['employees']['available'] ?? 0, 'hint' => ($o['employees']['held'] ?? 0).' held'],
                ]),
            ],
        ];
    }

    private function overdue(): array
    {
        $rows = DB::table('work_items as wi')
            ->leftJoin('agencies as ag', 'ag.id', '=', 'wi.agency_id')
            ->leftJoin('users as em', 'em.id', '=', 'wi.employee_id')
            ->whereIn('wi.status', ProjectReport::OPEN)
            ->whereRaw('DATE(wi.due_date) < ?', [$this->today()->toDateString()])
            ->orderBy('wi.due_date')->orderBy('wi.id')
            ->limit(10)
            ->get(['wi.id', 'wi.title', 'wi.due_date', 'ag.name as agency', 'em.name as employee']);

        return [
            'title' => 'Overdue projects',
            'blocks' => [$this->listBlock('Most late first', $rows->map(fn (object $r): array => [
                'title' => $r->title,
                'subtitle' => trim(($r->agency ?? 'No agency').' · '.($r->employee ?? 'Unassigned')),
                'meta' => $this->daysLate((string) $r->due_date).' days late',
                'tone' => 'danger',
                'href' => "/admin/projects/{$r->id}",
            ])->all())],
            'empty_message' => $rows->isEmpty() ? 'No project is overdue. Every open project is within its due date.' : null,
        ];
    }

    private function unassigned(): array
    {
        $data = (new AssignmentReport())->unassigned();

        return [
            'title' => 'Unassigned employees',
            'blocks' => [
                $this->stats('Right now', [['label' => 'Unassigned', 'value' => $data['unassigned_count']]]),
                $this->listBlock('Employees', array_map(fn (array $e): array => ['title' => $e['name'], 'href' => "/admin/employees/{$e['id']}"], $data['unassigned_employees'])),
            ],
            'empty_message' => $data['unassigned_count'] === 0 ? 'Every active employee is held by an agency.' : null,
        ];
    }

    private function overCapacity(ReportScope $scope): array
    {
        [$from, $to] = $this->week();
        $report = (new EmployeeUtilizationReport())->run($this->filters($from, $to, ['sort' => 'scheduled_hours', 'direction' => 'desc']), $scope);
        $over = array_values(array_filter($report['rows'], fn (array $r): bool => ($r['utilization'] ?? 0) > 90));

        return [
            'title' => 'Over capacity this week',
            'blocks' => [$this->progress($this->weekLabel(), array_map(
                fn (array $r): array => $this->progressItem($r['name'], $r['scheduled_hours'], $r['capacity_hours'], "/admin/employees/{$r['id']}"),
                $over
            ))],
            'empty_message' => $over === [] ? 'Nobody is scheduled above 90% of their week.' : null,
        ];
    }

    private function topAgencies(ReportScope $scope): array
    {
        $today = $this->today();
        $report = (new AgencySummaryReport())->run($this->filters($today->startOfMonth(), $today, ['sort' => 'scheduled_hours', 'direction' => 'desc', 'per_page' => 5]), $scope);
        $rows = array_values(array_filter($report['rows'], fn (array $r): bool => $r['scheduled_hours'] > 0 || $r['open_projects'] > 0));

        return [
            'title' => 'Agencies by scheduled work this month',
            'blocks' => [$this->table('Top five', ['Agency', 'Scheduled', 'Open projects', 'Employees held'], array_map(
                fn (array $r): array => [$r['name'], $this->hours($r['scheduled_hours']), $r['open_projects'], $r['employees_held']],
                $rows
            ))],
            'empty_message' => $rows === [] ? 'No agency has scheduled work this month yet.' : null,
        ];
    }
}
