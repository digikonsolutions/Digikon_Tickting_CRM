<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Services\AgencyContracts;
use App\Services\AgencyInsights;
use App\Services\AgencyBrandingService;
use App\Services\AgencyOnboardingService;
use App\Models\ContractDocument;
use App\Services\UserAccountService;
use Illuminate\Validation\ValidationException;
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
            'slug' => $agency->slug,
            'portal_path' => '/a/'.$agency->slug,
            'status' => $agency->status,
            'ticket_prefix' => $agency->ticket_prefix,
            'created_at' => $agency->created_at?->toISOString(),
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'is_active' => $owner->is_active] : null,
            'contract' => $contract === null ? null : [...$contract, 'days_remaining' => AgencyContracts::daysRemaining($contract)],
            'limits' => $limits,
            'contract_form' => $this->contractForm($agency),
            'branding' => \App\Services\AgencyBrandingPresenter::present($agency->loadMissing('branding')),
            'counts' => $insights->counts($agency->id),
            'usage' => $usage,
            'profile' => $this->profilePayload($agency),
            'documents' => ContractDocument::query()->where('agency_id', $agency->id)->latest('id')->get()->map(fn (ContractDocument $d): array => ContractDocumentController::payload($d))->values(),
            'employees' => $insights->heldEmployees($agency->id),
            'projects' => $insights->recentProjects($agency->id, null, 10),
        ]]);
    }

    /** Activates a draft agency and its owner and sends the invitation. */
    public function activate(Request $request, Agency $agency, AgencyOnboardingService $onboarding, UserAccountService $accounts): JsonResponse
    {
        $owner = $onboarding->activate($agency, $request->user());

        return response()->json([
            'agency' => ['id' => $agency->id, 'name' => $agency->name, 'status' => $agency->refresh()->status],
            'owner' => [...$accounts->userPayload($owner), 'is_active' => $owner->is_active],
        ]);
    }

    /** Sets the agency's logo through the existing Cloudinary-backed white-label settings. */
    /**
     * Admin edit of an agency's brand, including its portal slug. Changing the slug changes the portal address: links in
     * emails already sent (set-password, invitations) point at the old slug and stop working.
     */
    public function updateBranding(Request $request, Agency $agency, AgencyBrandingService $branding): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['sometimes', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'not_in:'.implode(',', Agency::RESERVED_SLUGS), \Illuminate\Validation\Rule::unique('agencies', 'slug')->ignore($agency->id)],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:140'],
            'primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'support_email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'email_from_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        if (isset($data['primary_color']) && ! \App\Support\ColorContrast::passesOnWhite($data['primary_color'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['primary_color' => 'This colour is too light for text on white (4.5:1 needed). Pick a darker shade.']);
        }
        if (isset($data['slug'])) {
            $agency->update(['slug' => $data['slug']]);
            unset($data['slug']);
        }
        $branding->update($agency, array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $data));

        return response()->json(['branding' => \App\Services\AgencyBrandingPresenter::present($agency->fresh('branding'))]);
    }

    public function uploadLogo(Request $request, Agency $agency, AgencyBrandingService $branding): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $request->file('file')->getRealPath());
        if (! in_array($sniffed, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw ValidationException::withMessages(['file' => 'The file content is not a PNG, JPG or WEBP image.']);
        }
        $record = $branding->storeAsset($agency, 'logo', $request->file('file'));
        if (! $record->logo_url) {
            throw ValidationException::withMessages(['file' => 'The logo could not be uploaded. Try again.']);
        }

        return response()->json(['logo_url' => $record->logo_url]);
    }

    /** The contract the agency page shows (live one first, else the latest), the same pick AgencyContracts makes. */
    private function currentContract(Agency $agency): ?\App\Models\AgencyContract
    {
        return \App\Models\AgencyContract::query()->where('agency_id', $agency->id)
            ->orderByRaw('CASE WHEN live_agency_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('start_date')->orderByDesc('id')->first();
    }

    /** Raw contract values for the edit form (the page itself only gets the presented, display-ready ones). */
    private function contractForm(Agency $agency): ?array
    {
        $contract = $this->currentContract($agency);

        return $contract === null ? null : [
            ...$contract->only(['plan_id', 'status', 'employees', 'max_open_projects', 'max_client_accounts', 'currency', 'billing_cycle', 'notice_period_days', 'payment_terms_days']),
            'hours_per_month' => $contract->hours_per_month !== null ? (float) $contract->hours_per_month : null,
            'price' => $contract->price !== null ? (float) $contract->price : null,
            'overage_rate' => $contract->overage_rate !== null ? (float) $contract->overage_rate : null,
            'discount_percent' => $contract->discount_percent !== null ? (float) $contract->discount_percent : null,
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'trial_end_date' => $contract->trial_end_date?->toDateString(),
            'auto_renew' => (bool) $contract->auto_renew,
            'features' => $contract->features ?? [],
        ];
    }

    /**
     * Creates the agency's contract, or edits the current one in place. Only the fields sent change; an empty one clears.
     * Seats cannot drop below the employees the agency already holds.
     */
    public function updateContract(\App\Http\Requests\AdminUpdateAgencyContractRequest $request, Agency $agency, AgencyContracts $contracts): JsonResponse
    {
        $data = $request->validated();
        $contract = $this->currentContract($agency);

        $status = $data['status'] ?? $contract?->status ?? 'active';
        $seats = array_key_exists('employees', $data) ? $data['employees'] : $contract?->employees;
        $held = \App\Models\EmployeeAssignment::query()->where('agency_id', $agency->id)->where('status', 'active')->count();
        if ($seats !== null && in_array($status, ['trial', 'active', 'paused'], true) && $held > (int) $seats) {
            throw ValidationException::withMessages(['employees' => "The agency holds {$held} employee".($held === 1 ? '' : 's').'. Release some first, or allow at least that many seats.']);
        }

        $plan = ! empty($data['plan_id']) ? \App\Models\Plan::query()->find($data['plan_id']) : null;
        if (array_key_exists('plan_id', $data)) {
            $data['plan_name'] = $plan?->name ?? 'Custom';
        }
        if (($data['status'] ?? $status) !== 'trial' && $status !== 'trial') {
            $data['trial_end_date'] = null;
        }
        if (array_key_exists('currency', $data) && $data['currency'] === null) {
            unset($data['currency']);
        }

        $admin = $request->user();
        \Illuminate\Support\Facades\DB::transaction(function () use (&$contract, $agency, $data, $admin): void {
            if ($contract === null) {
                $contract = \App\Models\AgencyContract::create([
                    'plan_name' => 'Custom',
                    'currency' => 'USD',
                    'billing_cycle' => 'monthly',
                    'status' => 'active',
                    'start_date' => now(config('app.timezone'))->toDateString(),
                    ...$data,
                    'agency_id' => $agency->id,
                    'created_by' => $admin->id,
                ]);
                $contract->record('created', $admin, ['status' => $contract->status, 'plan_id' => $contract->plan_id]);
            } else {
                $contract->fill($data)->save();
                $contract->record('updated', $admin, ['fields' => array_keys($data), 'status' => $contract->status]);
            }
            \App\Models\AuditLog::record($admin, 'updated_agency_contract', $agency);
        });

        $presented = $contracts->forAgency($agency->id);

        return response()->json([
            'contract' => $presented === null ? null : [...$presented, 'days_remaining' => AgencyContracts::daysRemaining($presented)],
            'contract_form' => $this->contractForm($agency),
        ]);
    }

    /** Gives the agency an employee nobody holds, on behalf of its owner. Seats and availability are checked like the owner's own flow. */
    public function assignEmployee(Request $request, Agency $agency, AgencyContracts $contracts): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'integer', 'exists:users,id']]);
        $owner = User::query()->where('agency_id', $agency->id)->where('role', Role::AgencyOwner->value)->orderBy('id')->first();
        if ($owner === null) {
            throw ValidationException::withMessages(['employee_id' => 'This agency has no owner yet, so it cannot hold employees.']);
        }

        try {
            $assignment = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $agency, $owner, $contracts, $request): \App\Models\EmployeeAssignment {
                $employee = User::query()->lockForUpdate()->findOrFail($data['employee_id']);
                if (! $employee->is_active || $employee->role !== Role::Employee) {
                    throw ValidationException::withMessages(['employee_id' => 'Choose an active employee.']);
                }
                if (\App\Models\EmployeeAssignment::query()->where('active_employee_id', $employee->id)->exists()) {
                    throw ValidationException::withMessages(['employee_id' => "{$employee->name} is already held by an agency."]);
                }
                $seats = AgencyContracts::limits($contracts->forAgency($agency->id))['seats'];
                $held = \App\Models\EmployeeAssignment::query()->where('agency_id', $agency->id)->where('status', 'active')->count();
                if ($seats !== null && $held >= $seats) {
                    throw ValidationException::withMessages(['employee_id' => "The contract allows {$seats} employee".($seats === 1 ? '' : 's').' and all seats are used. Raise the seats in the contract first.']);
                }

                $assignment = \App\Models\EmployeeAssignment::create([
                    'holder_id' => $owner->id,
                    'agency_id' => $agency->id,
                    'employee_id' => $employee->id,
                    'status' => 'active',
                    'source' => 'admin',
                    'active_employee_id' => $employee->id,
                    'started_at' => now(config('app.timezone')),
                ]);
                \App\Models\AuditLog::record($request->user(), 'assigned_employee', $agency);

                return $assignment;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['employee_id' => 'This employee was just assigned elsewhere. Pick again.']);
        }

        return response()->json(['assignment' => ['id' => $assignment->id, 'employee_id' => $assignment->employee_id]], 201);
    }

    /**
     * Edit the agency's name and company details. Only the fields sent change; an empty value clears an optional one.
     * A blank tax number leaves the stored one alone (the page only ever sees it masked). Slug and ticket prefix never change here.
     */
    public function updateDetails(\App\Http\Requests\AdminUpdateAgencyDetailsRequest $request, Agency $agency): JsonResponse
    {
        $data = $request->validated();
        if (blank($data['tax_number'] ?? null)) {
            unset($data['tax_number']);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($agency, $data): void {
            if (array_key_exists('name', $data)) {
                $agency->update(['name' => $data['name']]);
            }
            $profile = array_diff_key($data, ['name' => true]);
            if ($profile !== []) {
                $agency->profile()->updateOrCreate([], $profile);
            }
        });

        return response()->json(['agency' => [
            'id' => $agency->id,
            'name' => $agency->name,
            'profile' => $this->profilePayload($agency->fresh('profile.accountManager')),
        ]]);
    }

    /** Company details for the admin page. The tax number is masked and internal notes are for admins only (this route). */
    private function profilePayload(Agency $agency): array
    {
        $profile = $agency->profile;
        $manager = $profile?->accountManager;

        return [
            'legal_name' => $profile?->legal_name,
            'brand_name' => $profile?->brand_name,
            'website' => $profile?->website,
            'industry' => $profile?->industry,
            'country' => $profile?->country,
            'city' => $profile?->city,
            'address' => $profile?->address,
            'company_size' => $profile?->company_size,
            'tax_number_masked' => $profile?->taxNumberMasked(),
            'billing_email' => $profile?->billing_email,
            'billing_contact_name' => $profile?->billing_contact_name,
            'account_manager' => $manager ? ['id' => $manager->id, 'name' => $manager->name] : null,
            'internal_notes' => $profile?->internal_notes,
        ];
    }
}
