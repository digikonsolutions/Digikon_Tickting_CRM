<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAgencyBrandingRequest;
use App\Http\Requests\UploadAgencyBrandingAssetRequest;
use App\Models\Agency;
use App\Services\AgencyBrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The agency is always the authenticated user's own (`agency.active` guarantees one); no request input
 * ever selects it.
 */
class AgencyBrandingController extends Controller
{
    public function __construct(private AgencyBrandingService $branding)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->agency($request));
    }

    public function update(UpdateAgencyBrandingRequest $request): JsonResponse
    {
        $agency = $this->agency($request);
        $this->branding->update($agency, $request->validated());

        return $this->respond($agency);
    }

    public function upload(UploadAgencyBrandingAssetRequest $request, string $kind): JsonResponse
    {
        $agency = $this->agency($request);
        $this->branding->storeAsset($agency, $kind, $request->file('file'));

        return $this->respond($agency);
    }

    public function destroyAsset(Request $request, string $kind): JsonResponse
    {
        $agency = $this->agency($request);
        $this->branding->removeAsset($agency, $kind);

        return $this->respond($agency);
    }

    public function reset(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $this->branding->reset($agency);

        return $this->respond($agency);
    }

    private function agency(Request $request): Agency
    {
        return $request->user()->agency;
    }

    private function respond(Agency $agency): JsonResponse
    {
        return response()->json(['branding' => $this->branding->payload($agency->unsetRelation('branding'))]);
    }
}
