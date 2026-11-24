<?php

namespace App\Services\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * Who may do what on the support side. Admins hold every capability; an employee holds the ones saved on their account.
 * An employee with none keeps working their assigned tickets exactly as before.
 */
final class StaffCapability
{
    public const ALL = ['triage', 'builder', 'qa', 'delivery_manager', 'finance'];

    public static function isStaff(?User $user): bool
    {
        return $user !== null && in_array($user->role, [Role::Admin, Role::Employee, Role::TeamLead, Role::Qa], true);
    }

    public static function has(?User $user, string ...$capabilities): bool
    {
        if (! self::isStaff($user)) {
            return false;
        }
        if ($user->role === Role::Admin) {
            return true;
        }
        $held = $user->staff_capabilities ?? [];

        return array_intersect($capabilities, $held) !== [];
    }

    /** Anyone on the support side with at least one capability (or an admin). */
    public static function any(?User $user): bool
    {
        return self::isStaff($user) && ($user->role === Role::Admin || ($user->staff_capabilities ?? []) !== []);
    }

    /** Triage and delivery managers route work and make the calls that need judgement. */
    public static function manages(?User $user): bool
    {
        return self::has($user, 'triage', 'delivery_manager');
    }
}
