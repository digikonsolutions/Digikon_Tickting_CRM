<?php

namespace App\Notifications;

class AssignmentHeld extends TicketNotification
{
    public static function type(): string
    {
        return 'assignment';
    }
}
