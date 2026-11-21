<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\AgencyRequestNotice;
use App\Notifications\AgencyStatusNotice;
use App\Notifications\AssignmentEnded;
use App\Notifications\AssignmentHeld;
use App\Notifications\EmployeeDeactivatedNotice;
use App\Notifications\ProjectAssigned;
use App\Notifications\ProjectCancelled;
use App\Notifications\ProjectStatusChanged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handles workflow notifications for non-ticket system events:
 * projects, assignments, and account/agency state changes.
 *
 * Mail failures are swallowed and logged so they never break the user's action.
 */
class WorkflowNotificationService
{
    public function __construct(private TicketNotifier $notifier) {}

    /**
     * An agency user asks the administrators for more hours, a plan upgrade or a renewal. Every active admin gets an
     * in-app notification (and an email if their preferences allow it). Returns how many admins were notified.
     */
    public function agencyRequest(Agency $agency, User $requester, string $type, ?string $message): int
    {
        $what = [
            'more_hours' => ['requests more hours', 'more hours'],
            'upgrade' => ['requests a plan upgrade', 'a plan upgrade'],
            'renewal' => ['requests a contract renewal', 'a contract renewal'],
        ][$type] ?? ['sends a request', 'help'];

        $sent = 0;
        try {
            $admins = User::query()->where('role', Role::Admin->value)->where('is_active', true)->get();
            $body = "{$requester->name} from {$agency->name} asks for {$what[1]}.";
            if ($message !== null && trim($message) !== '') {
                $body .= ' Message: '.trim($message);
            }

            foreach ($admins as $admin) {
                $this->notifier->send($admin, AgencyRequestNotice::class, [
                    'title' => "{$agency->name} {$what[0]}",
                    'body' => $body,
                    'url_path' => "/admin/agencies/{$agency->id}?tab=contract",
                    'actor_name' => $requester->name,
                    'action_text' => 'Open agency',
                ]);
                $sent++;
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Workflow notification failed in agencyRequest: '.$e->getMessage(), ['exception' => $e]);
        }

        return $sent;
    }

    /**
     * Notify employee when assigned to a new project.
     */
    public function projectCreated(WorkItem $workItem, User $creator): void
    {
        try {
            $employee = $workItem->employee;
            if (! $employee || ! $this->isEligible($employee, $creator->id)) {
                return;
            }

            $agencyName = $workItem->agency?->name ?? 'ClientHub';
            $dates = $workItem->start_date && $workItem->due_date
                ? " ({$workItem->start_date->format('Y-m-d')} to {$workItem->due_date->format('Y-m-d')})"
                : '';

            $payload = [
                'project_id' => $workItem->id,
                'title' => "New project assigned: {$workItem->title}",
                'body' => "You have been assigned to project '{$workItem->title}'{$dates} with {$workItem->estimated_hours}h estimated for {$agencyName}.",
                'url_path' => '/my-work',
                'actor_name' => $creator->name,
                'action_text' => 'View project',
            ];

            $this->notifier->send($employee, ProjectAssigned::class, $payload);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Workflow notification failed in projectCreated: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Notify employee, creator, and agency owners when a project is cancelled.
     */
    public function projectCancelled(WorkItem $workItem, User $actor): void
    {
        try {
            $recipients = collect([$workItem->employee, $workItem->client])
                ->merge($this->agencyOwners($workItem->agency_id));

            $eligible = $this->filterEligible($recipients, $actor->id);

            foreach ($eligible as $user) {
                $urlPath = $user->role === Role::Employee ? '/my-work' : "/agency/projects/{$workItem->id}";
                $payload = [
                    'project_id' => $workItem->id,
                    'title' => "Project cancelled: {$workItem->title}",
                    'body' => "Project '{$workItem->title}' was cancelled by {$actor->name}.",
                    'url_path' => $urlPath,
                    'actor_name' => $actor->name,
                    'action_text' => 'View project',
                ];

                $this->notifier->send($user, ProjectCancelled::class, $payload);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Workflow notification failed in projectCancelled: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Notify creator and agency owners when project status changes (e.g. in_progress, completed).
     */
    public function projectStatusChanged(WorkItem $workItem, string $from, string $to, User $actor): void
    {
        try {
            $recipients = collect([$workItem->client])
                ->merge($this->agencyOwners($workItem->agency_id));

            $eligible = $this->filterEligible($recipients, $actor->id);
            if ($eligible->isEmpty()) {
                return;
            }

            $title = $to === 'completed'
                ? "Project completed: {$workItem->title}"
                : "Project in progress: {$workItem->title}";

            $body = $to === 'completed'
                ? "All tickets for '{$workItem->title}' are done. The project is completed."
                : "Work on '{$workItem->title}' has started.";

            foreach ($eligible as $user) {
                $urlPath = $user->role === Role::Employee ? '/my-work' : "/agency/projects/{$workItem->id}";
                $payload = [
                    'project_id' => $workItem->id,
                    'title' => $title,
                    'body' => $body,
                    'url_path' => $urlPath,
                    'actor_name' => $actor->name,
                    'action_text' => 'View project',
                ];

                $this->notifier->send($user, ProjectStatusChanged::class, $payload);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Workflow notification failed in projectStatusChanged: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Notify employee when an assignment is held.
     */
    public function assignmentHeld(EmployeeAssignment $assignment, User $holder): void
    {
        try {
            $employee = $assignment->employee;
            if (! $employee || ! $this->isEligible($employee, $holder->id)) {
                return;
            }

            $agencyName = $assignment->agency?->name ?? 'ClientHub';
            $payload = [
                'assignment_id' => $assignment->id,
                'title' => 'New team assignment',
                'body' => "You have been assigned to {$holder->name} ({$agencyName}).",
                'url_path' => '/my-work',
                'actor_name' => $holder->name,
                'action_text' => 'View your work',
            ];

            $this->notifier->send($employee, AssignmentHeld::class, $payload);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Workflow notification failed in assignmentHeld: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Notify employee and holder when an assignment ends.
     */
    public function assignmentEnded(EmployeeAssignment $assignment, ?User $actor = null): void
    {
        try {
            $actorId = $actor?->id;
            $actorName = $actor?->name ?? 'System';

            $recipients = collect([$assignment->employee, $assignment->holder]);
            $eligible = $this->filterEligible($recipients, $actorId);

            foreach ($eligible as $user) {
                $urlPath = $user->role === Role::Employee ? '/my-work' : '/agency/employees';
                $payload = [
                    'assignment_id' => $assignment->id,
                    'title' => 'Assignment ended',
                    'body' => 'The employee assignment has ended. Open projects have been cancelled.',
                    'url_path' => $urlPath,
                    'actor_name' => $actorName,
                    'action_text' => 'View team',
                ];

                $this->notifier->send($user, AssignmentEnded::class, $payload);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Workflow notification failed in assignmentEnded: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Notify clients/holders when their open projects are cancelled because an employee was deactivated.
     */
    public function employeeDeactivated(User $employee, Collection $affectedClients): void
    {
        try {
            $eligible = $this->filterEligible($affectedClients, $employee->id);

            foreach ($eligible as $user) {
                $payload = [
                    'title' => 'Project cancelled - employee unavailable',
                    'body' => "Your project with {$employee->name} was cancelled because the employee account was deactivated.",
                    'url_path' => '/agency/projects',
                    'actor_name' => 'System',
                    'action_text' => 'View projects',
                ];

                $this->notifier->send($user, EmployeeDeactivatedNotice::class, $payload);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Workflow notification failed in employeeDeactivated: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Notify agency owner when agency status changes.
     */
    public function agencyStatusChanged(Agency $agency, string $newStatus, ?User $actor = null): void
    {
        try {
            $owners = User::query()
                ->where('agency_id', $agency->id)
                ->where('role', Role::AgencyOwner->value)
                ->where('is_active', true)
                ->get();

            $eligible = $this->filterEligible($owners, $actor?->id, allowSuspendedAgency: true);

            foreach ($eligible as $owner) {
                $payload = [
                    'title' => "Agency status updated: {$newStatus}",
                    'body' => "The status of agency '{$agency->name}' has been updated to {$newStatus}.",
                    'url_path' => '/agency/directory',
                    'actor_name' => $actor?->name ?? 'Administrator',
                    'action_text' => 'View agency',
                ];

                $this->notifier->send($owner, AgencyStatusNotice::class, $payload);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Workflow notification failed in agencyStatusChanged: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Helper to get agency owners.
     */
    private function agencyOwners(?int $agencyId): Collection
    {
        if (! $agencyId) {
            return collect();
        }

        return User::query()
            ->where('agency_id', $agencyId)
            ->where('role', Role::AgencyOwner->value)
            ->where('is_active', true)
            ->get();
    }

    private function isEligible(User $user, ?int $actorId, bool $allowSuspendedAgency = false): bool
    {
        if ($actorId !== null && $user->id === $actorId) {
            return false;
        }

        if (! $user->is_active) {
            return false;
        }

        if (! $allowSuspendedAgency && $user->agency_id !== null && $user->agency?->status === 'suspended') {
            return false;
        }

        return true;
    }

    private function filterEligible(Collection $users, ?int $actorId, bool $allowSuspendedAgency = false): Collection
    {
        return $users
            ->filter()
            ->unique('id')
            ->filter(fn (User $u) => $this->isEligible($u, $actorId, $allowSuspendedAgency))
            ->values();
    }
}
