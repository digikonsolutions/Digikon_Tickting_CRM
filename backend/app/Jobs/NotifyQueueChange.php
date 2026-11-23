<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\QueueUpdated;
use App\Services\EmployeeQueuePlanner;
use App\Services\TicketNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an employee their queue changed (in-app). Runs on the queue worker (`php artisan queue:work`); the replan
 * itself has already happened, synchronously, inside the request.
 */
class NotifyQueueChange implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $employeeId, public string $kind, public ?int $actorId = null)
    {
        $this->afterCommit();
    }

    public function handle(TicketNotifier $notifier, EmployeeQueuePlanner $planner): void
    {
        $employee = User::query()->find($this->employeeId);
        if ($employee === null || ! $employee->is_active) {
            return;
        }
        $next = $planner->openItems($employee)->first();

        $notifier->send($employee, QueueUpdated::class, [
            'title' => $this->kind === 'reordered' ? 'Your queue was reordered' : 'Your queue was updated',
            'body' => $next === null
                ? 'Your queue is empty.'
                : 'The order of your projects changed. Up next: “'.$next->title.'”.',
            'url_path' => '/my-work',
            'actor_name' => $this->actorId === null ? null : User::query()->whereKey($this->actorId)->value('name'),
            'action_text' => 'Open my queue',
        ]);
    }
}
