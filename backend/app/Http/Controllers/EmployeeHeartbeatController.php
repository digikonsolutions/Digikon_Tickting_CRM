<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeHeartbeatController extends Controller
{
    /**
     * Sent about once a minute by the employee's open, visible tab. The only client input is whether the
     * tab saw no interaction for the idle period; the server measures time itself and returns only the
     * polling settings, never any activity history.
     */
    public function store(Request $request, ActivityLogger $activity): JsonResponse
    {
        $data = $request->validate(['idle' => ['sometimes', 'boolean']]);
        $activity->heartbeat($request->user(), (bool) ($data['idle'] ?? false));

        return response()->json([
            'interval_seconds' => 60,
            'idle_after_minutes' => (int) config('activity.idle_after_minutes'),
        ]);
    }
}
