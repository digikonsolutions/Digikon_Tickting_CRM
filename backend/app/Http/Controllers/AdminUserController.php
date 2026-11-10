<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AdminCreateUserRequest;
use App\Http\Requests\AdminUpdateAgencyRequest;
use App\Http\Requests\AdminUpdateUserRequest;
use App\Models\Agency;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    public function createUser(AdminCreateUserRequest $request, UserAccountService $accounts): JsonResponse
    {
        $user = $accounts->createInvitedUser($request->validated());

        return response()->json(['user' => $accounts->userPayload($user)], 201);
    }

    public function adminUsers(Request $request, UserAccountService $accounts): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', 'string', 'in:'.implode(',', array_map(
                fn (Role $role): string => $role->value,
                Role::cases()
            ))],
        ]);

        $users = User::query()
            ->with('agency')
            ->whereKeyNot($request->user()->id)
            ->where('email', '<>', $request->user()->email)
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->where('role', $role))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'users' => collect($users->items())->map(fn ($user): array => [
                ...$accounts->userPayload($user),
                'is_active' => $user->is_active,
                'can_manage' => in_array($user->role->value, $accounts->adminManagedRoles(), true),
            ]),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function updateAdminUser(AdminUpdateUserRequest $request, int $adminUser, UserAccountService $accounts): JsonResponse
    {
        $user = $accounts->findAdminManagedUser($adminUser);
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

    public function deleteAdminUser(int $adminUser, UserAccountService $accounts): JsonResponse
    {
        $accounts->deleteUser($accounts->findAdminManagedUser($adminUser));

        return response()->json(['message' => 'The account was permanently deleted.']);
    }

    public function updateAdminAgency(AdminUpdateAgencyRequest $request, Agency $agency): JsonResponse
    {
        $status = $request->validated('status');

        DB::transaction(function () use ($agency, $status): void {
            $agency->update(['status' => $status]);

            if ($status === 'suspended') {
                DB::table('sessions')
                    ->whereIn('user_id', $agency->users()->select('id'))
                    ->delete();
            }
        });

        return response()->json([
            'agency' => [
                'id' => $agency->id,
                'name' => $agency->name,
                'status' => $agency->status,
            ],
        ]);
    }
}
