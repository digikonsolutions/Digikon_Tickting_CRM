<?php

namespace App\Http\Controllers;

use App\Services\MonitoringPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** An employee reads the monitoring notice and accepts it. Never returns anything about recorded activity. */
class MonitoringConsentController extends Controller
{
    public function show(Request $request, MonitoringPolicy $policy): JsonResponse
    {
        return response()->json($this->payload($request, $policy));
    }

    public function accept(Request $request, MonitoringPolicy $policy): JsonResponse
    {
        // Accepting is per policy version; sending a different version than the current one is rejected.
        $request->validate(['policy_version' => ['required', 'string', 'in:'.$policy->version()]]);
        $policy->accept($request->user(), $request->ip());

        return response()->json($this->payload($request, $policy));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, MonitoringPolicy $policy): array
    {
        $consent = $policy->consentFor($request->user());

        return [
            'policy' => $policy->disclosure(),
            'consent' => [
                'accepted' => $consent !== null,
                'accepted_at' => $consent?->accepted_at?->toISOString(),
                'policy_version' => $consent?->policy_version,
            ],
        ];
    }
}
