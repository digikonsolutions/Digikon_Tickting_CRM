<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AgencyCharge;
use App\Models\RushRequest;
use App\Models\User;

/** How rush requests and charges look to the agency and to admins. Agency payloads never name pool employees or admins. */
class RushPresenter
{
    public const NO_ONE_AVAILABLE = 'No team member is available for this request.';

    public static function money(int $minor, string $currency): string
    {
        // Integer arithmetic only: money is never held as a float.
        return number_format(intdiv($minor, 100), 0, '.', ',').'.'.sprintf('%02d', $minor % 100).' '.$currency;
    }

    /**
     * @return array<string, mixed>
     */
    public function forAgency(RushRequest $request, User $viewer, ?array $impact = null): array
    {
        $isOwner = $viewer->role === Role::AgencyOwner;
        $request->loadMissing(['requester', 'assignment.employee']);
        $hasQuote = $request->quoted_amount_minor !== null;

        $message = null;
        if ($request->status === 'rejected') {
            $message = $request->type === 'extra_employee' || blank($request->rejection_reason)
                ? ($request->type === 'extra_employee' ? self::NO_ONE_AVAILABLE : 'This request could not be accommodated.')
                : $request->rejection_reason;
        }

        return [
            'id' => $request->id,
            'type' => $request->type,
            'title' => $request->title,
            'description' => $request->description,
            'skills_notes' => $request->skills_notes,
            'estimated_hours' => $request->estimated_hours,
            'needed_by' => $request->needed_by->toDateString(),
            'reason' => $request->reason,
            'status' => $request->status,
            'message' => $message,
            'requested_by' => ['id' => $request->requester?->id, 'name' => $request->requester?->name],
            'employee' => $request->type === 'priority_insert' && $request->assignment?->employee
                ? ['id' => $request->assignment->employee->id, 'name' => $request->assignment->employee->name]
                : null,
            // Spending is the owner's business: clients see that a request exists and where it stands, never the price.
            'quote' => $isOwner && $hasQuote ? [
                'amount_minor' => $request->quoted_amount_minor,
                'currency' => $request->currency,
                'amount_display' => self::money($request->quoted_amount_minor, $request->currency),
                'expires_at' => $request->quote_expires_at?->toISOString(),
                'note' => $request->quote_note,
            ] : null,
            'can_accept' => $isOwner && $request->status === 'quoted',
            'impact' => $isOwner ? $impact : null,
            'fulfilled_project_id' => $request->fulfilled_work_item_id,
            'created_at' => $request->created_at?->toISOString(),
            'responded_at' => $request->responded_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forAdmin(RushRequest $request): array
    {
        $request->loadMissing(['agency', 'requester', 'assignment.employee', 'proposedEmployee', 'quoter', 'charge']);

        return [
            'id' => $request->id,
            'type' => $request->type,
            'status' => $request->status,
            'agency' => ['id' => $request->agency_id, 'name' => $request->agency?->name],
            'requested_by' => ['id' => $request->requester?->id, 'name' => $request->requester?->name, 'role' => $request->requester?->role->value],
            'employee' => $request->assignment?->employee ? ['id' => $request->assignment->employee->id, 'name' => $request->assignment->employee->name] : null,
            'proposed_employee' => $request->proposedEmployee ? ['id' => $request->proposedEmployee->id, 'name' => $request->proposedEmployee->name] : null,
            'title' => $request->title,
            'description' => $request->description,
            'skills_notes' => $request->skills_notes,
            'estimated_hours' => $request->estimated_hours,
            'needed_by' => $request->needed_by->toDateString(),
            'reason' => $request->reason,
            'quoted_amount_minor' => $request->quoted_amount_minor,
            'currency' => $request->currency,
            'amount_display' => $request->quoted_amount_minor === null ? null : self::money($request->quoted_amount_minor, $request->currency),
            'quote_expires_at' => $request->quote_expires_at?->toISOString(),
            'quote_note' => $request->quote_note,
            'quoted_by' => $request->quoter?->name,
            'rejection_reason' => $request->rejection_reason,
            'charge_id' => $request->charge?->id,
            'fulfilled_project_id' => $request->fulfilled_work_item_id,
            'created_at' => $request->created_at?->toISOString(),
            'responded_at' => $request->responded_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function charge(AgencyCharge $charge): array
    {
        $charge->loadMissing('agency');

        return [
            'id' => $charge->id,
            'agency' => ['id' => $charge->agency_id, 'name' => $charge->agency?->name],
            'rush_request_id' => $charge->rush_request_id,
            'description' => $charge->description,
            'amount_minor' => $charge->amount_minor,
            'currency' => $charge->currency,
            'amount_display' => self::money($charge->amount_minor, $charge->currency),
            'status' => $charge->status,
            'created_at' => $charge->created_at?->toISOString(),
        ];
    }
}
