<?php

namespace App\Notifications;

class TicketCancelled extends TicketNotification
{
    public static function type(): string
    {
        return 'cancelled';
    }
}
