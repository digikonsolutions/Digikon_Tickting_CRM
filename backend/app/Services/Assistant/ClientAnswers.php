<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\Reports\EmployeeUtilizationReport;
use App\Services\Reports\ProjectReport;
use App\Services\Reports\ReportScope;
use Illuminate\Support\Facades\DB;

/** Agency client questions: only the client's own projects and the specialists they hold. */
class ClientAnswers extends AnswerSet
{
    public function questions(User $user): array
    {
        return [
            ['key' => 'client_my_projects', 'label' => 'What is the status of my projects?', 'description' => 'Your open projects with ticket progress and due dates.', 'category' => 'My projects'],
            ['key' => 'client_needs_my_input', 'label' => 'Does anything need my input?', 'description' => 'Tickets paused until you answer.', 'category' => 'My projects'],
            ['key' => 'client_upcoming_deadlines', 'label' => 'What is due in the next two weeks?', 'description' => 'Your open projects due within 14 days.', 'category' => 'My projects'],
            ['key' => 'client_my_specialists', 'label' => 'How busy are my specialists this week?', 'description' => 'Hours scheduled on your projects this week.', 'category' => 'Specialists'],
        ];
    }

    public function answer(string $key, User $user, array $params): ?array
    {
        return match ($key) {
            'client_my_projects' => $this->projects($user),
            'client_needs_my_input' => $this->needsInput($user),
            'client_upcoming_deadlines' => $this->deadlines($user),
            'client_my_specialists' => $this->specialists($user),
            default => null,
        };
    }

    private function openProjects(User $user)
    {
        return DB::table('work_items as wi')
            ->leftJoin('users as em', 'em.id', '=', 'wi.employee_id')
            ->where('wi.client_id', $user->id)
            ->whereIn('wi.status', ProjectReport::OPEN)
            ->selectRaw(
                'wi.id AS id, wi.title AS title, wi.status AS status, wi.due_date AS due_date, em.name AS employee, '
                ."(SELECT COUNT(*) FROM tickets t WHERE t.work_item_id = wi.id AND t.status = 'done') AS done, "
                ."(SELECT COUNT(*) FROM tickets t WHERE t.work_item_id = wi.id AND t.status <> 'cancelled') AS total"
            )
            ->orderBy('wi.due_date')->orderBy('wi.id');
    }

    private function projects(User $user): array
    {
        $rows = $this->openProjects($user)->limit(20)->get();
        $today = $this->today()->toDateString();

        return [
            'title' => 'Your open projects',
            'blocks' => [$this->listBlock('By due date', $rows->map(fn (object $r): array => [
                'title' => $r->title,
                'subtitle' => ($r->status === 'in_progress' ? 'In progress' : 'Scheduled').' · '.($r->employee ?? 'No specialist yet'),
                'meta' => "{$r->done} of {$r->total} tickets done · due ".$this->date((string) $r->due_date),
                'tone' => substr((string) $r->due_date, 0, 10) < $today ? 'danger' : null,
                'href' => "/agency/projects/{$r->id}",
            ])->all())],
            'empty_message' => $rows->isEmpty() ? 'You have no open projects. Start one from Agency projects.' : null,
        ];
    }

    private function needsInput(User $user): array
    {
        $rows = DB::table('tickets as t')
            ->join('work_items as wi', 'wi.id', '=', 't.work_item_id')
            ->leftJoin('agencies as ag', 'ag.id', '=', 't.agency_id')
            ->where('wi.client_id', $user->id)
            ->where('t.status', 'waiting_on_client')
            ->whereIn('wi.status', \App\Services\Reports\ProjectReport::OPEN)
            ->orderBy('t.updated_at')
            ->limit(10)
            ->get(['t.title', 't.number', 't.updated_at', 'ag.ticket_prefix', 'wi.title as project']);

        return [
            'title' => 'Waiting for your input',
            'blocks' => [$this->listBlock('Oldest first', $rows->map(function (object $r): array {
                $key = $r->ticket_prefix && $r->number ? "{$r->ticket_prefix}-{$r->number}" : null;

                return [
                    'title' => ($key ? "{$key} · " : '').$r->title,
                    'subtitle' => $r->project,
                    'meta' => 'Waiting since '.$this->date((string) $r->updated_at),
                    'tone' => 'warning',
                    'href' => $key ? "/tickets/{$key}" : null,
                ];
            })->all())],
            'empty_message' => $rows->isEmpty() ? 'Nothing is waiting on you right now.' : null,
        ];
    }

    private function deadlines(User $user): array
    {
        $today = $this->today();
        $rows = $this->openProjects($user)
            ->whereRaw('DATE(wi.due_date) >= ? AND DATE(wi.due_date) <= ?', [$today->toDateString(), $today->addDays(14)->toDateString()])
            ->get();

        return [
            'title' => 'Due in the next two weeks',
            'blocks' => [$this->table('Soonest first', ['Project', 'Due', 'Tickets done'], $rows->map(fn (object $r): array => [
                $r->title, $this->date((string) $r->due_date), "{$r->done} / {$r->total}",
            ])->all())],
            'empty_message' => $rows->isEmpty() ? 'None of your projects is due in the next 14 days.' : null,
        ];
    }

    private function specialists(User $user): array
    {
        [$from, $to] = $this->week();
        $rows = (new EmployeeUtilizationReport())->run($this->filters($from, $to, ['sort' => 'scheduled_hours', 'direction' => 'desc']), ReportScope::fromUser($user))['rows'];

        return [
            'title' => 'Your specialists this week',
            'blocks' => [$this->progress($this->weekLabel().' · hours scheduled on your projects, not hours worked', array_map(
                fn (array $r): array => $this->progressItem($r['name'], $r['scheduled_hours'], $r['capacity_hours']),
                $rows
            ))],
            'empty_message' => $rows === [] ? 'No specialist is working on your projects right now.' : null,
        ];
    }
}
