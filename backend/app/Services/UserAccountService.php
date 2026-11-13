<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class UserAccountService
{
    public function __construct(
        private AssignmentService $assignments,
        private ?TicketEventRecorder $recorder = null
    ) {
        $this->recorder ??= app(TicketEventRecorder::class);
    }

    /**
     * @param  array{name: string, email: string, role: string, agency_id?: int|null, agency_name?: string}  $data
     */
    public function createInvitedUser(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $agencyId = $data['agency_id'] ?? null;

            if (Role::from($data['role']) === Role::AgencyOwner) {
                $agency = Agency::create([
                    'name' => $data['agency_name'],
                    'ticket_prefix' => Agency::generatePrefix($data['agency_name']),
                ]);
                $agencyId = $agency->id;
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Str::random(64),
                'role' => Role::from($data['role']),
                'agency_id' => $agencyId,
                'is_active' => true,
            ]);

            $status = $this->sendInvitationLink($user);

            if ($status !== Password::RESET_LINK_SENT) {
                throw new \RuntimeException('Unable to send the account invitation: '.$status);
            }

            return $user;
        });

        return $user->loadMissing('agency');
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, agency_id: int|null, agency_name: string|null}
     */
    public function userPayload(User $user): array
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

    public function normalizeEmail(Request $request): void
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge(['email' => Str::lower(trim($email))]);
        }
    }

    public function findManagedAgencyUser(User $manager, int $userId): User
    {
        return User::query()->where('agency_id', $manager->agency_id)
            ->whereIn('role', $this->agencyManagedRoles())->findOrFail($userId);
    }

    public function findAdminManagedUser(int $userId): User
    {
        return User::query()->whereIn('role', $this->adminManagedRoles())->findOrFail($userId);
    }

    /**
     * @return list<string>
     */
    public function adminManagedRoles(): array
    {
        return [
            Role::Employee->value,
            Role::AgencyOwner->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateManagedUser(User $user, array $data, string $oldEmail, bool $passwordSetupEmailSent): void
    {
        DB::transaction(function () use ($user, $data, $oldEmail, $passwordSetupEmailSent): void {
            $user->fill($data)->save();

            if ($passwordSetupEmailSent) {
                $status = $this->sendInvitationLink($user);
                if ($status !== Password::RESET_LINK_SENT) {
                    throw new \RuntimeException('Unable to send the account invitation: '.$status);
                }

                DB::table(config('auth.passwords.users.table'))->where('email', $oldEmail)->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            if (array_key_exists('is_active', $data) && ! $user->is_active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $this->releaseHeldAssignments($user);

                if ($user->role === Role::Employee) {
                    EmployeeAssignment::query()
                        ->where('employee_id', $user->id)
                        ->where('status', 'active')
                        ->get()
                        ->each(fn (EmployeeAssignment $assignment) => $this->assignments->end($assignment));

                    $workItemIds = DB::table('work_items')
                        ->where('employee_id', $user->id)
                        ->where('status', '<>', 'completed')
                        ->pluck('id');

                    DB::table('work_items')
                        ->whereIn('id', $workItemIds)
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);

                    DB::table('work_item_allocations')
                        ->where('employee_id', $user->id)
                        ->whereDate('work_date', '>=', now()->toDateString())
                        ->delete();

                    $remainingTickets = Ticket::query()
                        ->whereIn('work_item_id', $workItemIds)
                        ->whereNotIn('status', ['done', 'cancelled'])
                        ->get();

                    app(TimeTracker::class)->stopForUser($user, 'employee_deactivated');
        foreach ($remainingTickets as $remainingTicket) {
                        $remainingTicket->update(['status' => 'cancelled', 'updated_at' => now()]);
                        $this->recorder->record($remainingTicket, 'cancelled', ['cause' => 'employee_deactivated'], null);
                    }
                }
            }
        });
    }

    public function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Free the employees this user holds (and release their booked hours) before the
            // assignment rows are removed by the foreign-key cascade.
            $this->releaseHeldAssignments($user);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table(config('auth.passwords.users.table'))->where('email', $user->email)->delete();
            $user->delete();
        });
    }

    /**
     * Assignments are only manageable by their holder (and admins), so an account that can no
     * longer act must not keep an employee held.
     */
    private function releaseHeldAssignments(User $holder): void
    {
        EmployeeAssignment::query()
            ->where('holder_id', $holder->id)
            ->where('status', 'active')
            ->get()
            ->each(fn (EmployeeAssignment $assignment) => $this->assignments->end($assignment));
    }

    /**
     * @return list<string>
     */
    public function agencyManagedRoles(): array
    {
        return [
            Role::AgencyMember->value,
            Role::AgencyClient->value,
        ];
    }

    /**
     * @return list<string>
     */
    public function agencyDirectoryRoles(): array
    {
        return [
            Role::AgencyOwner->value,
            ...$this->agencyManagedRoles(),
        ];
    }

    private function sendInvitationLink(User $user): string
    {
        return Password::broker()->sendResetLink(
            ['email' => $user->email],
            function (User $notifiable, string $token): string {
                $notifiable->notify(new SetPasswordNotification($token, true));

                return Password::RESET_LINK_SENT;
            }
        );
    }
}
