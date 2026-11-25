<?php

namespace App\Http\Controllers;

use App\Models\HoursRequest;
use App\Models\Ticket;
use App\Services\HoursRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * More hours on a ticket. The assigned employee asks and can withdraw; the agency's owner and clients answer. Every
 * lookup is scoped to the caller (their own ticket, their own agency); anything else is a 404.
 */
class HoursRequestController extends Controller
{
    public function store(Request $request, Ticket $ticket, HoursRequestService $service): JsonResponse
    {
        abort_if($ticket->usesLifecycle(), 404);
        $user = $request->user();
        abort_unless($ticket->workItem?->employee_id === $user->id, 404);
        $data = $request->validate([
            'hours' => ['required', 'numeric', 'decimal:0,2', 'min:0.25', 'max:'.config('scheduling.max_project_hours', 180)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        return response()->json(['hours_request' => $this->payload($service->request($ticket, $user, (float) $data['hours'], trim($data['reason'])))], 201);
    }

    public function cancel(Request $request, int $id, HoursRequestService $service): JsonResponse
    {
        $found = HoursRequest::query()->where('requested_by', $request->user()->id)->findOrFail($id);

        return response()->json(['hours_request' => $this->payload($service->cancel($found, $request->user()))]);
    }

    public function agencyIndex(Request $request): JsonResponse
    {
        $rows = HoursRequest::query()->with(['ticket.agency', 'requester'])
            ->where('agency_id', $request->user()->agency_id)->where('status', 'pending')
            ->latest('id')->limit(100)->get();

        return response()->json(['hours_requests' => $rows->map(fn (HoursRequest $row): array => $this->payload($row, withTicket: true))->values()]);
    }

    public function approve(Request $request, int $id, HoursRequestService $service): JsonResponse
    {
        $found = $this->inAgency($request, $id);

        return response()->json(['hours_request' => $this->payload($service->approve($found, $request->user()))]);
    }

    public function decline(Request $request, int $id, HoursRequestService $service): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['hours_request' => $this->payload($service->decline($this->inAgency($request, $id), $request->user(), $data['note'] ?? null))]);
    }

    private function inAgency(Request $request, int $id): HoursRequest
    {
        return HoursRequest::query()->where('agency_id', $request->user()->agency_id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function payload(HoursRequest $row, bool $withTicket = false): array
    {
        $payload = [
            'id' => $row->id,
            'ticket_id' => $row->ticket_id,
            'hours' => $row->hours,
            'reason' => $row->reason,
            'status' => $row->status,
            'decision_note' => $row->decision_note,
            'created_at' => $row->created_at?->toISOString(),
            'decided_at' => $row->decided_at?->toISOString(),
        ];
        if ($withTicket) {
            $row->loadMissing(['ticket.agency', 'requester']);
            $payload['ticket'] = ['id' => $row->ticket->id, 'key' => $row->ticket->key, 'title' => $row->ticket->title, 'estimated_hours' => $row->ticket->estimated_hours];
            $payload['requested_by'] = ['id' => $row->requester?->id, 'name' => $row->requester?->name];
        }

        return $payload;
    }
}
