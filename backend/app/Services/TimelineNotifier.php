<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\TimelineNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Every message of the timeline review: who is told, what it says and where the link goes. Each message is an
 * in-app notification plus a plain, unbranded email (the shared TicketNotification mail layout), sent through the
 * same TicketNotifier as the rest of the app, so recipients' preferences and "inactive users get nothing" apply.
 *
 * A message only carries what that recipient may already see: the owner and the client see their own project,
 * the employee sees their assignment, admins see everything. Internal ticket comments are never included.
 */
class TimelineNotifier
{
    public function __construct(private TicketNotifier $notifier) {}

    private function frontendPath(string $kind, WorkItem $project): string
    {
        return match ($kind) {
            'admin' => "/admin/timeline-reviews/{$project->id}",
            'employee' => "/my-work/timeline/{$project->id}",
            default => "/agency/projects/{$project->id}",
        };
    }

    /** @return list<User> */
    private function admins(WorkItem $project, bool $managerOnly = false): array
    {
        if ($managerOnly && $project->project_manager_id !== null) {
            $manager = User::query()->where('is_active', true)->find($project->project_manager_id);
            if ($manager !== null) {
                return [$manager];
            }
        }

        return User::query()->where('role', Role::Admin->value)->where('is_active', true)->get()->all();
    }

    /** @return list<User> The agency's owners (the people who run the account). */
    private function owners(WorkItem $project): array
    {
        return User::query()->where('agency_id', $project->agency_id)->where('role', Role::AgencyOwner->value)->where('is_active', true)->get()->all();
    }

    /** @return array<int, User> keyed by id, so nobody is told twice */
    private function unique(array ...$groups): array
    {
        $users = [];
        foreach ($groups as $group) {
            foreach ($group as $user) {
                if ($user !== null) {
                    $users[$user->id] = $user;
                }
            }
        }

        return $users;
    }

    private function hoursText(WorkItem $project, bool $forAgency): string
    {
        $fmt = fn (float $hours): string => rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').' h';
        $proposed = (float) $project->estimated_hours;
        $final = $project->scheduledHours();
        if (abs($final - $proposed) < 0.005) {
            return $fmt($final);
        }

        return $fmt($final).($forAgency ? ' (you proposed ' : ' (proposed ').$fmt($proposed).')';
    }

