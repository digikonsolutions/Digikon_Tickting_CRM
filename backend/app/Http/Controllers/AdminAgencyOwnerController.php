<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminStoreAgencyOwnerRequest;
use App\Services\AgencyContracts;
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
            'agency' => ['id' => $agency->id, 'name' => $agency->name, 'status' => $agency->status, 'ticket_prefix' => $agency->ticket_prefix],
            'owner' => [...$accounts->userPayload($owner), 'is_active' => $owner->is_active],
            'contract' => $result['contract'] === null ? null : AgencyContracts::present($result['contract']),
            'warnings' => $result['warnings'],
        ], 201);
    }
}