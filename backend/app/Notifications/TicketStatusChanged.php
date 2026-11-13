<?php

namespace App\Notifications;

class TicketStatusChanged extends TicketNotification
{
    public static function type(): string
    {
        return 'status_changed';
    }
}
