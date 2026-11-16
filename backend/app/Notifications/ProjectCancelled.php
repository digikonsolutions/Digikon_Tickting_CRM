<?php

namespace App\Notifications;

class ProjectCancelled extends TicketNotification
{
    public static function type(): string
    {
        return 'project';
    }
}
