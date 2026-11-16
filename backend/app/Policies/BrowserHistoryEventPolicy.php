<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\BrowserHistoryEvent;
use App\Models\User;

/**
 * Administrators may read browser history and nothing else. Every write ability is false for everyone,
 * admins included; the extension sync inserts rows through its own controller after its own checks.
 */
class BrowserHistoryEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === Role::Admin;
    }

    public function view(User $user, BrowserHistoryEvent $event): bool
    {
        return $user->role === Role::Admin;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, BrowserHistoryEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, BrowserHistoryEvent $event): bool
    {
        return false;
    }

    public function restore(User $user, BrowserHistoryEvent $event): bool
    {
        return false;
    }

    public function forceDelete(User $user, BrowserHistoryEvent $event): bool
    {
        return false;
    }
}
