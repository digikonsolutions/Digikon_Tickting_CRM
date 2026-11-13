<?php

namespace App\Services;

use App\Events\TicketEventRecorded;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TicketEventRecorder
{
    public function record(
        Ticket $ticket,
        string $type,
        array $payload = [],
        ?User $actor = null,
        string $visibility = 'public'
    ): TicketEvent {
        $actorName = $actor ? $actor->name : 'System';
        $actorRole = $actor ? ($actor->role?->value ?? (string) $actor->role) : 'system';

        $event = TicketEvent::create([
            'ticket_id' => $ticket->id,
            'work_item_id' => $ticket->work_item_id,
            'actor_id' => $actor?->id,
            'actor_name' => $actorName,
            'actor_role' => $actorRole,
            'type' => $type,
            'visibility' => $visibility,
            'payload' => $payload,
            'created_at' => now(),
        ]);

        DB::afterCommit(function () use ($event): void {
            event(new TicketEventRecorded($event));
        });

        return $event;
    }
}
