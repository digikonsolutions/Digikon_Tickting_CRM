<?php

namespace App\Notifications;

class TicketAssigned extends TicketNotification
{
    public static function type(): string
    {
        return 'assigned';
    }
}
