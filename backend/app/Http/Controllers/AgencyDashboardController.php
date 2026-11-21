<?php

namespace App\Http\Controllers;

use App\Services\AgencyDashboardService;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Agency owner and client dashboards. Scoped to the caller's own agency (and, for a client, their own work). */
class AgencyDashboardController extends Controller
{
    public function show(Request $request, AgencyDashboardService $dashboard): JsonResponse
    {
        $user = $request->user()->loadMissing('agency');

        return response()->json(['dashboard' => $dashboard->payload($user)]);
    }

    /** "Request more hours", "Request upgrade" and "Request renewal": an in-app notification for every admin. */
    public function request(Request $request, WorkflowNotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['more_hours', 'upgrade', 'renewal'])],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $request->user()->loadMissing('agency');
        $notified = $notifications->agencyRequest($user->agency, $user, $data['type'], $data['message'] ?? null);

        return response()->json([
            'message' => 'Your request was sent. An administrator will get back to you.',
            'notified' => $notified,
        ], 201);
    }
}
