<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All time-tracking rules in one place (limits live in config/time.php).
 *
 * - Only the ticket's assigned employee logs time. Admins may edit or delete (with a reason) but never log.
 * - One running timer per employee; starting another stops the first.
 * - Every manual entry and every stopped timer records one public `time_logged` event. Starting a
 *   timer records nothing.
 */
class TimeTracker
{
    public function __construct(private TicketAccess $access, private TicketEventRecorder $recorder) {}

    private function tz(): string
    {
        return (string) config('app.timezone');
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->tz());
    }

    /* ---- running timer ---------------------------------------------------------------------- */

    public function runningFor(User $user): ?TimeEntry
    {
        $cacheKey = 'running-timer:'.$user->id;
        $attributes = request()->attributes;
        if ($attributes->has($cacheKey)) {
            return $attributes->get($cacheKey);
        }

        $entry = TimeEntry::query()
            ->where('user_id', $user->id)
            ->where('source', 'timer')
            ->whereNull('ended_at')
            ->latest('id')
            ->first();
        $attributes->set($cacheKey, $entry);

        return $entry;
    }

    private function forgetRunning(int $userId): void
    {
        request()->attributes->remove('running-timer:'.$userId);
    }

    /**
     * @return array{started: TimeEntry, stopped: TimeEntry|null}
     */
    public function start(User $user, Ticket $ticket): array
    {
        $this->assertAssignedEmployee($user, $ticket);
        $workItem = $ticket->workItem;
        if (! in_array($ticket->status, config('time.timer_statuses'), true) || ! in_array($workItem->status, ['scheduled', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'ticket' => 'A timer can only run while the ticket is in progress or has changes requested.',
            ]);
        }

        return DB::transaction(function () use ($user, $ticket, $workItem): array {
            $running = TimeEntry::query()->where('user_id', $user->id)->where('source', 'timer')->whereNull('ended_at')->lockForUpdate()->first();
            if ($running && $running->ticket_id === $ticket->id) {
                throw ValidationException::withMessages(['ticket' => 'A timer is already running on this ticket.']);
            }
            $stopped = $running ? $this->finish($running, 'switched', $user) : null;

            $now = $this->now();
            $started = TimeEntry::create([
                'ticket_id' => $ticket->id,
                'work_item_id' => $workItem->id,
                'user_id' => $user->id,
                'entry_date' => $now->toDateString(),
                'started_at' => $now,
                'source' => 'timer',
            ]);
            $this->forgetRunning($user->id);

            return ['started' => $started, 'stopped' => $stopped];
        });
    }

    public function stop(User $user, string $reason = 'manual'): TimeEntry
    {
        $entry = TimeEntry::query()->where('user_id', $user->id)->where('source', 'timer')->whereNull('ended_at')->latest('id')->first();
        if (! $entry) {
            throw ValidationException::withMessages(['timer' => 'No timer is running.']);
        }

        return DB::transaction(function () use ($entry, $reason, $user): TimeEntry {
            $stopped = $this->finish($entry, $reason, $user);
            $this->forgetRunning($user->id);

            return $stopped;
        });
    }

    /** Stops every running timer on these tickets (status changes, cancellations). Returns how many. */
    public function stopForTickets(iterable $ticketIds, string $reason): int
    {
        $entries = TimeEntry::query()
            ->whereIn('ticket_id', collect($ticketIds)->all())
            ->where('source', 'timer')
            ->whereNull('ended_at')
            ->get();

        foreach ($entries as $entry) {
            $this->finish($entry, $reason, null);
            $this->forgetRunning($entry->user_id);
        }

        return $entries->count();
    }

    public function stopForUser(User $user, string $reason): void
    {
        $this->stopForTickets(
            TimeEntry::query()->where('user_id', $user->id)->where('source', 'timer')->whereNull('ended_at')->pluck('ticket_id'),
            $reason
        );
    }

    /** Closes timers that ran past the cap at exactly the cap and flags them. */
    public function stopStale(): int
    {
        $cap = (int) config('time.max_timer_hours') * 60;
        $stale = TimeEntry::query()
            ->where('source', 'timer')
            ->whereNull('ended_at')
            ->where('started_at', '<=', $this->now()->subMinutes($cap))
            ->get();

        foreach ($stale as $entry) {
            $this->finish($entry, 'stale', null, $entry->started_at->addMinutes($cap), true);
            $this->forgetRunning($entry->user_id);
        }

        return $stale->count();
    }

    private function finish(TimeEntry $entry, string $reason, ?User $actor, ?CarbonInterface $endAt = null, bool $auto = false): TimeEntry
    {
        $cap = (int) config('time.max_timer_hours') * 60;
        $end = $endAt ?? $this->now();
        $minutes = (int) min($cap, max(1, ceil($entry->started_at->diffInSeconds($end, true) / 60)));
        $entry->forceFill([
            'ended_at' => $end,
            'minutes' => $minutes,
            'entry_date' => $entry->started_at->setTimezone($this->tz())->toDateString(),
            'auto_stopped' => $auto,
            'stop_reason' => $reason,
        ])->save();

        $this->recordLogged($entry, $actor);

        return $entry;
    }

    private function recordLogged(TimeEntry $entry, ?User $actor): void
    {
        $this->recorder->record($entry->ticket()->first(), 'time_logged', [
            'entry_id' => $entry->id,
            'minutes' => $entry->minutes,
            'entry_date' => $entry->entry_date->toDateString(),
            'source' => $entry->source,
            'stop_reason' => $entry->stop_reason,
        ], $actor);
    }

    /* ---- manual entries --------------------------------------------------------------------- */

    public function logManual(User $user, Ticket $ticket, string $date, int $minutes, ?string $note): TimeEntry
    {
        $this->assertAssignedEmployee($user, $ticket);
        if ($ticket->status === 'cancelled') {
            throw ValidationException::withMessages(['ticket' => 'Time cannot be logged on a cancelled ticket.']);
        }
        $this->assertEntryRules($user, $date, $minutes, null);

        return DB::transaction(function () use ($user, $ticket, $date, $minutes, $note): TimeEntry {
            $entry = TimeEntry::create([
                'ticket_id' => $ticket->id,
                'work_item_id' => $ticket->work_item_id,
                'user_id' => $user->id,
                'entry_date' => $date,
                'minutes' => $minutes,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'source' => 'manual',
            ]);
            $this->recordLogged($entry, $user);

            return $entry;
        });
    }

    /**
     * @param array{entry_date?: string, minutes?: int, note?: string|null} $data
     */
    public function update(TimeEntry $entry, User $actor, array $data, ?string $reason): TimeEntry
    {
        $this->assertCanModify($entry, $actor, $reason);

        $date = $data['entry_date'] ?? $entry->entry_date->toDateString();
        $minutes = $data['minutes'] ?? $entry->minutes;
        $this->assertEntryRules($entry->user, $date, $minutes, $entry->id, $actor->role === Role::Admin);

        $entry->fill([
            'entry_date' => $date,
            'minutes' => $minutes,
            'note' => array_key_exists('note', $data) ? ($data['note'] !== null && trim($data['note']) !== '' ? trim($data['note']) : null) : $entry->note,
            'modified_reason' => $actor->role === Role::Admin ? $reason : $entry->modified_reason,
        ])->save();

        return $entry;
    }

    public function delete(TimeEntry $entry, User $actor, ?string $reason): void
    {
        $this->assertCanModify($entry, $actor, $reason);
        DB::transaction(function () use ($entry, $actor, $reason): void {
            if ($actor->role === Role::Admin) {
                $entry->forceFill(['modified_reason' => $reason])->save();
            }
            $entry->delete();
        });
    }

    public function canModify(TimeEntry $entry, ?User $viewer): bool
    {
        if (! $viewer || $entry->isRunning()) {
            return false;
        }
        if ($viewer->role === Role::Admin) {
            return true;
        }

        return $viewer->role === Role::Employee
            && $entry->user_id === $viewer->id
            && $entry->created_at->greaterThanOrEqualTo($this->now()->subHours((int) config('time.edit_window_hours')));
    }

    private function assertCanModify(TimeEntry $entry, User $actor, ?string $reason): void
    {
        if ($entry->isRunning()) {
            throw ValidationException::withMessages(['entry' => 'Stop the timer before changing this entry.']);
        }
        if ($actor->role === Role::Admin) {
            if ($reason === null || strlen(trim($reason)) < 3) {
                throw ValidationException::withMessages(['reason' => 'A reason is required when an administrator changes time entries.']);
            }

            return;
        }
        abort_unless($actor->role === Role::Employee && $entry->user_id === $actor->id, 404);
        abort_unless($this->canModify($entry, $actor), 403, 'Entries can only be changed for '.config('time.edit_window_hours').' hours; ask an administrator.');
    }

    private function assertEntryRules(User $user, string $date, int $minutes, ?int $ignoreId, bool $isAdmin = false): void
    {
        $min = (int) config('time.min_entry_minutes');
        $max = (int) config('time.max_entry_minutes');
        if ($minutes < $min || $minutes > $max) {
            throw ValidationException::withMessages(['minutes' => "An entry must be between {$min} minute and ".($max / 60).' hours.']);
        }

        $day = CarbonImmutable::parse($date, $this->tz())->startOfDay();
        $today = $this->now()->startOfDay();
        $daysBack = (int) config('time.max_days_back');
        if ($day->greaterThan($today)) {
            throw ValidationException::withMessages(['entry_date' => 'Time cannot be logged for a future date.']);
        }
        if (! $isAdmin && $day->lessThan($today->subDays($daysBack))) {
            throw ValidationException::withMessages(['entry_date' => "Time can only be logged for the last {$daysBack} days."]);
        }

        $dailyCap = (int) config('time.max_daily_hours') * 60;
        $already = (int) TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('entry_date', $day->toDateString())
            ->whereNotNull('minutes')
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->sum('minutes');
        if ($already + $minutes > $dailyCap) {
            throw ValidationException::withMessages([
                'minutes' => 'That would put more than '.config('time.max_daily_hours').' hours on '.$day->toDateString().'.',
            ]);
        }
    }

    private function assertAssignedEmployee(User $user, Ticket $ticket): void
    {
        abort_unless($this->access->isAssignedEmployee($user, $ticket), 403, 'Only the specialist assigned to this ticket can log time.');
    }

    /* ---- presentation ----------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    public function payload(TimeEntry $entry, ?User $viewer = null): array
    {
        return [
            'id' => $entry->id,
            'ticket_id' => $entry->ticket_id,
            'user' => ['id' => $entry->user_id, 'name' => $entry->relationLoaded('user') ? $entry->user?->name : null],
            'entry_date' => $entry->entry_date->toDateString(),
            'minutes' => $entry->minutes,
            'hours' => $entry->minutes === null ? null : round($entry->minutes / 60, 2),
            'started_at' => $entry->started_at?->toISOString(),
            'ended_at' => $entry->ended_at?->toISOString(),
            'note' => $entry->note,
            'source' => $entry->source,
            'running' => $entry->isRunning(),
            'auto_stopped' => $entry->auto_stopped,
            'stop_reason' => $entry->stop_reason,
            'can_modify' => $this->canModify($entry, $viewer),
            'created_at' => $entry->created_at?->toISOString(),
        ];
    }

    public static function hours(int|float|string|null $minutes): float
    {
        return round(((float) $minutes) / 60, 2);
    }
}
