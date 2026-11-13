<?php

namespace App\Notifications;

class TicketCommented extends TicketNotification
{
    public static function type(): string
    {
        return 'comment';
    }
}
