<?php

namespace App\Notifications;

class EmployeeDeactivatedNotice extends TicketNotification
{
    public static function type(): string
    {
        return 'assignment';
    }
}
