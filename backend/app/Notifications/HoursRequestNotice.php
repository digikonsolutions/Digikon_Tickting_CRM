<?php

namespace App\Notifications;

/** An employee asked for more hours on a ticket, or the agency answered. */
class HoursRequestNotice extends TicketNotification
{
    public static function type(): string
    {
        return 'hours';
    }
}
