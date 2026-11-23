<?php

namespace App\Http\Controllers;

use App\Models\AgencyCharge;
use App\Models\RushRequest;
use App\Services\RushPresenter;
use App\Services\RushRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin side of rush requests: review, quote or reject, and the charge ledger. */
class AdminRushRequestController extends Controller
{
    public function index(Request $request, RushPresenter $presenter): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'pending', 'quoted', 'accepted', 'rejected', 'cancelled', 'fulfilled', 'expired'])],
            'type' => ['sometimes', Rule::in(RushRequest::TYPES)],
            'agency_id' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = RushRequest::query()
            ->when($filters['status'] ?? null, fn ($q, string $status) => $status === 'open' ? $q->whereIn('status', RushRequest::OPEN) : $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->when($filters['agency_id'] ?? null, fn ($q, int $agency) => $q->where('agency_id', $agency));

        $page = (clone $query)->with(['agency', 'requester', 'assignment.employee', 'proposedEmployee', 'quoter', 'charge'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'quoted' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'rush_requests' => collect($page->items())->map(fn (RushRequest $r): array => $presenter->forAdmin($r))->values(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'counts' => [
                'pending' => RushRequest::query()->where('status', 'pending')->count(),
                'quoted' => RushRequest::query()->where('status', 'quoted')->count(),
            ],
        ]);
    }

    public function quote(Request $request, int $id, RushRequestService $service, RushPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1', 'max:'.config('billing.max_quote_minor')],
            'valid_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:720'],
            'note' => ['nullable', 'string', 'max:1000'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['rush_request' => $presenter->forAdmin($service->quote(RushRequest::query()->findOrFail($id), $request->user(), $data))]);
    }

    public function reject(Request $request, int $id, RushRequestService $service, RushPresenter $presenter): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['rush_request' => $presenter->forAdmin($service->reject(RushRequest::query()->findOrFail($id), $request->user(), $data['reason']))]);
    }

    public function charges(Request $request, RushPresenter $presenter): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['unbilled', 'invoiced', 'paid', 'void'])],
            'agency_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AgencyCharge::query()
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['agency_id'] ?? null, fn ($q, int $agency) => $q->where('agency_id', $agency))
            ->when($filters['from'] ?? null, fn ($q, string $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, string $to) => $q->whereDate('created_at', '<=', $to));

        $totals = (clone $query)->selectRaw('currency, status, SUM(amount_minor) AS amount_minor, COUNT(*) AS charges')->groupBy('currency', 'status')->get();
        $page = $query->with('agency')->orderByDesc('id')->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'charges' => collect($page->items())->map(fn (AgencyCharge $c): array => $presenter->charge($c))->values(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'totals' => $totals->map(fn ($row): array => [
                'currency' => $row->currency,
                'status' => $row->status,
                'amount_minor' => (int) $row->amount_minor,
                'amount_display' => RushPresenter::money((int) $row->amount_minor, $row->currency),
                'charges' => (int) $row->charges,
            ])->values(),
        ]);
    }
}
