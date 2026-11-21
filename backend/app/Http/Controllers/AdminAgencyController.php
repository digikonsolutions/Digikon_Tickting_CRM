<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Services\AgencyContracts;
use App\Services\AgencyInsights;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read side of the Agencies pages (status changes stay on AdminUserController::updateAdminAgency). */
class AdminAgencyController extends Controller
{
    public function index(Request $request, AdminDashboardService $dashboard): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);

        return response()->json($dashboard->agencyPage(
            CarbonImmutable::now(config('app.timezone')),
            $filters['search'] ?? null,
            $filters['per_page'] ?? 25
        ));
    }

    /** Everything the agency detail tabs show, in one call. A tab with nothing to show gets an empty list or null. */
    public function show(Agency $agency, AgencyInsights $insights, AgencyContracts $contracts): JsonResponse
    {
        $contract = $contracts->forAgency($agency->id);
        $limits = AgencyContracts::limits($contract);
        $usage = $insights->usage($agency->id);
        $owner = User::query()->where('agency_id', $agency->id)->where('role', Role::AgencyOwner->value)->orderBy('id')->first();

        return response()->json(['agency' => [
            'id' => $agency->id,
            'name' => $agency->name,
            'status' => $agency->status,
            'ticket_prefix' => $agency->ticket_prefix,
            'created_at' => $agency->created_at?->toISOString(),
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'is_active' => $owner->is_active] : null,
            'contract' => $contract === null ? null : [...$contract, 'days_remaining' => AgencyContracts::daysRemaining($contract)],
            'limits' => $limits,
            'counts' => $insights->counts($agency->id),
            'usage' => $usage,
            'employees' => $insights->heldEmployees($agency->id),
            'projects' => $insights->recentProjects($agency->id, null, 10),
        ]]);
    }
}
