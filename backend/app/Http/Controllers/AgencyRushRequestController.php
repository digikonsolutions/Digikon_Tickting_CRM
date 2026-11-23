<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\RushRequest;
use App\Models\User;
use App\Services\RushPresenter;
use App\Services\RushRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An agency's rush requests. Owners see every request of their agency and are the only ones who accept or decline a
 * quote; clients raise requests and see only their own, without prices.
 */
class AgencyRushRequestController extends Controller
{
    public function index(Request $request, RushPresenter $presenter): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate(['status' => ['sometimes', Rule::in(['open', 'pending', 'quoted', 'accepted', 'rejected', 'cancelled', 'fulfilled', 'expired'])]]);

        $requests = RushRequest::query()
            ->with(['requester', 'assignment.employee'])
            ->where('agency_id', $user->agency_id)
            ->when($user->role === Role::AgencyClient, fn ($query) => $query->where('requested_by', $user->id))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $status === 'open'
                ? $query->whereIn('status', RushRequest::OPEN)
                : $query->where('status', $status))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['rush_requests' => $requests->map(fn (RushRequest $r): array => $presenter->forAgency($r, $user))->values()]);
    }

    public function store(Request $request, RushRequestService $service, RushPresenter $presenter): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'type' => ['required', Rule::in(RushRequest::TYPES)],
            'assignment_id' => ['required_if:type,priority_insert', 'nullable', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'skills_notes' => ['nullable', 'string', 'max:2000'],
            'estimated_hours' => ['required', 'numeric', 'decimal:0,2', 'min:0.25', 'max:'.config('scheduling.max_project_hours', 180)],
            'needed_by' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addYear()->toDateString()],
            'reason' => ['nullable', 'string', 'max:1000'],
            'agency_id' => ['prohibited'],
            'status' => ['prohibited'],
        ]);

        $rush = $service->submit($user, $data);

        return response()->json(['rush_request' => $presenter->forAgency($rush->load(['requester', 'assignment.employee']), $user)], 201);
    }

    public function show(Request $request, int $id, RushRequestService $service, RushPresenter $presenter): JsonResponse
    {
        $user = $request->user();
        $rush = $this->find($user, $id);
        $impact = null;
        if ($user->role === Role::AgencyOwner && $rush->status === 'quoted' && $rush->type === 'priority_insert') {
            $impact = $service->impact($rush);
        }

        return response()->json(['rush_request' => $presenter->forAgency($rush, $user, $impact)]);
    }

    public function cancel(Request $request, int $id, RushRequestService $service, RushPresenter $presenter): JsonResponse
    {
        $user = $request->user();
        $rush = $service->cancel($this->find($user, $id), $user);

        return response()->json(['rush_request' => $presenter->forAgency($rush, $user)]);
    }

    public function accept(Request $request, int $id, RushRequestService $service, RushPresenter $presenter): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['confirm_impact' => ['sometimes', 'boolean']]);
        $rush = $service->accept($this->find($user, $id), $user, (bool) ($data['confirm_impact'] ?? false));

        return response()->json(['rush_request' => $presenter->forAgency($rush, $user)]);
    }

    public function decline(Request $request, int $id, RushRequestService $service, RushPresenter $presenter): JsonResponse
    {
        $user = $request->user();
        $rush = $service->cancel($this->find($user, $id), $user, declined: true);

        return response()->json(['rush_request' => $presenter->forAgency($rush, $user)]);
    }

    private function find(User $user, int $id): RushRequest
    {
        return RushRequest::query()
            ->where('agency_id', $user->agency_id)
            ->when($user->role === Role::AgencyClient, fn ($query) => $query->where('requested_by', $user->id))
            ->findOrFail($id);
    }
}
