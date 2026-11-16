<?php

namespace App\Notifications;

class ProjectAssigned extends TicketNotification
{
    public static function type(): string
    {
        return 'project';
    }
}