    /** The one line of facts a message carries. Agency recipients never get the agency's own name back or any staff name. */
    private function facts(WorkItem $project, bool $withSchedule = false, bool $forAgency = false): string
    {
        $project->loadMissing(['agency', 'client', 'employee']);
        $parts = $forAgency ? [] : ["Agency: {$project->agency?->name}"];
        $parts[] = 'Client: '.($project->client?->name ?? 'n/a');
        $parts[] = 'Employee: '.($project->employee?->name ?? 'n/a');
        $parts[] = 'Hours: '.$this->hoursText($project, $forAgency);
        $parts[] = 'Dates: '.$project->start_date?->format('D j M Y').' to '.$project->due_date?->format('D j M Y');
        if ($withSchedule) {
            $days = $project->allocations()->orderBy('work_date')->get()->map(fn ($a): string => CarbonImmutable::parse($a->work_date)->format('D j M').' '.rtrim(rtrim(number_format((float) $a->hours, 2, '.', ''), '0'), '.').' h')->implode(', ');
            $parts[] = ($forAgency ? 'Days' : 'Proposed days').": {$days}";
        }

        return implode(' · ', $parts);
    }
    private function send(User $user, array $payload): void
    {
        try {
            $this->notifier->send($user, TimelineNotice::class, $payload);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Timeline notification failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    private function notes(WorkItem $project): string
    {
        $count = $project->attachments()->count();

        return ($project->description ? ' Notes: '.str($project->description)->limit(300) : '').($count ? " {$count} attached file".($count === 1 ? '' : 's').'.' : '');
    }

    /** A project or ticket was created: admins to review, the employee told, the creator reassured. */
    public function proposed(WorkItem $project, User $creator, ?string $ticketTitle = null): void
    {
        $what = $ticketTitle !== null ? "Ticket “{$ticketTitle}” on {$project->title}" : "Project “{$project->title}”";
        foreach ($this->admins($project) as $admin) {
            $this->send($admin, [
                'project_id' => $project->id,
                'title' => 'Timeline review needed: '.($ticketTitle ?? $project->title),
                'body' => "{$creator->name} proposed {$what}. Set the final hours and days, then confirm. ".$this->facts($project, true).'.'.$this->notes($project),
                'url_path' => $this->frontendPath('admin', $project),
                'actor_name' => $creator->name,
                'action_text' => 'Review timeline',
            ]);
        }
        if ($project->employee !== null && $ticketTitle === null) {
            $this->send($project->employee, [
                'project_id' => $project->id,
                'title' => "You've been assigned: {$project->title}",
                'body' => "You have been assigned to “{$project->title}”. The timeline is pending review; you can start once it is confirmed. ".$this->facts($project).'.',
                'url_path' => $this->frontendPath('employee', $project),
                'actor_name' => $creator->name,
                'action_text' => 'Open timeline',
            ]);
        }
        // Agency side: "sent for review" and nothing about who reviews it. The creator is told directly, the rest of the account is kept in the loop.
        foreach ($this->unique($this->owners($project), [$project->client, $creator]) as $user) {
            $mine = $user->id === $creator->id;
            $this->send($user, [
                'project_id' => $project->id,
                'title' => 'Request sent for review: '.($ticketTitle ?? $project->title),
                'body' => ($mine ? "Your request has been sent for review. ({$what})" : "A request has been sent for review by {$creator->name}: {$what}.")
                    .' Our team will plan the schedule and tell you as soon as it is confirmed. Any hours you entered are only an estimate.',
                'url_path' => $this->frontendPath('agency', $project),
                'action_text' => 'View project',
            ]);
        }
    }
    public function changesRequested(WorkItem $project, User $admin, string $message): void
    {
        foreach ($this->unique($this->owners($project), [$project->client]) as $user) {
            $this->send($user, [
                'project_id' => $project->id,
                'title' => "A few changes are needed: {$project->title}",
                'body' => "Our team needs a few changes before “{$project->title}” can be confirmed: ".str($message)->limit(400).' Please reply with your update.',
                'url_path' => $this->frontendPath('agency', $project),
                'action_text' => 'Reply to our team',
            ]);
        }
    }

    public function ownerResponded(WorkItem $project, User $responder, ?string $note): void
    {
        foreach ($this->admins($project, managerOnly: true) as $admin) {
            $this->send($admin, [
                'project_id' => $project->id,
                'title' => "Reply received: {$project->title}",
                'body' => "{$responder->name} replied about “{$project->title}” and it is back in the review queue.".($note ? ' Reply: '.str($note)->limit(400) : ''),
                'url_path' => $this->frontendPath('admin', $project),
                'actor_name' => $responder->name,
                'action_text' => 'Review timeline',
            ]);
        }
    }

    public function confirmed(WorkItem $project, User $by): void
    {
        $project->loadMissing(['employee']);
        $manager = $project->project_manager_id ? User::query()->find($project->project_manager_id) : null;
        $recipients = $this->unique($this->owners($project), [$project->client, $project->employee, $manager]);
        foreach ($recipients as $user) {
            $kind = $user->role === Role::Employee ? 'employee' : ($user->role === Role::Admin ? 'admin' : 'agency');
            if ($kind === 'agency') {
                // Owner and client: the outcome and the final numbers, never who confirmed it.
                $differs = abs($project->scheduledHours() - (float) $project->estimated_hours) >= 0.005;
                $this->send($user, [
                    'project_id' => $project->id,
                    'title' => "Confirmed: {$project->title}",
                    'body' => "Your timeline has been confirmed. “{$project->title}”: ".$this->facts($project, true, forAgency: true).'.'
                        .($differs ? ' The final hours differ from your proposal based on the work needed.' : '')
                        .($differs && filled($project->planned_note) ? ' Note: '.str($project->planned_note)->limit(300) : ''),
                    'url_path' => $this->frontendPath($kind, $project),
                    'action_text' => 'View timeline',
                ]);

                continue;
            }
            $this->send($user, [
                'project_id' => $project->id,
                'title' => "Timeline confirmed: {$project->title}",
                'body' => "The timeline of “{$project->title}” was confirmed by {$by->name}. ".$this->facts($project, true).'.',
                'url_path' => $this->frontendPath($kind, $project),
                'actor_name' => $by->name,
                'action_text' => 'View timeline',
            ]);
        }
    }

    /** @param  int  $hours  how long the review has been waiting */
    public function overdue(WorkItem $project, int $hours): void
    {
        foreach ($this->admins($project, managerOnly: true) as $admin) {
            $this->send($admin, [
                'project_id' => $project->id,
                'title' => "Review overdue ({$hours} h): {$project->title}",
                'body' => "The timeline of “{$project->title}” has waited {$hours} hours for review. ".$this->facts($project).'.',
                'url_path' => $this->frontendPath('admin', $project),
                'action_text' => 'Review timeline',
            ]);
        }
    }

    /** An assignment ended while timelines were unconfirmed: admins and owners are told they were released. */
    public function released(WorkItem $project): void
    {
        foreach ($this->unique($this->admins($project), $this->owners($project)) as $user) {
            $kind = $user->role === Role::Admin ? 'admin' : 'agency';
            $this->send($user, [
                'project_id' => $project->id,
                'title' => "Timeline released: {$project->title}",
                'body' => "The employee assignment for “{$project->title}” ended, so its timeline and reserved hours were released.",
                'url_path' => $this->frontendPath($kind, $project),
                'action_text' => 'View project',
            ]);
        }
    }
}
