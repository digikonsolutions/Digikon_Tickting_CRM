<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened to a rush request. Notifications listen to this (see SendRushNotifications); the request
 * flow itself never depends on delivery.
 *
 * $name is one of: submitted, quoted, accepted, declined, expired, rejected, cancelled, fulfilled.
 */
class RushRequestEvent
{
    use Dispatchable;

    public function __construct(
        public int $rushRequestId,
        public string $name,
        public ?int $actorId = null,
    ) {}
}
