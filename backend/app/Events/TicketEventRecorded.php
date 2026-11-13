<?php

namespace App\Events;

use App\Models\TicketEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketEventRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(public TicketEvent $event) {}
}
