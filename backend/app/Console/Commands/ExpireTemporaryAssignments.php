<?php

namespace App\Console\Commands;

use App\Models\EmployeeAssignment;
use App\Services\AssignmentService;
use Illuminate\Console\Command;

/**
 * Every 5 minutes: temporary assignments (an extra team member bought through a rush request) whose time is up are
 * ended through AssignmentService, exactly like a manual release: open projects on it are closed, the employee is
 * freed, and the holder and the employee are notified.
 */
class ExpireTemporaryAssignments extends Command
{
    protected $signature = 'assignments:expire-temporary';

    protected $description = 'End temporary employee assignments that have reached their expiry';

    public function handle(AssignmentService $assignments): int
    {
        $ended = 0;
        EmployeeAssignment::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (EmployeeAssignment $assignment) use ($assignments, &$ended): void {
                $assignments->end($assignment);
                $ended++;
            });

        $this->info("Ended {$ended} temporary assignment(s).");

        return self::SUCCESS;
    }
}
