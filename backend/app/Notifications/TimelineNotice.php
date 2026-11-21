<?php

namespace App\Notifications;

/** A timeline review step: requested, received, pending, changes requested, confirmed, overdue, or released. */
class TimelineNotice extends TicketNotification
{
    public static function type(): string
    {
        return 'timeline';
    }
}
