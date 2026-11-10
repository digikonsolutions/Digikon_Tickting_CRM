<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AdminCreateUserRequest;
use App\Http\Requests\AdminUpdateUserRequest;
use App\Http\Requests\AgencyCreateUserRequest;
use App\Http\Requests\AgencyUpdateProfileRequest;
use App\Http\Requests\AgencyUpdateUserRequest;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function createUser(AdminCreateUserRequest $request): JsonResponse
    {
        $user = $this->createInvitedUser($request->validated());

        return response()->json(['user' => $this->userPayload($user)], 201);
    }

    public function createAgencyUser(AgencyCreateUserRequest $request): JsonResponse
    {
        $creator = $request->user();
        $this->ensureActiveAgencyOwner($creator);

        $user = $this->createInvitedUser([
            ...$request->validated(),
            'agency_id' => $creator->agency_id,
        ]);

        return response()->json(['user' => $this->userPayload($user)], 201);
    }

    public function agencyUsers(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $this->ensureActiveAgencyOwner($viewer);
        $agencyRoles = $this->agencyManagedRoles();

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
                ...$this->userPayload($user),
                'is_active' => $user->is_active,
                'can_manage' => $user->agency_id === $viewer->agency_id
                    && in_array($user->role->value, $this->agencyManagedRoles(), true),
            ]);

        return response()->json(['users' => $users]);
    }

    public function updateAgencyUser(AgencyUpdateUserRequest $request, int $agencyUser): JsonResponse
    {
        $manager = $request->user();
        $this->ensureActiveAgencyOwner($manager);
        $user = $this->findManagedAgencyUser($manager, $agencyUser);
        $oldEmail = $user->email;
        $data = $request->validated();
        $passwordSetupEmailSent = isset($data['email']) && $data['email'] !== $oldEmail;

        $this->updateManagedUser($user, $data, $oldEmail, $passwordSetupEmailSent);

        return response()->json([
            'user' => [
                ...$this->userPayload($user),
                'is_active' => $user->is_active,
                'password_setup_email_sent' => $passwordSetupEmailSent,
            ],
        ]);
    }

    public function updateAdminUser(AdminUpdateUserRequest $request, int $adminUser): JsonResponse
    {
        $user = $this->findAdminManagedUser($adminUser);
        $oldEmail = $user->email;
        $data = $request->validated();
        $passwordSetupEmailSent = isset($data['email']) && $data['email'] !== $oldEmail;

        $this->updateManagedUser($user, $data, $oldEmail, $passwordSetupEmailSent);

        return response()->json([
            'user' => [
                ...$this->userPayload($user),
                'is_active' => $user->is_active,
                'password_setup_email_sent' => $passwordSetupEmailSent,
            ],
        ]);
    }

    public function deleteAdminUser(int $adminUser): JsonResponse
    {
        $user = $this->findAdminManagedUser($adminUser);
        $this->deleteUser($user);

        return response()->json(['message' => 'The account was permanently deleted.']);
    }

    public function deleteAgencyUser(Request $request, int $agencyUser): JsonResponse
    {
        $manager = $request->user();
        $this->ensureActiveAgencyOwner($manager);
        $user = $this->findManagedAgencyUser($manager, $agencyUser);

        $this->deleteUser($user);

        return response()->json(['message' => 'The account was permanently deleted.']);
    }

    public function adminUsers(Request $request): JsonResponse
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
            'users' => collect($users->items())->map(fn (User $user): array => [
                ...$this->userPayload($user),
                'is_active' => $user->is_active,
                'can_manage' => in_array($user->role->value, $this->adminManagedRoles(), true),
            ]),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function agencyDirectory(Request $request): JsonResponse
    {
        $member = $request->user();
        abort_unless($member->agency_id !== null && $member->agency?->status === 'active', 403);

        $users = User::query()
            ->whereKeyNot($member->id)
            ->where('email', '<>', $member->email)
            ->where(function ($query) use ($member): void {
                $query->where(function ($query) use ($member): void {
                    $query->where('agency_id', $member->agency_id)
                        ->whereIn('role', $this->agencyDirectoryRoles());
                })->orWhere(function ($query): void {
                    $query->where('role', Role::Employee->value)
                        ->whereNull('agency_id');
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user): array => [
                ...$this->userPayload($user),
                'is_active' => $user->is_active,
            ]);

        return response()->json(['users' => $users]);
    }

    public function agencyTeam(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless(
            in_array($viewer->role, [Role::AgencyOwner, Role::AgencyClient], true)
                && $viewer->agency_id !== null
                && $viewer->agency?->status === 'active',
            403
        );

        $employees = User::query()
            ->with('agency')
            ->where(function ($query) use ($viewer): void {
                $query->where(function ($query) use ($viewer): void {
                    $query->where('agency_id', $viewer->agency_id)
                        ->where('role', Role::Employee->value)
                        ->whereNull('agency_id');
                })->orWhere(function ($query): void {
                    $query->where('role', Role::Employee->value)
                        ->whereNull('agency_id');
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (User $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => $employee->role->value,
                'agency_name' => $employee->agency?->name ?? 'Shared employee',
                'is_active' => $employee->is_active,
            ]);

        return response()->json(['employees' => $employees]);
    }

    public function updateAgencyProfile(AgencyUpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->agency_id !== null && $user->agency?->status === 'active', 403);

        $user->update($request->validated());

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function updateProfile(AgencyUpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function login(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        if (! Auth::guard('web')->attempt($credentials)) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }

        $user = $request->user();
        $user->loadMissing('agency');

        if (! $user->is_active || ($user->agency && $user->agency->status !== 'active')) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    public function currentUser(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
        ]);

        Password::sendResetLink($data);

        return response()->json([
            'message' => 'If an account exists for that email, password reset instructions will be sent.',
        ], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                DB::table('sessions')->where('user_id', $user->getKey())->delete();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This password reset token is invalid or has expired.'], 422);
        }

        return response()->json(['message' => 'Your password has been reset.']);
    }

    /**
     * @param  array{name: string, email: string, role: string, agency_id?: int|null, agency_name?: string}  $data
     */
    private function createInvitedUser(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $agencyId = $data['agency_id'] ?? null;

            if (Role::from($data['role']) === Role::AgencyOwner) {
                $agencyId = Agency::create(['name' => $data['agency_name']])->id;
            }

            return User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Str::random(64),
                'role' => Role::from($data['role']),
                'agency_id' => $agencyId,
                'is_active' => true,
            ]);
        });

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw new \RuntimeException('Unable to send the account invitation: '.$status);
        }

        return $user->loadMissing('agency');
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, agency_id: int|null, agency_name: string|null}
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing('agency');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'agency_id' => $user->agency_id,
            'agency_name' => $user->agency?->name,
        ];
    }

    private function normalizeEmail(Request $request): void
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge(['email' => Str::lower(trim($email))]);
        }
    }

    private function ensureActiveAgencyOwner(User $user): void
    {
        abort_unless(
            $user->role === Role::AgencyOwner
                && $user->agency_id !== null
                && $user->agency?->status === 'active',
            403,
            'An active agency owner account is required to manage agency users.'
        );
    }

    private function findManagedAgencyUser(User $manager, int $userId): User
    {
        return User::query()->where('agency_id', $manager->agency_id)
            ->whereIn('role', $this->agencyManagedRoles())->findOrFail($userId);
    }

    private function findAdminManagedUser(int $userId): User
    {
        return User::query()->whereIn('role', $this->adminManagedRoles())->findOrFail($userId);
    }

    /**
     * @return list<string>
     */
    private function adminManagedRoles(): array
    {
        return [
            Role::Employee->value,
            Role::AgencyOwner->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateManagedUser(User $user, array $data, string $oldEmail, bool $passwordSetupEmailSent): void
    {
        DB::transaction(function () use ($user, $data, $oldEmail, $passwordSetupEmailSent): void {
            $user->fill($data)->save();

            if ($passwordSetupEmailSent) {
                $status = Password::sendResetLink(['email' => $user->email]);
                if ($status !== Password::RESET_LINK_SENT) {
                    throw new \RuntimeException('Unable to send the account invitation: '.$status);
                }

                DB::table(config('auth.passwords.users.table'))->where('email', $oldEmail)->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            if (array_key_exists('is_active', $data) && ! $user->is_active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });
    }

    private function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table(config('auth.passwords.users.table'))->where('email', $user->email)->delete();
            $user->delete();
        });
    }

    /**
     * @return list<string>
     */
    private function agencyManagedRoles(): array
    {
        return [
            Role::AgencyMember->value,
            Role::AgencyClient->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function agencyDirectoryRoles(): array
    {
        return [
            Role::AgencyOwner->value,
            ...$this->agencyManagedRoles(),
        ];
    }
}
