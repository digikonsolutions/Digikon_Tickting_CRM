<?php

namespace App\Notifications;

/** An employee's queue changed (reordered, or an urgent task was added). In-app by default. */
class QueueUpdated extends TicketNotification
{
    public static function type(): string
    {
        return 'queue';
    }
}
