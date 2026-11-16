<?php

namespace App\Notifications;

class ProjectStatusChanged extends TicketNotification
{
    public static function type(): string
    {
        return 'project';
    }
}
