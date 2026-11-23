<?php

namespace App\Notifications;

/** Anything about an urgent request: submitted, quoted, accepted, declined, expired, rejected, fulfilled. */
class RushUpdated extends TicketNotification
{
    public static function type(): string
    {
        return 'rush';
    }
}
