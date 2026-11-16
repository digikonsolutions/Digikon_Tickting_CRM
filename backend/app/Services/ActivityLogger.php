<?php

namespace App\Services;

use App\Enums\ActivityEvent;
use App\Enums\Role;
use App\Models\EmployeeActivityLog;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of employee activity. Event types and timestamps always come from the server;
 * the browser may only say "I am idle" on a heartbeat. Non-employees are never recorded.
 */
class ActivityLogger
{
    public function __construct(private readonly MonitoringPolicy $policy)
    {
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    /** Only employees who accepted the current notice are ever recorded. */
    private function tracks(?User $user): bool
    {
        if ($user === null || $user->role !== Role::Employee) {
            return false;
        }

        return $this->policy->hasConsented($user);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function log(User $user, ActivityEvent $event, ?int $workItemId = null, ?int $ticketId = null, ?array $metadata = null, ?CarbonImmutable $at = null): void
    {
        // Collection limit: an event whose category is not in the disclosure is dropped, never stored.
        if (! $this->policy->allows($event->category())) {
            return;
        }

        $request = request();
        $withConnection = $this->policy->allows('connection');
        EmployeeActivityLog::create([
            'employee_id' => $user->id,
            'event_type' => $event->value,
            'work_item_id' => $workItemId,
            'ticket_id' => $ticketId,
            'metadata' => $metadata,
            'occurred_at' => $at ?? $this->now(),
            'ip_address' => $withConnection ? $request->ip() : null,
            'user_agent' => $withConnection && $request->userAgent() ? mb_substr($request->userAgent(), 0, 255) : null,
        ]);
    }

    /* ---- sessions --------------------------------------------------------------------------- */

    public function login(User $user): void
    {
        if (! $this->tracks($user)) {
            return;
        }
        DB::transaction(function () use ($user): void {
            $this->closeOpenSessions($user, 'superseded');
            $this->log($user, ActivityEvent::Login);
            if ($this->policy->allows('work_sessions')) {
                $this->startSession($user);
            }
        });
    }

    public function logout(User $user): void
    {
        if (! $this->tracks($user)) {
            return;
        }
        DB::transaction(function () use ($user): void {
            $this->closeOpenSessions($user, 'logout');
            $this->log($user, ActivityEvent::Logout);
        });
    }

    private function startSession(User $user): WorkSession
    {
        $now = $this->now();
        $session = WorkSession::create(['employee_id' => $user->id, 'started_at' => $now, 'last_heartbeat_at' => $now]);
        $this->log($user, ActivityEvent::SessionStart, null, null, ['session_id' => $session->id], $now);

        return $session;
    }

    private function closeOpenSessions(User $user, string $reason): void
    {
        WorkSession::query()->where('employee_id', $user->id)->whereNull('ended_at')->lockForUpdate()->get()
            ->each(fn (WorkSession $session) => $this->endSession($user, $session, $reason, $reason === 'logout' ? $this->now() : $session->last_heartbeat_at));
    }

    private function endSession(User $user, WorkSession $session, string $reason, CarbonImmutable|\Carbon\CarbonInterface $at): void
    {
        $session->forceFill(['ended_at' => $at, 'ended_reason' => $reason, 'is_idle' => false])->save();
        $this->log($user, ActivityEvent::SessionEnd, null, null, [
            'session_id' => $session->id,
            'reason' => $reason,
            'active_seconds' => $session->active_seconds,
            'idle_seconds' => $session->idle_seconds,
        ], CarbonImmutable::instance($at));
    }

    /**
     * Counts time since the previous heartbeat as active or idle. The elapsed time is measured by the
     * server and capped, so a client cannot claim hours it did not spend.
     *
     * @return array{active_seconds: int, idle_seconds: int}
     */
    public function heartbeat(User $user, bool $idle): array
    {
        if (! $this->tracks($user) || ! $this->policy->allows('work_sessions')) {
            return ['active_seconds' => 0, 'idle_seconds' => 0];
        }

        return DB::transaction(function () use ($user, $idle): array {
            $now = $this->now();
            $timeout = (int) config('activity.session_timeout_minutes') * 60;
            $session = WorkSession::query()->where('employee_id', $user->id)->whereNull('ended_at')->lockForUpdate()->latest('id')->first();

            if ($session && $session->last_heartbeat_at->diffInSeconds($now, true) > $timeout) {
                $this->endSession($user, $session, 'timeout', $session->last_heartbeat_at);
                $session = null;
            }
            if (! $session) {
                $session = $this->startSession($user);
            }

            $gap = (int) min(
                (int) config('activity.max_heartbeat_gap_seconds'),
                max(0, $session->last_heartbeat_at->diffInSeconds($now, true))
            );

            if ($idle) {
                if (! $session->is_idle) {
                    $this->log($user, ActivityEvent::IdleDetected, null, null, [
                        'session_id' => $session->id,
                        'idle_after_minutes' => (int) config('activity.idle_after_minutes'),
                    ]);
                }
                $session->idle_seconds += $gap;
            } else {
                $session->active_seconds += $gap;
            }
            $session->is_idle = $idle;
            $session->last_heartbeat_at = $now;
            $session->save();

            return ['active_seconds' => $session->active_seconds, 'idle_seconds' => $session->idle_seconds];
        });
    }

    /** Closes sessions whose heartbeats stopped, at their last heartbeat. Returns how many. */
    public function closeStaleSessions(): int
    {
        $cutoff = $this->now()->subMinutes((int) config('activity.session_timeout_minutes'));
        $stale = WorkSession::query()->whereNull('ended_at')->where('last_heartbeat_at', '<', $cutoff)->with('employee')->get();

        foreach ($stale as $session) {
            if ($session->employee) {
                $this->endSession($session->employee, $session, 'timeout', $session->last_heartbeat_at);
            }
        }

        return $stale->count();
    }

    public function prune(): int
    {
        $before = $this->now()->subDays((int) config('activity.retention_days'));
        $logs = EmployeeActivityLog::query()->where('occurred_at', '<', $before)->delete();
        WorkSession::query()->whereNotNull('ended_at')->where('ended_at', '<', $before)->delete();

        return $logs;
    }

    /* ---- work events ------------------------------------------------------------------------ */

    public function ticketStatusChanged(?User $actor, Ticket $ticket, string $from, string $to): void
    {
        if ($this->tracks($actor)) {
            $this->log($actor, ActivityEvent::TicketStatusChanged, $ticket->work_item_id, $ticket->id, [
                'ticket_key' => $ticket->key, 'from' => $from, 'to' => $to,
            ]);
        }
    }

    public function ticketCommented(?User $actor, Ticket $ticket, bool $internal): void
    {
        if ($this->tracks($actor)) {
            // Only that a comment was written, never its text.
            $this->log($actor, ActivityEvent::TicketCommentAdded, $ticket->work_item_id, $ticket->id, [
                'ticket_key' => $ticket->key, 'internal' => $internal,
            ]);
        }
    }

    public function projectStatusChanged(?User $actor, WorkItem $workItem, string $from, string $to, ?Ticket $cause = null): void
    {
        if ($this->tracks($actor)) {
            $this->log($actor, ActivityEvent::ProjectStatusChanged, $workItem->id, $cause?->id, [
                'project_title' => $workItem->title, 'from' => $from, 'to' => $to,
            ]);
        }
    }
}
