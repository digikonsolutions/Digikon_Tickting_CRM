<?php

namespace App\Notifications;

/** Feedback about an employee: new feedback, a low rating (admins) or the employee's reply. */
class FeedbackNotice extends TicketNotification
{
    public static function type(): string
    {
        return 'feedback';
    }
}
