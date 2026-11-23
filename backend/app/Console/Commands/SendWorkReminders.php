<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\WorkReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Every 30 minutes: tells employees which projects and tickets start today and reminds them as deadlines come
 * (3 days, 1 day, the day itself, then once a day while overdue). Each reminder carries a dedupe key, so running the
 * command again, or every half hour, never repeats one. Uses the app's existing notifications (bell, preferences).
 */
class SendWorkReminders extends Command
{
    protected $signature = 'app:send-work-reminders';

    protected $description = 'Notify employees about work starting today and approaching or missed deadlines';

    private int $sent = 0;

    private CarbonImmutable $today;

    public function handle(): int
    {
        $this->today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $this->sent = 0;

        WorkItem::query()
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereNotNull('employee_id')
            ->with('employee')
            ->chunkById(200, function ($projects): void {
                foreach ($projects as $project) {
                    $this->remindProject($project);
                }
            });

        Ticket::query()
            ->whereIn('status', ['todo', 'in_progress'])
            ->whereHas('workItem', fn ($query) => $query->whereIn('status', ['scheduled', 'in_progress'])->whereNotNull('employee_id'))
            ->with(['workItem.employee', 'agency'])
            ->chunkById(200, function ($tickets): void {
                foreach ($tickets as $ticket) {
                    $this->remindTicket($ticket);
                }
            });

        $this->info("Reminders sent: {$this->sent}");

        return self::SUCCESS;
    }

    private function remindProject(WorkItem $project): void
    {
        $employee = $project->employee;
        $label = "Project “{$project->title}”";
        if ($project->status === 'scheduled' && $project->start_date !== null && $this->isToday($project->start_date)) {
            $this->notify($employee, 'project_start', 'Start today', "{$label} is scheduled to start today.", "project:{$project->id}:start", ['project_id' => $project->id]);
        }
        $this->deadline($employee, "project:{$project->id}", $label, $project->due_date, ['project_id' => $project->id]);
    }

    private function remindTicket(Ticket $ticket): void
    {
        $employee = $ticket->workItem?->employee;
        $label = "Ticket “{$ticket->title}”";
        $data = ['ticket_id' => $ticket->id, 'ticket_key' => $ticket->key, 'project_id' => $ticket->work_item_id, 'url_path' => $ticket->key ? "/tickets/{$ticket->key}" : '/my-work'];
        if ($ticket->status === 'todo' && $ticket->start_date !== null && $this->isToday($ticket->start_date)) {
            $this->notify($employee, 'ticket_start', 'Start today', "{$label} is scheduled to start today.", "ticket:{$ticket->id}:start", $data);
        }
        $this->deadline($employee, "ticket:{$ticket->id}", $label, $ticket->due_date, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deadline(?User $employee, string $prefix, string $label, ?\DateTimeInterface $due, array $data): void
    {
        if ($due === null) {
            return;
        }
        $days = (int) $this->today->diffInDays(CarbonImmutable::parse($due->format('Y-m-d'), config('app.timezone')), false);
        if ($days < 0) {
            $date = $this->today->toDateString();
            $late = abs($days);
            $this->notify($employee, 'deadline_overdue', 'Overdue', "{$label} is overdue by {$late} ".($late === 1 ? 'day' : 'days').'.', "{$prefix}:overdue:{$date}", $data);

            return;
        }
        $message = match ($days) {
            0 => ['deadline_today', 'Due today', "{$label} is due today."],
            1 => ['deadline_soon', 'Due tomorrow', "{$label} is due tomorrow."],
            3 => ['deadline_soon', 'Due in 3 days', "{$label} is due in 3 days."],
            default => null,
        };
        if ($message !== null) {
            $this->notify($employee, $message[0], $message[1], $message[2], "{$prefix}:due:{$days}", $data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notify(?User $employee, string $kind, string $title, string $body, string $key, array $data): void
    {
        if ($employee === null || ! $employee->is_active || $employee->role !== Role::Employee) {
            return;
        }
        $already = DatabaseNotification::query()
            ->where('notifiable_type', $employee->getMorphClass())
            ->where('notifiable_id', $employee->id)
            ->where('data->dedupe_key', $key)
            ->exists();
        if ($already) {
            return;
        }

        // A failed delivery must not stop the other reminders; it is retried on the next run.
        $delivered = rescue(function () use ($employee, $kind, $title, $body, $key, $data): bool {
            $employee->notifyNow(new WorkReminder([
                'kind' => $kind, 'dedupe_key' => $key, 'title' => $title, 'body' => $body,
                'url_path' => '/my-work', ...$data,
            ]));

            return true;
        }, false);
        if ($delivered) {
            $this->sent++;
        }
    }

    private function isToday(\DateTimeInterface $date): bool
    {
        return $date->format('Y-m-d') === $this->today->toDateString();
    }
}
