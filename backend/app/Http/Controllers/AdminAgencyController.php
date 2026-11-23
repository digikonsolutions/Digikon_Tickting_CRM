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
