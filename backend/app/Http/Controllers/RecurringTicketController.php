<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupportTicketRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Tickets an agency wants created for it on a schedule (a weekly health check, a monthly report). */
class RecurringTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['schedules' => DB::table('recurring_ticket_schedules')->where('agency_id', $request->user()->agency_id)->orderBy('next_run_on')
            ->get(['id', 'type', 'title', 'frequency', 'next_run_on', 'active', 'last_ticket_id'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        // The same rules as a normal ticket decide what the schedule must contain.
        $rules = collect(StoreSupportTicketRequest::rulesFor((string) $request->input('type'), $user->agency_id))
            ->filter(fn ($rule, string $key): bool => in_array($key, ['type', 'title', 'description', 'expected_result', 'end_client_id', 'details'], true) || str_starts_with($key, 'details.'))->all();
        $data = $request->validate($rules + ['frequency' => ['required', Rule::in(['weekly', 'monthly'])], 'start_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        $id = DB::table('recurring_ticket_schedules')->insertGetId([
            'agency_id' => $user->agency_id, 'created_by' => $user->id, 'type' => $data['type'], 'title' => $data['title'], 'description' => $data['description'], 'expected_result' => $data['expected_result'],
            'details' => json_encode($data['details'] ?? []), 'end_client_id' => $data['end_client_id'] ?? null, 'frequency' => $data['frequency'], 'next_run_on' => $data['start_on'], 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function destroy(Request $request, int $schedule): JsonResponse
    {
        $deleted = DB::table('recurring_ticket_schedules')->where('id', $schedule)->where('agency_id', $request->user()->agency_id)->delete();
        abort_if($deleted === 0, 404);

        return response()->json(['message' => 'Deleted.']);
    }
}