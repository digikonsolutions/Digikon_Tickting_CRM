<?php

namespace App\Notifications;

class TicketNeedsYourInput extends TicketNotification
{
    public static function type(): string
    {
        return 'needs_input';
    }
}
