<?php

namespace App\Notifications;

/** An agency owner or client asking an administrator for more hours, an upgrade or a renewal. */
class AgencyRequestNotice extends TicketNotification
{
    public static function type(): string
    {
        return 'assignment';
    }
}
