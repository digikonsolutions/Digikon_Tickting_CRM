<?php

namespace App\Support;

/**
 * The one place that knows both status vocabularies. Tickets made through the support module use the lifecycle
 * statuses; the older hour-slice tickets keep their legacy ones and are mapped for display, filters and KPIs.
 */
final class TicketStatus
{
    public const LIFECYCLE = ['new', 'triage', 'assigned', 'in_progress', 'waiting_agency', 'waiting_third_party', 'qa', 'resolved', 'closed', 'rejected'];

    public const LEGACY_MAP = [
        'todo' => 'assigned', 'in_progress' => 'in_progress', 'changes_requested' => 'in_progress', 'in_review' => 'qa',
        'blocked' => 'waiting_third_party', 'waiting_on_client' => 'waiting_agency', 'done' => 'closed', 'cancelled' => 'rejected',
    ];

    public const LABELS = [
        'new' => 'New', 'triage' => 'Triage', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'waiting_agency' => 'Waiting for agency',
        'waiting_third_party' => 'Waiting for third party', 'qa' => 'QA / testing', 'resolved' => 'Resolved', 'closed' => 'Closed', 'rejected' => 'Rejected / out of scope',
    ];

    /** Statuses in which the ticket is finished. */
    public const FINISHED = ['resolved', 'closed', 'rejected'];

    public static function lifecycle(string $status): string
    {
        return self::LEGACY_MAP[$status] ?? $status;
    }

    public static function label(string $status): string
    {
        return self::LABELS[self::lifecycle($status)] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function isOpen(string $status): bool
    {
        return ! in_array(self::lifecycle($status), ['resolved', 'closed', 'rejected'], true);
    }

    /** @return list<string> every stored status that maps to the given lifecycle status, for SQL filters */
    public static function stored(string $lifecycle): array
    {
        $stored = array_keys(array_filter(self::LEGACY_MAP, fn (string $to): bool => $to === $lifecycle));

        return in_array($lifecycle, self::LIFECYCLE, true) ? array_values(array_unique([...$stored, $lifecycle])) : [$lifecycle];
    }
}
