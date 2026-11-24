<?php

namespace App\Services\Support;

use App\Models\Ticket;

/**
 * The seam for AI help (reply suggestions, summaries, duplicate detection). Nothing calls out in this release: the bound
 * implementation returns nothing, so no ticket text ever leaves the system.
 */
interface TicketAssistant
{
    public function suggestReply(Ticket $ticket): ?string;

    public function summarize(Ticket $ticket): ?string;
}