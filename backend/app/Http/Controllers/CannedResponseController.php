<?php

namespace App\Http\Controllers;

use App\Models\CannedResponse;
use App\Services\Support\StaffCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Saved replies. Anyone on the support side can use them; triage, managers and admins write them. */
class CannedResponseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(StaffCapability::any($request->user()), 403);

        return response()->json(['responses' => CannedResponse::query()->orderBy('title')->get(['id', 'title', 'body'])]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(StaffCapability::manages($request->user()), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:5000']]);

        return response()->json(['response' => CannedResponse::create($data + ['created_by' => $request->user()->id])->only(['id', 'title', 'body'])], 201);
    }

    public function update(Request $request, CannedResponse $response): JsonResponse
    {
        abort_unless(StaffCapability::manages($request->user()), 403);
        $response->update($request->validate(['title' => ['sometimes', 'string', 'max:120'], 'body' => ['sometimes', 'string', 'max:5000']]));

        return response()->json(['response' => $response->only(['id', 'title', 'body'])]);
    }

    public function destroy(Request $request, CannedResponse $response): JsonResponse
    {
        abort_unless(StaffCapability::manages($request->user()), 403);
        $response->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
