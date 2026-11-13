<?php

namespace App\Services\Attachments;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\TicketAccess;
use Illuminate\Support\Collection;

/** JSON shape of attachments, shared by the attachment endpoints and the activity timeline. */
class AttachmentPresenter
{
    public function __construct(private AttachmentService $service, private TicketAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(TicketAttachment $attachment, ?User $viewer = null, ?Ticket $ticket = null): array
    {
        return [
            'id' => $attachment->id,
            'ticket_id' => $attachment->ticket_id,
            'comment_event_id' => $attachment->comment_event_id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'is_image' => $this->service->isInlineSafe($attachment),
            'is_internal' => $attachment->is_internal,
            'uploader' => ['id' => $attachment->uploaded_by, 'name' => $attachment->uploader_name],
            'created_at' => $attachment->created_at?->toISOString(),
            // The server decides who may delete, so the UI never has to work it out.
            'can_delete' => $viewer !== null && (
                $attachment->uploaded_by === $viewer->id
                || $viewer->role === Role::Admin
                || ($ticket !== null && $this->access->isAgencyOwner($viewer, $ticket))
            ),
        ];
    }

    /**
     * Attachments linked to comment events, keyed by event id. Internal ones are only included when the
     * viewer may see internal content (filtered in SQL).
     *
     * @param Collection<int, int> $eventIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function forEvents(Collection $eventIds, bool $canSeeInternal, ?User $viewer = null, ?Ticket $ticket = null): array
    {
        if ($eventIds->isEmpty()) {
            return [];
        }

        return TicketAttachment::query()
            ->whereIn('comment_event_id', $eventIds)
            ->when(! $canSeeInternal, fn ($query) => $query->where('is_internal', false))
            ->orderBy('id')
            ->get()
            ->groupBy('comment_event_id')
            ->map(fn (Collection $group): array => $group->map(fn (TicketAttachment $a): array => $this->payload($a, $viewer, $ticket))->all())
            ->all();
    }
}
