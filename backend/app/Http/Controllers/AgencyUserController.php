<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AgencyCreateUserRequest;
use App\Http\Requests\AgencyUpdateProfileRequest;
use App\Http\Requests\AgencyUpdateUserRequest;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgencyUserController extends Controller
{
    public function createAgencyUser(AgencyCreateUserRequest $request, UserAccountService $accounts): JsonResponse
    {
        $creator = $request->user();
        $user = $accounts->createInvitedUser([
            ...$request->validated(),
            'agency_id' => $creator->agency_id,
        ]);

        return response()->json(['user' => $accounts->userPayload($user)], 201);
    }

    public function agencyUsers(Request $request, UserAccountService $accounts): JsonResponse
    {
        $viewer = $request->user();
        $agencyRoles = $accounts->agencyManagedRoles();

        $users = User::query()
            ->where(function ($query) use ($viewer, $agencyRoles): void {
                $query->where(function ($query) use ($viewer, $agencyRoles): void {
                    $query->where('agency_id', $viewer->agency_id)
                        ->whereIn('role', $agencyRoles);
                });

                $query->orWhere(function ($query): void {
                    $query->where('role', Role::Employee->value)
                        ->whereNull('agency_id');
                });
            })
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                ...$accounts->userPayload($user),
                'is_active' => $user->is_active,
                'can_manage' => $user->agency_id === $viewer->agency_id
                    && in_array($user->role->value, $accounts->agencyManagedRoles(), true),
            ]);

        return response()->json(['users' => $users]);
    }

    public function updateAgencyUser(AgencyUpdateUserRequest $request, int $agencyUser, UserAccountService $accounts): JsonResponse
    {
        $manager = $request->user();
        $user = $accounts->findManagedAgencyUser($manager, $agencyUser);
        $oldEmail = $user->email;
        $data = $request->validated();
        $passwordSetupEmailSent = isset($data['email']) && $data['email'] !== $oldEmail;

        $accounts->updateManagedUser($user, $data, $oldEmail, $passwordSetupEmailSent);

        return response()->json([
            'user' => [
                ...$accounts->userPayload($user),
                'is_active' => $user->is_active,
                'password_setup_email_sent' => $passwordSetupEmailSent,
            ],
        ]);
    }

    public function deleteAgencyUser(Request $request, int $agencyUser, UserAccountService $accounts): JsonResponse
    {
        $manager = $request->user();
        $accounts->deleteUser($accounts->findManagedAgencyUser($manager, $agencyUser));

        return response()->json(['message' => 'The account was permanently deleted.']);
    }

    public function agencyDirectory(Request $request, UserAccountService $accounts): JsonResponse
    {
        $member = $request->user();

        $users = User::query()
            ->whereKeyNot($member->id)
            ->where('email', '<>', $member->email)
            ->where(function ($query) use ($member, $accounts): void {
                $query->where(function ($query) use ($member, $accounts): void {
                    $query->where('agency_id', $member->agency_id)
                        ->whereIn('role', $accounts->agencyDirectoryRoles());
                })->orWhere(function ($query): void {
                    $query->where('role', Role::Employee->value)
                        ->whereNull('agency_id');
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user): array => [
                ...$accounts->userPayload($user),
                'is_active' => $user->is_active,
            ]);

        return response()->json(['users' => $users]);
    }

    public function agencyTeam(Request $request): JsonResponse
    {
        $viewer = $request->user();

        $employees = User::query()
            ->where('role', Role::Employee->value)
            ->whereNull('agency_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (User $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => $employee->role->value,
                'agency_name' => 'Shared employee',
                'is_active' => $employee->is_active,
            ]);

        return response()->json(['employees' => $employees]);
    }

    public function updateAgencyProfile(AgencyUpdateProfileRequest $request, UserAccountService $accounts): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json(['user' => $accounts->userPayload($user)]);
    }
}
