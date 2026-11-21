<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\PublicEmployeeProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The limited profile agency owners and clients see before holding an employee and on project screens.
 * Only active employees are viewable, and only those the agency could hold: its own or shared ones.
 * Everything else is a 404, so ids reveal nothing about other agencies' employees or non-employees.
 */
class AgencyEmployeeProfileController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $viewer = $request->user();

        $employee = User::query()
            ->where('role', Role::Employee->value)
            ->where('is_active', true)
            ->where(function ($query) use ($viewer): void {
                $query->whereNull('agency_id')->orWhere('agency_id', $viewer->agency_id);
            })
            ->findOrFail($id);

        return response()->json(['employee' => (new PublicEmployeeProfileResource($employee))->resolve()]);
    }
}
