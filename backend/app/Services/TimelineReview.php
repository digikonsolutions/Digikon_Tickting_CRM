<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\Attachments\AttachmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The timeline review state machine.
 *
 *   created -> proposed -> in_review -> confirmed
 *                 ^            |
 *                 |            v
 *                 +---- changes_requested        (an admin asks; the owner replies and it is proposed again)
 *   confirmed -> (an edit to hours, dates or days) -> proposed
 *
 * A project and each of its tickets carry their own timeline_status. Confirming a project also confirms every
 * ticket still waiting in it; a ticket added to an already confirmed project is proposed on its own and never
 * disturbs the confirmed work. Allocations are "provisional" exactly while their parent is not confirmed; they
 * still count as booked, so the employee cannot be double-booked while a review is open.
 */
class TimelineReview
{
    public const PENDING = ['proposed', 'in_review', 'changes_requested'];

    public function __construct(private TimelineNotifier $notifier, private TicketPlanner $planner, private AttachmentService $files) {}

    public static function required(): bool
    {
        return (bool) config('scheduling.require_timeline_review', true);
    }

    /** Whether the employee may start work on this ticket: its project and the ticket itself must be confirmed. */
    public static function allowsWork(WorkItem $project, Ticket $ticket): bool
    {
        return ! self::required() || ($project->timeline_status === 'confirmed' && $ticket->timeline_status === 'confirmed');
    }

    /** @param  array<string, mixed>  $extra  keys: before, after, note, subject ('project'|'ticket'), subject_id */
    public function log(WorkItem $project, string $type, ?User $actor, array $extra = []): TimelineEvent
    {
        return TimelineEvent::create([
            'work_item_id' => $project->id,
            'subject_type' => $extra['subject'] ?? 'project',
            'subject_id' => $extra['subject_id'] ?? $project->id,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->role->value,
            'type' => $type,
            'before' => $extra['before'] ?? null,
            'after' => $extra['after'] ?? null,
            'note' => $extra['note'] ?? null,
        ]);
    }

    /** @return list<array{date: string, hours: float}> */
    public function days(WorkItem $project): array
    {
        return $project->allocations()->orderBy('work_date')->get()
            ->map(fn ($a): array => ['date' => CarbonImmutable::parse($a->work_date)->toDateString(), 'hours' => round((float) $a->hours, 2)])->all();
    }

    private function reviewDue(): \Illuminate\Support\Carbon
    {
        return now()->addHours((int) config('scheduling.review_overdue_hours', 24));
    }

    /** A new project: provisional schedule, waiting for a review. Call after the project and its allocations exist. */
    public function propose(WorkItem $project, User $creator): void
    {
        if (! self::required()) {
            return;
        }
        $project->forceFill([
            'timeline_status' => 'proposed', 'timeline_proposed_at' => now(), 'timeline_review_due_at' => $this->reviewDue(),
            'timeline_confirmed_at' => null, 'timeline_confirmed_by' => null,
        ])->save();
        $this->log($project, 'proposed', $creator, ['after' => ['days' => $this->days($project), 'start' => $project->start_date?->toDateString(), 'due' => $project->due_date?->toDateString()]]);
        $this->notifier->proposed($project->fresh(['agency', 'client', 'employee']), $creator);
    }

    /** A new ticket: proposed on its own. The project keeps its status (a confirmed project stays confirmed). */
    public function proposeTicket(Ticket $ticket, WorkItem $project, User $creator): void
    {
        if (! self::required()) {
            return;
        }
        $ticket->forceFill(['timeline_status' => 'proposed', 'timeline_proposed_at' => now(), 'timeline_confirmed_at' => null, 'timeline_confirmed_by' => null])->save();
        if ($project->timeline_status === 'confirmed') {
            $project->forceFill(['timeline_review_due_at' => $this->reviewDue()])->save();
        }
        $this->log($project, 'proposed', $creator, ['subject' => 'ticket', 'subject_id' => $ticket->id, 'after' => ['ticket' => $ticket->title, 'start' => $ticket->start_date?->toDateString(), 'due' => $ticket->due_date?->toDateString()]]);
        $this->notifier->proposed($project->fresh(['agency', 'client', 'employee']), $creator, $ticket->title);
    }

    /** An edit to a confirmed project's hours, dates or employee puts the project back to proposed. */
    public function reopen(WorkItem $project, User $actor, string $why): void
    {
        if (! self::required() || $project->timeline_status !== 'confirmed') {
            return;
        }
        $project->forceFill(['timeline_status' => 'proposed', 'timeline_proposed_at' => now(), 'timeline_review_due_at' => $this->reviewDue(), 'timeline_confirmed_at' => null, 'timeline_confirmed_by' => null])->save();
        $this->log($project, 'reopened', $actor, ['note' => $why, 'after' => ['days' => $this->days($project)]]);
        $this->notifier->proposed($project->fresh(['agency', 'client', 'employee']), $actor);
    }

