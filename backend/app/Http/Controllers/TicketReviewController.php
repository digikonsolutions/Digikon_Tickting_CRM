<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Ticket;
use App\Services\TicketAccess;
use App\Services\TicketReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Review packages and the two reviews of a project ticket's work (our own check, then the agency). */
class TicketReviewController extends Controller
{
    public function __construct(private TicketReviews $reviews) {}

    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        $this->projectTicket($ticket);
        app(TicketAccess::class)->authorizeView($request->user(), $ticket);

        return response()->json(['submissions' => $this->reviews->thread($ticket, $request->user())]);
    }

    public function submit(Request $request, Ticket $ticket): JsonResponse
    {
        $this->projectTicket($ticket);
        $data = $request->validate([
            'summary' => ['required', 'string', 'min:10', 'max:5000'],
            'links' => ['required', 'array', 'min:1', 'max:10'],
            'links.*' => ['required', 'string', 'url:http,https', 'max:500'],
            'ghl_changes' => ['nullable', 'string', 'max:5000'],
            'test_steps' => ['required', 'string', 'min:5', 'max:5000'],
            'limitations' => ['nullable', 'string', 'max:3000'],
            'access_note' => ['nullable', 'string', 'max:1000'],
            'time_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $submission = $this->reviews->submit($ticket, $request->user(), $data);

        return response()->json(['submission' => $submission->only(['id', 'version']), 'ticket_status' => $ticket->fresh()->status], 201);
    }

    public function review(Request $request, Ticket $ticket): JsonResponse
    {
        $this->projectTicket($ticket);
        $user = $request->user();
        $data = $request->validate([
            'stage' => ['required', Rule::in(['internal', 'agency'])],
            'decision' => ['required', Rule::in(['approved', 'changes_requested'])],
            'feedback' => ['nullable', 'string', 'max:5000'],
            'items' => ['nullable', 'array', 'max:20'],
            'items.*.point' => ['required', 'string', 'max:300'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
        ]);
        $items = $data['items'] ?? [];

        if ($data['stage'] === 'internal') {
            abort_unless($user->role === Role::Admin, 403);
            $review = $this->reviews->internalReview($ticket, $user, $data['decision'], $data['feedback'] ?? null, $items);
        } else {
            abort_unless(app(TicketAccess::class)->isAgencyParticipant($user, $ticket), 403);
            $review = $this->reviews->agencyReview($ticket, $user, $data['decision'], $data['feedback'] ?? null, $items);
        }

        return response()->json(['review' => $review->only(['id', 'stage', 'decision']), 'ticket_status' => $ticket->fresh()->status], 201);
    }

    private function projectTicket(Ticket $ticket): void
    {
        abort_if($ticket->work_item_id === null, 404);
    }
}