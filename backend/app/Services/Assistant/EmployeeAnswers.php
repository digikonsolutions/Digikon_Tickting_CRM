<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\Reports\Capacity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Specialist (employee) questions: only their own schedule, tickets and logged time. */
class EmployeeAnswers extends AnswerSet
{
    public function questions(User $user): array
    {
        return [
            ['key' => 'employee_my_week', 'label' => 'What does my week look like?', 'description' => 'Hours scheduled for you each day this week, against your 36 h capacity.', 'category' => 'My work'],
            ['key' => 'employee_open_tickets', 'label' => 'Which tickets are open for me?', 'description' => 'Tickets on your projects that are not done yet.', 'category' => 'My work'],
            ['key' => 'employee_logged_time', 'label' => 'How much time have I logged this week?', 'description' => 'Logged time compared with what was scheduled.', 'category' => 'Time'],
        ];
    }

    public function answer(string $key, User $user, array $params): ?array
    {
        return match ($key) {
            'employee_my_week' => $this->myWeek($user),
            'employee_open_tickets' => $this->tickets($user),
            'employee_logged_time' => $this->logged($user),
            default => null,
        };
    }

    /** @return array<string, float> date => scheduled hours this week */
    private function scheduledByDay(User $user): array
    {
        [$from, $to] = $this->week();

        return DB::table('work_item_allocations as a')
            ->join('work_items as wi', 'wi.id', '=', 'a.work_item_id')
            ->where('a.employee_id', $user->id)
            ->where('wi.status', '<>', 'cancelled')
            ->whereRaw('DATE(a.work_date) >= ? AND DATE(a.work_date) <= ?', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('DATE(a.work_date) AS day, SUM(a.hours) AS hours')
            ->groupByRaw('DATE(a.work_date)')
            ->pluck('hours', 'day')
            ->map(fn ($h): float => (float) $h)
            ->all();
    }

    private function myWeek(User $user): array
    {
        [$from, $to] = $this->week();
        $byDay = $this->scheduledByDay($user);
        $total = array_sum($byDay);
        $rows = [];
        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $rows[] = [$day->format('D j M'), $this->hours($byDay[$day->toDateString()] ?? 0)];
        }

        return [
            'title' => 'Your week',
            'blocks' => [
                $this->progress($this->weekLabel(), [$this->progressItem('Scheduled for you', $total, Capacity::hours($from, $to))]),
                $this->table('By day', ['Day', 'Scheduled'], $rows),
            ],
            'empty_message' => $total == 0 ? 'Nothing is scheduled for you this week.' : null,
        ];
    }

    private function tickets(User $user): array
    {
        $rows = DB::table('tickets as t')
            ->join('work_items as wi', 'wi.id', '=', 't.work_item_id')
            ->leftJoin('agencies as ag', 'ag.id', '=', 't.agency_id')
            ->where('wi.employee_id', $user->id)
            ->whereNotIn('t.status', ['done', 'cancelled'])
            ->whereIn('wi.status', \App\Services\Reports\ProjectReport::OPEN)
            ->orderByRaw('CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date')
            ->limit(15)
            ->get(['t.title', 't.number', 't.status', 't.due_date', 'ag.ticket_prefix', 'wi.title as project']);

        return [
            'title' => 'Your open tickets',
            'blocks' => [$this->listBlock('Earliest due first', $rows->map(function (object $r): array {
                $key = $r->ticket_prefix && $r->number ? "{$r->ticket_prefix}-{$r->number}" : null;

                return [
                    'title' => ($key ? "{$key} · " : '').$r->title,
                    'subtitle' => $r->project.' · '.str_replace('_', ' ', $r->status),
                    'meta' => $r->due_date ? 'Due '.$this->date((string) $r->due_date) : 'No due date',
                    'href' => $key ? "/tickets/{$key}" : null,
                ];
            })->all())],
            'empty_message' => $rows->isEmpty() ? 'You have no open tickets.' : null,
        ];
    }

    private function logged(User $user): array
    {
        [$from, $to] = $this->week();
        $minutes = (int) DB::table('time_entries')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->whereNotNull('minutes')
            ->whereRaw('DATE(entry_date) >= ? AND DATE(entry_date) <= ?', [$from->toDateString(), $to->toDateString()])
            ->sum('minutes');
        $logged = round($minutes / 60, 2);
        $scheduled = array_sum($this->scheduledByDay($user));

        return [
            'title' => 'Time logged this week',
            'blocks' => [$this->stats($this->weekLabel(), [
                ['label' => 'Logged', 'value' => $this->hours($logged)],
                ['label' => 'Scheduled', 'value' => $this->hours($scheduled)],
                ['label' => 'Logged vs scheduled', 'value' => $this->percent($scheduled > 0 ? round($logged / $scheduled * 100, 1) : null)],
            ])],
            'empty_message' => $minutes === 0 ? 'You have not logged any time this week. Start a timer from a ticket.' : null,
        ];
    }
}