    private function pendingTickets(WorkItem $project)
    {
        return $project->tickets()->whereIn('timeline_status', self::PENDING)->get();
    }

    public function isPending(WorkItem $project): bool
    {
        return in_array($project->timeline_status, self::PENDING, true) || $project->tickets()->whereIn('timeline_status', self::PENDING)->exists();
    }

    /** The first admin to open a review becomes its project manager; the status moves to in_review. */
    public function startReview(WorkItem $project, User $admin): WorkItem
    {
        abort_unless($admin->role === Role::Admin, 403);

        return DB::transaction(function () use ($project, $admin): WorkItem {
            $locked = WorkItem::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless($this->isPending($locked), 422, 'This timeline is already confirmed.');
            $changed = false;
            if ($locked->project_manager_id === null) {
                $locked->forceFill(['project_manager_id' => $admin->id])->save();
                $changed = true;
            }
            if ($locked->timeline_status === 'proposed') {
                $locked->forceFill(['timeline_status' => 'in_review'])->save();
                $changed = true;
            }
            $locked->tickets()->where('timeline_status', 'proposed')->update(['timeline_status' => 'in_review']);
            if ($changed) {
                $this->log($locked, 'review_started', $admin, ['after' => ['project_manager' => $locked->project_manager_id]]);
            }

            return $locked;
        });
    }

    public function canEdit(User $user, WorkItem $project): bool
    {
        if ($user->role === Role::Admin) {
            return true;
        }

        return $user->role === Role::Employee
            && $project->employee_id === $user->id
            && $project->assignment()->where('status', 'active')->exists();
    }

    /**
     * Replace the project's day-by-day schedule. `$days` is the FULL desired list. Every rule is checked on the
     * server, and errors come back per day (`days.N.hours`, `days.N.date`) plus `days` for totals and weeks.
     *
     * @param  list<array{date?: mixed, hours?: mixed}>  $days
     */
    public function editDays(WorkItem $project, User $actor, array $days, ?string $dueDate, ?string $note): WorkItem
    {
        abort_unless($this->canEdit($actor, $project), 404);

        return DB::transaction(function () use ($project, $actor, $days, $dueDate, $note): WorkItem {
            $locked = WorkItem::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless(in_array($locked->status, ['scheduled', 'in_progress'], true), 422, 'Only an open project has a timeline to edit.');
            abort_if($locked->tickets()->whereNotIn('status', ['todo', 'cancelled'])->exists(), 422, 'Work has already started on this project, so its days can no longer be rearranged here.');
            $employee = User::query()->findOrFail($locked->employee_id);

            $due = $dueDate ?? $locked->due_date->toDateString();
            $before = ['days' => $this->days($locked), 'start' => $locked->start_date->toDateString(), 'due' => $locked->due_date->toDateString()];
            $rows = $this->validatedDays($locked, $employee, $days, $due);

            DB::table('work_item_allocations')->where('work_item_id', $locked->id)->delete();
            foreach ($rows as $row) {
                DB::table('work_item_allocations')->insert(['work_item_id' => $locked->id, 'employee_id' => $employee->id, 'work_date' => $row['date'], 'hours' => WorkItemScheduler::unitsToHours($row['units'])]);
            }
            $locked->forceFill(['start_date' => $rows[0]['date'], 'due_date' => $due])->save();
            $this->planner->plan($locked, null, ['type' => 'timeline_edit', 'actor' => $actor, 'actor_name' => $actor->name]);

            $after = ['days' => $this->days($locked), 'start' => $rows[0]['date'], 'due' => $due];
            $wasConfirmed = $locked->timeline_status === 'confirmed';
            if ($wasConfirmed) {
                $locked->forceFill(['timeline_status' => 'proposed', 'timeline_proposed_at' => now(), 'timeline_review_due_at' => $this->reviewDue(), 'timeline_confirmed_at' => null, 'timeline_confirmed_by' => null])->save();
                $this->log($locked, 'reopened', $actor, ['note' => 'Dates were edited after confirmation.', 'before' => $before, 'after' => $after]);
            }
            $this->log($locked, 'edited', $actor, ['before' => $before, 'after' => $after, 'note' => $note]);
            if ($before['due'] !== $due) {
                $this->log($locked, 'due_date_changed', $actor, ['before' => ['due' => $before['due']], 'after' => ['due' => $due], 'note' => $note]);
            }

            return $locked;
        });
    }

