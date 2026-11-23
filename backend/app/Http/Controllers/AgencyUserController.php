<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AgencyCreateUserRequest;
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

                $query->orWhere(function ($query) use ($viewer): void {
                    $query->where('role', Role::Employee->value)
                        ->whereNull('agency_id')
                        ->whereDoesntHave('employeeAssignments', fn ($q) => $q->where('status', 'active')->where('agency_id', '!=', $viewer->agency_id));
                });
            })
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                ...$this->forViewer($accounts->userPayload($user), $viewer),
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

    /**
     * What an agency user may learn about another account: employees appear only as "team members" of their own agency,
     * with nothing that says whether they are dedicated or shared with other agencies.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function forViewer(array $payload, User $viewer): array
    {
        if (($payload['role'] ?? null) === 'employee') {
            $payload['role'] = 'team_member';
            $payload['agency_id'] = null;
            $payload['agency_name'] = $viewer->agency ? \App\Services\AgencyBrandingPresenter::displayName($viewer->agency) : null;
            $payload = array_intersect_key($payload, array_flip(['id', 'name', 'email', 'role', 'agency_id', 'agency_name', 'photo_url']));
        }

        return $payload;
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
                })->orWhere(function ($query) use ($member): void {
                    $query->where('role', Role::Employee->value)
                        ->whereNull('agency_id')
                        ->whereDoesntHave('employeeAssignments', fn ($q) => $q->where('status', 'active')->where('agency_id', '!=', $member->agency_id));
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user): array => [
                ...$this->forViewer($accounts->userPayload($user), $member),
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
            ->whereDoesntHave('employeeAssignments', fn ($q) => $q->where('status', 'active')->where('agency_id', '!=', $viewer->agency_id))
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (User $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => 'team_member',
                'agency_name' => \App\Services\AgencyBrandingPresenter::displayName($viewer->agency),
                'is_active' => $employee->is_active,
            ]);

        return response()->json(['employees' => $employees]);
    }
}
