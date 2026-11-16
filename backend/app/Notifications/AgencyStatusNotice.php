<?php

namespace App\Notifications;

class AgencyStatusNotice extends TicketNotification
{
    public static function type(): string
    {
        return 'assignment';
    }
}
