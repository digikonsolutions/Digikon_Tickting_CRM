<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\Role;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;

/**
 * The guards every ticket mutation shares: the caller must belong to the project's active agency, the project must be
 * open, and the employee assignment behind it must still be active. Moved out of TicketController so the
 * work-order endpoints apply exactly the same rules.
 */
trait GuardsProjectAccess
{
    private function authorizeProject(
        User $user,
        WorkItem $workItem,
        bool $requireOpen = false,
        bool $requireActiveAssignment = false,
        bool $allowCompleted = false
    ): void {
        abort_unless($this->canAccessProject($user, $workItem), 404);

        if ($requireOpen) {
            $this->ensureOpenProject($workItem, $allowCompleted);
        }

        if ($requireActiveAssignment) {
            $this->ensureActiveProjectAssignment($workItem);
        }
    }

    private function canAccessProject(User $user, WorkItem $workItem): bool
    {
        return in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)
            && $user->agency_id !== null
            && $user->agency?->status === 'active'
            && $workItem->agency_id === $user->agency_id;
    }

    private function ensureOpenProject(WorkItem $workItem, bool $allowCompleted = false): void
    {
        $allowed = $allowCompleted ? ['scheduled', 'in_progress', 'completed'] : ['scheduled', 'in_progress'];
        abort_unless(
            in_array($workItem->status, $allowed, true),
            422,
            $allowCompleted ? 'Tickets cannot be added to a cancelled project.' : 'Tickets can only be changed on open projects.'
        );
    }

    private function ensureActiveProjectAssignment(WorkItem $workItem): void
    {
        abort_unless(
            EmployeeAssignment::query()
                ->whereKey($workItem->assignment_id)
                ->where('employee_id', $workItem->employee_id)
                ->where('agency_id', $workItem->agency_id)
                ->where('status', 'active')
                ->exists(),
            404
        );
    }

    private function lockActiveProjectAssignment(WorkItem $workItem): EmployeeAssignment
    {
        $assignment = EmployeeAssignment::query()
            ->whereKey($workItem->assignment_id)
            ->where('employee_id', $workItem->employee_id)
            ->where('agency_id', $workItem->agency_id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        abort_unless($assignment !== null, 404);

        return $assignment;
    }

    private function ensureActiveEmployeeAgency(User $user): void
    {
        if ($user->agency_id !== null) {
            abort_unless($user->agency?->status === 'active', 403, 'An active agency is required to access employee tickets.');
        }
    }
}
