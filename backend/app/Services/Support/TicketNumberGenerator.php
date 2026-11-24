<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\DB;

/** DG-1048: the next id from a counter row. Call it inside the transaction that creates the ticket. */
class TicketNumberGenerator
{
    public function next(): string
    {
        $row = DB::table('ticket_sequences')->where('name', 'tickets')->lockForUpdate()->first();
        $value = ((int) ($row->value ?? config('support.first_number', 1000))) + 1;
        DB::table('ticket_sequences')->updateOrInsert(['name' => 'tickets'], ['value' => $value]);

        return config('support.ticket_prefix', 'DG').'-'.$value;
    }
}
