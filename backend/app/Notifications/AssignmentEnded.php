<?php

namespace App\Notifications;

class AssignmentEnded extends TicketNotification
{
    public static function type(): string
    {
        return 'assignment';
    }
}