    /**
     * @param  list<array{date?: mixed, hours?: mixed}>  $days
     * @return list<array{date: string, units: int}>
     */
    private function validatedDays(WorkItem $project, User $employee, array $days, string $due): array
    {
        $errors = [];
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $cap = WorkItemScheduler::dailyCapacityUnits();
        $weekCap = (int) round((float) config('scheduling.weekly_capacity_hours', 36) * 100);
        $first = $days === [] ? $today : (string) ($days[0]['date'] ?? $today);
        $others = $this->othersBooked($project, $employee);
        $rows = [];
        $seen = [];

        if ($days === []) {
            throw ValidationException::withMessages(['days' => 'Add at least one day.']);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            throw ValidationException::withMessages(['due_date' => 'Enter a valid due date.']);
        }

        foreach ($days as $i => $day) {
            $date = (string) ($day['date'] ?? '');
            $hours = $day['hours'] ?? null;
            try {
                $parsed = CarbonImmutable::createFromFormat('Y-m-d', $date, config('app.timezone'));
                if ($parsed === false || $parsed->toDateString() !== $date) {
                    throw new \InvalidArgumentException;
                }
            } catch (\Throwable) {
                $errors["days.{$i}.date"] = 'Enter a valid date.';

                continue;
            }
            if (isset($seen[$date])) {
                $errors["days.{$i}.date"] = 'This date is listed twice.';

                continue;
            }
            $seen[$date] = true;
            if (! is_numeric($hours) || (float) $hours <= 0) {
                $errors["days.{$i}.hours"] = 'Enter the hours for this day (more than 0).';

                continue;
            }
            $units = (int) round((float) $hours * 100);
            if (abs((float) $hours * 100 - $units) > 0.001) {
                $errors["days.{$i}.hours"] = 'Use at most two decimals.';

                continue;
            }
            if (! in_array($parsed->dayOfWeekIso, config('scheduling.working_days', [1, 2, 3, 4, 5, 6]), true)) {
                $errors["days.{$i}.date"] = $parsed->dayOfWeek === CarbonImmutable::SUNDAY ? 'Sundays cannot hold work.' : 'This is not a working day.';

                continue;
            }
            if (in_array($date, config('scheduling.non_working_dates', []), true)) {
                $errors["days.{$i}.date"] = 'This date is a non-working day.';

                continue;
            }
            if ($date < $today) {
                $errors["days.{$i}.date"] = 'This date is in the past.';

                continue;
            }
            if ($date > $due) {
                $errors["days.{$i}.date"] = 'This day is after the due date. Move it earlier or propose a later due date.';

                continue;
            }
            $free = max(0, $cap - ($others[$date] ?? 0));
            if ($units > $cap) {
                $errors["days.{$i}.hours"] = 'At most '.WorkItemScheduler::unitsToHours($cap).' h can be planned in one day.';

                continue;
            }
            if ($units > $free) {
                $errors["days.{$i}.hours"] = 'Only '.WorkItemScheduler::unitsToHours($free)." h are free for {$employee->name} on this day (other bookings use the rest).";

                continue;
            }
            $rows[] = ['date' => $date, 'units' => $units, 'index' => $i];
        }

        // Weekly limit: this project's hours plus the employee's other bookings in each ISO week.
        $byWeek = [];
        foreach ($rows as $row) {
            $monday = CarbonImmutable::parse($row['date'])->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $byWeek[$monday] = ($byWeek[$monday] ?? 0) + $row['units'];
        }
        foreach ($byWeek as $monday => $units) {
            $monDate = CarbonImmutable::parse($monday);
            $otherWeek = 0;
            for ($d = 0; $d < 7; $d++) {
                $otherWeek += $others[$monDate->addDays($d)->toDateString()] ?? 0;
            }
            if ($units + $otherWeek > $weekCap) {
                $errors['days'] = 'The week of '.$monDate->format('D j M').' would have '.WorkItemScheduler::unitsToHours($units + $otherWeek).' h for '.$employee->name.' (the limit is '.WorkItemScheduler::unitsToHours($weekCap).' h per week).';
            }
        }

        $total = array_sum(array_column($rows, 'units'));
        $target = (int) round((float) $project->estimated_hours * 100);
        if ($errors === [] && $total !== $target) {
            $errors['days'] = 'The days add up to '.WorkItemScheduler::unitsToHours($total).' h but the project is '.WorkItemScheduler::unitsToHours($target).' h. They must match.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        unset($first);

        return array_map(fn (array $r): array => ['date' => $r['date'], 'units' => $r['units']], $rows);
    }

    /** @return array<string, int> hundredths of an hour booked on the employee by every OTHER open project, per date */
    public function othersBooked(WorkItem $project, User $employee): array
    {
        return DB::table('work_item_allocations')->join('work_items', 'work_items.id', '=', 'work_item_allocations.work_item_id')
            ->where('work_item_allocations.employee_id', $employee->id)->where('work_items.id', '<>', $project->id)
            ->whereNotIn('work_items.status', ['completed', 'cancelled'])
            ->selectRaw('DATE(work_item_allocations.work_date) AS d, SUM(work_item_allocations.hours) AS h')->groupByRaw('DATE(work_item_allocations.work_date)')
            ->pluck('h', 'd')->map(fn ($h): int => (int) round((float) $h * 100))->all();
    }

    /** Admin or the assigned employee confirms; everything still waiting in the project is confirmed with it. */
    public function confirm(WorkItem $project, User $actor): WorkItem
    {
        abort_unless($this->canEdit($actor, $project), 404);

        $locked = DB::transaction(function () use ($project, $actor): WorkItem {
            $locked = WorkItem::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless(in_array($locked->status, ['scheduled', 'in_progress'], true), 422, 'Only an open project can be confirmed.');
            abort_unless($this->isPending($locked), 422, 'There is nothing waiting for confirmation.');
            if ($actor->role === Role::Employee) {
                abort_if($locked->timeline_status === 'changes_requested', 422, 'Changes were requested; the owner has to reply before this can be confirmed.');
            }
            $projectPending = in_array($locked->timeline_status, self::PENDING, true);
            if ($projectPending) {
                $locked->forceFill(['timeline_status' => 'confirmed', 'timeline_confirmed_at' => now(), 'timeline_confirmed_by' => $actor->id, 'timeline_review_due_at' => null])->save();
            }
            if ($actor->role === Role::Admin && $locked->project_manager_id === null) {
                $locked->forceFill(['project_manager_id' => $actor->id])->save();
            }
            foreach ($this->pendingTickets($locked) as $ticket) {
                $ticket->forceFill(['timeline_status' => 'confirmed', 'timeline_confirmed_at' => now(), 'timeline_confirmed_by' => $actor->id])->save();
                $this->log($locked, 'confirmed', $actor, ['subject' => 'ticket', 'subject_id' => $ticket->id, 'after' => ['ticket' => $ticket->title, 'start' => $ticket->start_date?->toDateString(), 'due' => $ticket->due_date?->toDateString()]]);
            }
            if ($projectPending) {
                $this->log($locked, 'confirmed', $actor, ['after' => ['days' => $this->days($locked), 'start' => $locked->start_date->toDateString(), 'due' => $locked->due_date->toDateString()]]);
            }

            return $locked;
        });
        $this->notifier->confirmed($locked->fresh(['agency', 'client', 'employee']), $actor);

        return $locked;
    }

    public function requestChanges(WorkItem $project, User $admin, string $message): WorkItem
    {
        abort_unless($admin->role === Role::Admin, 403);

        $locked = DB::transaction(function () use ($project, $admin, $message): WorkItem {
            $locked = WorkItem::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless($this->isPending($locked), 422, 'There is nothing waiting for review.');
            if ($locked->project_manager_id === null) {
                $locked->forceFill(['project_manager_id' => $admin->id])->save();
            }
            if (in_array($locked->timeline_status, self::PENDING, true)) {
                $locked->forceFill(['timeline_status' => 'changes_requested'])->save();
            }
            $locked->tickets()->whereIn('timeline_status', ['proposed', 'in_review'])->update(['timeline_status' => 'changes_requested']);
            $this->log($locked, 'changes_requested', $admin, ['note' => $message]);

            return $locked;
        });
        $this->notifier->changesRequested($locked->fresh(['agency', 'client', 'employee']), $admin, $message);

        return $locked;
    }

    /** The owner (or the client who created it) answers a change request; it goes back to proposed. */
    public function respond(WorkItem $project, User $user, ?string $note, array $files = []): WorkItem
    {
        abort_unless(in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true) && $user->agency_id === $project->agency_id, 404);
        abort_if($user->role === Role::AgencyClient && $project->client_id !== $user->id, 404);

        $locked = DB::transaction(function () use ($project, $user, $note, $files): WorkItem {
            $locked = WorkItem::query()->lockForUpdate()->findOrFail($project->id);
            $ticketsWaiting = $locked->tickets()->where('timeline_status', 'changes_requested')->exists();
            abort_unless($locked->timeline_status === 'changes_requested' || $ticketsWaiting, 422, 'No changes are waiting for your reply.');
            if ($locked->timeline_status === 'changes_requested') {
                $locked->forceFill(['timeline_status' => 'proposed', 'timeline_proposed_at' => now(), 'timeline_review_due_at' => $this->reviewDue()])->save();
            }
            $locked->tickets()->where('timeline_status', 'changes_requested')->update(['timeline_status' => 'proposed', 'timeline_proposed_at' => now()]);
            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    $this->files->storeForProject($locked, $user, $file);
                }
            }
            $this->log($locked, 'proposed', $user, ['note' => $note, 'after' => ['response' => true, 'files' => count($files)]]);

            return $locked;
        });
        $this->notifier->ownerResponded($locked->fresh(['agency', 'client', 'employee']), $user, $note);

        return $locked;
    }
}
