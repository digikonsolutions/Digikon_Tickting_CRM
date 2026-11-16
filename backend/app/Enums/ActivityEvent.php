<?php

namespace App\Enums;

/** Event types the server writes to employee_activity_logs. Clients can never choose one. */
enum ActivityEvent: string
{
    case Login = 'login';
    case Logout = 'logout';
    case SessionStart = 'session_start';
    case SessionEnd = 'session_end';
    case TicketStatusChanged = 'ticket_status_changed';
    case TicketCommentAdded = 'ticket_comment_added';
    case ProjectStatusChanged = 'project_status_changed';
    case IdleDetected = 'idle_detected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
