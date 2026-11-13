<?php

namespace App\Notifications;

class TicketRescheduled extends TicketNotification
{
    public static function type(): string
    {
        return 'rescheduled';
    }
}
