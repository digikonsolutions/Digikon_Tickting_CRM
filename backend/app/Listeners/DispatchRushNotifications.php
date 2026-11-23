<?php

namespace App\Listeners;

use App\Events\RushRequestEvent;
use App\Jobs\NotifyRushRequest;

/** Hands every rush request event to the queue; delivery never blocks or fails the request. */
class DispatchRushNotifications
{
    public function handle(RushRequestEvent $event): void
    {
        NotifyRushRequest::dispatch($event->rushRequestId, $event->name, $event->actorId);
    }
}
