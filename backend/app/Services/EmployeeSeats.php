<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\AgencyContract;
use App\Models\EmployeeAssignment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Who may put an employee on an agency, and how many.
 *
 * - Seats = the contract's employee count. Active assignments of the agency may never exceed it.
 * - Agency clients can never add employees.
 * - An owner may add employees only on a Custom plan, or when the admin created the agency without assigning any
 *   employee (then on any plan). Otherwise employees are assigned by the account manager and the owner can
 *   only request one.
 * - No contract means no known seat limit and no plan, so only the "admin assigned nobody" exception applies.
 */
class EmployeeSeats
{
    public function __construct(private AgencyContracts $contracts) {}

    /** @return array{seats: int|null, used: int, remaining: int|null, full: bool, custom_plan: bool, admin_assigned: bool, can_add: bool, reason: string|null} */
    public function summary(int $agencyId): array
    {
        $contract = $this->contracts->forAgency($agencyId);
        $seats = AgencyContracts::limits($contract)['seats'];
        $used = EmployeeAssignment::query()->where('agency_id', $agencyId)->where('status', 'active')->count();
        $custom = $this->isCustom($contract);
        // Legacy rows have no source and count as admin-assigned. Ended assignments still count: the admin did assign them.
        $adminAssigned = EmployeeAssignment::query()->where('agency_id', $agencyId)->where(fn ($q) => $q->whereNull('source')->orWhere('source', 'admin'))->exists();
        $permitted = $custom || ! $adminAssigned;
        $full = $seats !== null && $used >= $seats;

        return [
            'seats' => $seats,
            'used' => $used,
            'remaining' => $seats !== null ? max(0, $seats - $used) : null,
            'full' => $full,
            'custom_plan' => $custom,
            'admin_assigned' => $adminAssigned,
            'can_add' => $permitted && ! $full,
            'reason' => match (true) {
                ! $permitted => 'Employees are assigned by your account manager. Use “Request an employee” to ask for one.',
                $full => "All {$seats} employee ".($seats === 1 ? 'seat is' : 'seats are').' in use. Release an employee or request more seats.',
                default => null,
            },
        ];
    }

    /** Throws when this user may not add another employee to their agency. Call inside the transaction that creates the assignment. */
    public function assertCanAdd(User $holder): void
    {
        abort_unless($holder->role === Role::AgencyOwner, 403, 'Only the agency owner can add employees. Ask your owner or account manager.');

        // Serialise concurrent adds for one agency so two requests cannot both take the last seat.
        Agency::query()->lockForUpdate()->findOrFail($holder->agency_id);
        $summary = $this->summary($holder->agency_id);

        if (! $summary['custom_plan'] && $summary['admin_assigned']) {
            abort(403, $summary['reason']);
        }
        if ($summary['full']) {
            throw ValidationException::withMessages(['employee_id' => $summary['reason']]);
        }
    }

    /** @param  array<string, mixed>|null  $contract */
    private function isCustom(?array $contract): bool
    {
        if ($contract === null) {
            return false;
        }
        $row = AgencyContract::query()->find($contract['id'] ?? 0);

        return $row !== null && ($row->plan_id === null || (bool) Plan::query()->whereKey($row->plan_id)->value('is_custom'));
    }
}
