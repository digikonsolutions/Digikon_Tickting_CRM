<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\AgencyContract;
use App\Models\AuditLog;
use App\Models\EmployeeAssignment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates an agency together with its owner, company profile, contract and pre-assigned employees in ONE database
 * transaction. In "create" mode the owner is invited (a failed invitation rolls everything back); in "draft" mode
 * nothing is sent, the owner cannot sign in, and activate() finishes the job later.
 */
class AgencyOnboardingService
{
    private const OWNER_PROFILE_FIELDS = ['phone', 'whatsapp', 'job_title', 'preferred_contact_method', 'timezone'];

    private const AGENCY_PROFILE_FIELDS = ['legal_name', 'brand_name', 'website', 'industry', 'country', 'city', 'address', 'company_size', 'tax_number', 'billing_email', 'billing_contact_name', 'account_manager_id', 'internal_notes'];

    public function __construct(private UserAccountService $accounts, private AgencyBrandingService $branding) {}

    /**
     * @param  array<string, mixed>  $data  validated wizard payload (owner, agency, contract, setup, mode)
     * @return array{agency: Agency, owner: User, contract: AgencyContract|null, warnings: list<string>}
     */
    public function create(array $data, User $admin, ?UploadedFile $logo = null): array
    {
        $draft = ($data['mode'] ?? 'create') === 'draft';
        $setup = $data['setup'] ?? [];
        $sendInvite = ! $draft && (bool) ($setup['send_invite'] ?? true);
        $contractInput = self::contractInput($data['contract'] ?? null);
        $employeeIds = array_values(array_unique(array_map('intval', $setup['employee_ids'] ?? [])));
        $welcome = isset($setup['welcome_message']) ? trim((string) $setup['welcome_message']) : null;

        try {
            $result = DB::transaction(function () use ($data, $admin, $draft, $setup, $sendInvite, $contractInput, $employeeIds, $welcome): array {
                $snapshot = $contractInput === null ? null : $this->snapshot($contractInput);
                $this->reserveEmployees($employeeIds, $snapshot['employees'] ?? null);

                $agency = Agency::create([
                    'name' => $data['agency']['name'],
                    'slug' => filled($setup['slug'] ?? null) ? $setup['slug'] : null,
                    'ticket_prefix' => Agency::generatePrefix($data['agency']['name']),
                    'status' => $draft ? 'draft' : 'active',
                ]);
                $agency->profile()->create([
                    ...array_intersect_key($data['agency'], array_flip(self::AGENCY_PROFILE_FIELDS)),
                    'welcome_message' => $welcome ?: null,
                ]);

                $owner = User::create([
                    'name' => $data['owner']['name'],
                    'email' => $data['owner']['email'],
                    'password' => Str::random(64),
                    'role' => Role::AgencyOwner,
                    'agency_id' => $agency->id,
                    'is_active' => ! $draft,
                ]);
                $owner->ownerProfile()->create(array_intersect_key($data['owner'], array_flip(self::OWNER_PROFILE_FIELDS)));

                $brand = array_filter([
                    'primary_color' => $setup['brand_color'] ?? null, 'display_name' => $setup['display_name'] ?? null,
                    'tagline' => $setup['tagline'] ?? null, 'support_email' => $setup['support_email'] ?? null,
                ], fn ($value) => filled($value));
                if ($brand !== []) {
                    $this->branding->update($agency, $brand);
                }

                $contract = null;
                if ($snapshot !== null) {
                    $contract = AgencyContract::create([...$snapshot, 'agency_id' => $agency->id, 'created_by' => $admin->id]);
                    $contract->record('created', $admin, ['status' => $contract->status, 'plan_id' => $contract->plan_id, 'draft_agency' => $draft]);
                }

                foreach ($employeeIds as $employeeId) {
                    EmployeeAssignment::create([
                        'holder_id' => $owner->id,
                        'agency_id' => $agency->id,
                        'employee_id' => $employeeId,
                        'status' => 'active',
                        'source' => 'admin',
                        'active_employee_id' => $employeeId,
                        'started_at' => now(config('app.timezone')),
                    ]);
                }

                AuditLog::record($admin, $draft ? 'created_agency_draft' : 'created_agency', $agency);

                if ($sendInvite) {
                    $this->invite($owner, $welcome);
                }

                return ['agency' => $agency, 'owner' => $owner, 'contract' => $contract];
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two admins grabbed the same employee at once: the unique "one active holder" index caught it.
            throw ValidationException::withMessages(['setup.employee_ids' => 'One of these employees was just assigned elsewhere. Pick again.']);
        }

        $warnings = $this->warnings($result['contract'], $employeeIds, $draft, $sendInvite);

        // The logo goes to Cloudinary, which cannot be rolled back, so it runs after the commit. If it fails the
        // agency is kept and the admin is told.
        if ($logo !== null) {
            $this->branding->storeAsset($result['agency'], 'logo', $logo);
            if (! $this->branding->forAgency($result['agency'])->logo_url) {
                $warnings[] = 'The logo could not be uploaded. You can add it later.';
            }
        }

        return [...$result, 'warnings' => $warnings];
    }

    /**
     * Finishes a draft: validates what is required, switches the agency and its owner on, and sends the invitation.
     * If the invitation cannot be sent nothing changes.
     */
    public function activate(Agency $agency, User $admin): User
    {
        if ($agency->status !== 'draft') {
            throw ValidationException::withMessages(['agency' => 'Only a draft agency can be activated.']);
        }
        $owner = User::query()->where('agency_id', $agency->id)->where('role', Role::AgencyOwner->value)->orderBy('id')->first();
        $problems = [];
        if ($owner === null) {
            $problems['owner'] = 'This agency has no owner yet.';
        } elseif (blank($owner->email) || blank($owner->name)) {
            $problems['owner'] = 'The owner needs a name and an email address.';
        }
        if (blank($agency->name)) {
            $problems['agency'] = 'The agency needs a name.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        DB::transaction(function () use ($agency, $owner, $admin): void {
            $agency->update(['status' => 'active']);
            $owner->update(['is_active' => true]);

            $contract = AgencyContract::query()->where('agency_id', $agency->id)->orderByRaw('CASE WHEN live_agency_id IS NULL THEN 1 ELSE 0 END')->orderByDesc('id')->first();
            $contract?->record('agency_activated', $admin);
            AuditLog::record($admin, 'activated_agency', $agency);

            $this->invite($owner, $agency->profile?->welcome_message);
        });

        return $owner->refresh();
    }

    private function invite(User $owner, ?string $welcome): void
    {
        $status = $this->accounts->sendInvitation($owner, $welcome);
        if ($status !== Password::RESET_LINK_SENT) {
            throw new \RuntimeException('Unable to send the account invitation: '.$status);
        }
    }

    /**
     * The contract section, or null when the admin filled in nothing (a deliberate "no contract yet").
     *
     * @param  array<string, mixed>|null  $contract
     * @return array<string, mixed>|null
     */
    public static function contractInput(?array $contract): ?array
    {
        $filled = array_filter($contract ?? [], fn ($value) => $value !== null && $value !== '' && $value !== []);
        // Status and auto_renew have defaults, so they alone do not make a contract.
        $meaningful = array_diff_key($filled, array_flip(['status', 'auto_renew', 'currency', 'billing_cycle']));

        return $meaningful === [] ? null : $filled;
    }

    /**
     * Copies the plan's values onto the contract and lets the admin's own numbers win. The result is stored on the
     * contract, so later edits to the plan never reach it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function snapshot(array $input): array
    {
        $plan = ! empty($input['plan_id']) ? Plan::query()->find($input['plan_id']) : null;
        $values = [];
        foreach (Plan::SNAPSHOT_FIELDS as $field) {
            $values[$field] = array_key_exists($field, $input) && $input[$field] !== null ? $input[$field] : $plan?->{$field};
        }
        $status = $input['status'] ?? 'active';

        return [
            ...$values,
            'plan_id' => $plan?->id,
            'plan_name' => $plan?->name ?? 'Custom',
            'features' => isset($input['features']) && is_array($input['features']) ? array_values($input['features']) : ($plan?->features ?? []),
            'currency' => strtoupper((string) ($values['currency'] ?? 'USD')),
            'billing_cycle' => $values['billing_cycle'] ?? 'monthly',
            'status' => $status,
            'start_date' => $input['start_date'] ?? now(config('app.timezone'))->toDateString(),
            'end_date' => $input['end_date'] ?? null,
            'auto_renew' => (bool) ($input['auto_renew'] ?? false),
            'notice_period_days' => $input['notice_period_days'] ?? null,
            'trial_end_date' => $status === 'trial' ? ($input['trial_end_date'] ?? null) : null,
            'payment_terms_days' => $input['payment_terms_days'] ?? null,
            'discount_percent' => $input['discount_percent'] ?? null,
        ];
    }

    /**
     * Locks the chosen employees and checks them again inside the transaction: they must be active employees with
     * no active holder, and no more than the contract's seats.
     *
     * @param  list<int>  $ids
     */
    private function reserveEmployees(array $ids, int|string|null $seats): void
    {
        if ($ids === []) {
            return;
        }
        $employees = User::query()->whereIn('id', $ids)->where('role', Role::Employee->value)->where('is_active', true)->lockForUpdate()->get();
        if ($employees->count() !== count($ids)) {
            throw ValidationException::withMessages(['setup.employee_ids' => 'Choose active employees only.']);
        }
        $held = EmployeeAssignment::query()->where('status', 'active')->whereIn('employee_id', $ids)->lockForUpdate()->pluck('employee_id');
        if ($held->isNotEmpty()) {
            $names = $employees->whereIn('id', $held)->pluck('name')->implode(', ');
            throw ValidationException::withMessages(['setup.employee_ids' => "{$names} is already held by another agency."]);
        }
        if ($seats !== null && count($ids) > (int) $seats) {
            throw ValidationException::withMessages(['setup.employee_ids' => 'The contract allows '.(int) $seats.' employee'.((int) $seats === 1 ? '' : 's').', but '.count($ids).' were chosen.']);
        }
    }

    /**
     * @param  list<int>  $employeeIds
     * @return list<string>
     */
    private function warnings(?AgencyContract $contract, array $employeeIds, bool $draft, bool $sendInvite): array
    {
        $warnings = [];
        if ($contract === null) {
            $warnings[] = 'No contract added. The agency has no plan, seat or hour limits yet.';
        }
        if ($employeeIds === []) {
            $warnings[] = 'No employees assigned yet.';
        }
        if ($draft) {
            $warnings[] = 'Saved as a draft. The owner cannot sign in and no invitation was sent until you activate the agency.';
        } elseif (! $sendInvite) {
            $warnings[] = 'No invitation was sent. The owner can set a password with "Forgot password" on the sign-in page.';
        }

        return $warnings;
    }
}
