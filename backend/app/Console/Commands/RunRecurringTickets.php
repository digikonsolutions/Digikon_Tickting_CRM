<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Support\SupportTicketService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunRecurringTickets extends Command
{
    protected $signature = 'tickets:run-recurring';

    protected $description = 'Create the tickets that agencies scheduled to repeat';

    public function handle(SupportTicketService $service): int
    {
        $today = CarbonImmutable::now(config('support.timezone'))->startOfDay();
        $made = 0;
        foreach (DB::table('recurring_ticket_schedules')->where('active', true)->where('next_run_on', '<=', $today->toDateString())->get() as $row) {
            $user = User::query()->find($row->created_by);
            if ($user === null || ! $user->is_active || $user->agency_id !== $row->agency_id) {
                continue;
            }
            try {
                $ticket = $service->create($user, ['type' => $row->type, 'title' => $row->title, 'description' => $row->description, 'expected_result' => $row->expected_result, 'details' => json_decode((string) $row->details, true) ?: [], 'end_client_id' => $row->end_client_id]);
                $next = CarbonImmutable::parse($row->next_run_on);
                do {
                    $next = $row->frequency === 'weekly' ? $next->addWeek() : $next->addMonthNoOverflow();
                } while ($next->lessThanOrEqualTo($today));
                DB::table('recurring_ticket_schedules')->where('id', $row->id)->update(['next_run_on' => $next->toDateString(), 'last_ticket_id' => $ticket->id, 'updated_at' => now()]);
                $made++;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("created: {$made}");

        return self::SUCCESS;
    }
}