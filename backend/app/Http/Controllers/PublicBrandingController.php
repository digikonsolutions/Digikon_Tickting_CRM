<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Services\AgencyBrandingPresenter;
use Illuminate\Http\JsonResponse;

/** Anonymous brand lookup for the portal login page. Unknown, draft and suspended agencies are indistinguishable. */
class PublicBrandingController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $agency = Agency::query()->with('branding')->where('slug', strtolower($slug))->where('status', 'active')->first();
        abort_if($agency === null, 404, 'Not found.');

        return response()->json(['branding' => AgencyBrandingPresenter::present($agency)]);
    }
}
