<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;

/**
 * The single source of truth for who may see, and who may see internal content on, a ticket.
 *
 * - admin: everything
 * - the assigned employee: the ticket and internal content (an employee in a suspended agency loses access)
 * - owner/client of the ticket's agency (agency must be active): the ticket, never internal content
 * - everyone else: nothing
 */
class TicketAccess
{
    public function canView(User $user, Ticket $ticket): bool
    {
        if ($user->role === Role::Admin) {
            return true;
        }

        if ($this->isAssignedEmployee($user, $ticket)) {
            return $user->agency_id === null || $user->agency?->status === 'active';
        }

        return $this->isAgencyParticipant($user, $ticket);
    }

    /** Internal comments, internal events and attachments on internal comments. */
    public function canSeeInternal(User $user, Ticket $ticket): bool
    {
        return $user->role === Role::Admin || $this->isAssignedEmployee($user, $ticket);
    }

    public function isAssignedEmployee(User $user, Ticket $ticket): bool
    {
        return $user->role === Role::Employee && $ticket->workItem?->employee_id === $user->id;
    }

    public function isAgencyParticipant(User $user, Ticket $ticket): bool
    {
        return in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)
            && $user->agency_id !== null
            && $user->agency_id === $ticket->agency_id
            && $user->agency?->status === 'active';
    }

    /** Owner of the ticket's agency (used for delete rights and notifications). */
    public function isAgencyOwner(User $user, Ticket $ticket): bool
    {
        return $user->role === Role::AgencyOwner && $this->isAgencyParticipant($user, $ticket);
    }

    /** Callers answer 404 so ticket existence is not disclosed. */
    public function authorizeView(User $user, Ticket $ticket): void
    {
        abort_unless($this->canView($user, $ticket), 404);
    }
}
