<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminStoreAgencyOwnerRequest;
use App\Enums\Role;
use App\Models\User;
use App\Services\AgencyContracts;
use App\Services\EmployeeProfileService;
use Illuminate\Http\Request;
use App\Services\AgencyOnboardingService;
use App\Services\UserAccountService;
use Illuminate\Http\JsonResponse;

/** The "Create agency owner" wizard endpoint. POST /api/auth/users still creates a bare owner and agency. */
class AdminAgencyOwnerController extends Controller
{
    public function store(AdminStoreAgencyOwnerRequest $request, AgencyOnboardingService $onboarding, UserAccountService $accounts): JsonResponse
    {
        $result = $onboarding->create($request->validated(), $request->user(), $request->file('setup.logo'));
        $agency = $result['agency'];
        $owner = $result['owner'];

        return response()->json([
            'agency' => ['id' => $agency->id, 'name' => $agency->name, 'status' => $agency->status, 'ticket_prefix' => $agency->ticket_prefix, 'slug' => $agency->slug, 'portal_path' => '/a/'.$agency->slug],
            'owner' => [...$accounts->userPayload($owner), 'is_active' => $owner->is_active],
            'contract' => $result['contract'] === null ? null : AgencyContracts::present($result['contract']),
            'warnings' => $result['warnings'],
        ], 201);
    }

    /** Active employees nobody holds, for the wizard's "pre-assign employees" step. Optional `search` by name. */
    public function availableEmployees(Request $request, EmployeeProfileService $profiles): JsonResponse
    {
        $search = $request->validate(['search' => ['sometimes', 'string', 'max:120']])['search'] ?? null;
        $employees = User::query()
            ->with(['employeeProfile', 'skills'])
            ->where('role', Role::Employee->value)->where('is_active', true)
            ->whereNotExists(fn ($query) => $query->select(\Illuminate\Support\Facades\DB::raw(1))->from('employee_assignments as ea')
                ->whereColumn('ea.employee_id', 'users.id')->where('ea.status', 'active'))
            ->when($search, fn ($query, string $term) => $query->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')->orderBy('id')->limit(100)->get();

        return response()->json(['employees' => $employees->map(fn (User $employee): array => [
            'id' => $employee->id,
            'name' => $employee->name,
            ...$profiles->publicSummary($employee),
        ])->values()]);
    }

    /** Admins and team leads who can be an agency's account manager. */
    public function accountManagers(): JsonResponse
    {
        $users = User::query()->whereIn('role', [Role::Admin->value, Role::TeamLead->value])->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']);

        return response()->json(['managers' => $users->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role->value])->values()]);
    }}
