<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\RushRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\EmployeeQueuePlanner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Local demo only: on top of WhiteLabelDemoSeeder, Northwind holds three employees with busy queues (one has a ticket
 * in progress), Sunrise holds one, one employee is left unassigned for the quote step, and Northwind's client has a
 * pending urgent request. The password comes from DEMO_PASSWORD and the seeder does nothing without it or outside the
 * local environment. Safe to run twice. Run it with:
 *
 *   php artisan db:seed --class=QueueDemoSeeder
 */
class QueueDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || blank(env('DEMO_PASSWORD'))) {
            $this->command?->warn('QueueDemoSeeder skipped: it needs the local environment and DEMO_PASSWORD.');

            return;
        }
        $this->call(WhiteLabelDemoSeeder::class);

        $planner = app(EmployeeQueuePlanner::class);
        $northwind = Agency::query()->where('slug', 'northwind')->firstOrFail();
        $sunrise = Agency::query()->where('slug', 'sunrise')->firstOrFail();
        $owner = User::query()->where('email', 'owner@northwind.test')->firstOrFail();
        $client = User::query()->where('email', 'client@northwind.test')->firstOrFail();
        $sunriseOwner = User::query()->where('email', 'owner@sunrise.test')->firstOrFail();

        $plans = [
            [$northwind, $owner, 'queue.ava@example.test', 'Queue Demo Ava', [['Homepage redesign', 18, $owner], ['Blog setup', 12, $client], ['Newsletter template', 6, $owner]]],
            [$northwind, $owner, 'queue.ben@example.test', 'Queue Demo Ben', [['Checkout audit', 10, $owner], ['Landing page copy', 8, $client]]],
            [$northwind, $owner, 'queue.cleo@example.test', 'Queue Demo Cleo', [['Brand guidelines', 30, $owner]]],
            [$sunrise, $sunriseOwner, 'queue.dan@example.test', 'Queue Demo Dan', [['Spring campaign', 14, $sunriseOwner]]],
        ];

        $firstAssignment = null;
        foreach ($plans as [$agency, $holder, $email, $name, $projects]) {
            $employee = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => env('DEMO_PASSWORD'), 'role' => Role::Employee, 'is_active' => true]);
            $assignment = EmployeeAssignment::query()->where('employee_id', $employee->id)->where('status', 'active')->first()
                ?? EmployeeAssignment::create([
                    'holder_id' => $holder->id, 'agency_id' => $agency->id, 'employee_id' => $employee->id, 'status' => 'active',
                    'source' => 'owner', 'active_employee_id' => $employee->id, 'started_at' => now(),
                ]);
            $firstAssignment ??= $assignment;

            foreach ($projects as [$title, $hours, $creator]) {
                if (WorkItem::query()->where('assignment_id', $assignment->id)->where('title', $title)->exists()) {
                    continue;
                }
                WorkItem::create([
                    'agency_id' => $agency->id, 'client_id' => $creator->id, 'assignment_id' => $assignment->id, 'employee_id' => $employee->id,
                    'title' => $title, 'description' => 'Demo project.', 'estimated_hours' => $hours,
                    'start_date' => CarbonImmutable::now()->toDateString(), 'due_date' => CarbonImmutable::now()->addDays(28)->toDateString(),
                    'status' => 'scheduled', 'queue_position' => $planner->nextPosition($employee),
                ]);
                $planner->replan($employee);
            }
        }

        // Ava has started her first project: a ticket in progress is "now" on the queue.
        $ava = User::query()->where('email', 'queue.ava@example.test')->firstOrFail();
        $first = WorkItem::query()->where('employee_id', $ava->id)->where('title', 'Homepage redesign')->first();
        if ($first !== null && ! $first->tickets()->exists()) {
            $first->update(['status' => 'in_progress']);
            Ticket::create([
                'agency_id' => $northwind->id, 'work_item_id' => $first->id, 'created_by' => $owner->id,
                'number' => (int) Ticket::query()->where('agency_id', $northwind->id)->max('number') + 1,
                'title' => 'Build the hero section', 'description' => 'Demo ticket.', 'estimated_hours' => 6,
                'priority' => 'normal', 'status' => 'in_progress', 'position' => 1,
            ]);
        }

        // One employee nobody holds, for the "another team member" quote step.
        User::query()->updateOrCreate(['email' => 'queue.pool@example.test'], ['name' => 'Queue Demo Spare', 'password' => env('DEMO_PASSWORD'), 'role' => Role::Employee, 'is_active' => true]);

        if ($firstAssignment !== null && ! RushRequest::query()->where('agency_id', $northwind->id)->where('title', 'Fix the broken checkout')->exists()) {
            RushRequest::create([
                'agency_id' => $northwind->id, 'requested_by' => $client->id, 'type' => 'priority_insert',
                'assignment_id' => EmployeeAssignment::query()->where('employee_id', $ava->id)->where('status', 'active')->value('id'),
                'title' => 'Fix the broken checkout', 'description' => 'Customers cannot pay.', 'estimated_hours' => 4,
                'needed_by' => CarbonImmutable::now()->addDays(3)->toDateString(), 'reason' => 'Campaign goes live on Friday.', 'status' => 'pending',
            ]);
        }

        $this->command?->info('Queue demo ready: sign in as owner@northwind.test / client@northwind.test (Northwind) or queue.ava@example.test (employee), then open Team queue.');
    }
}
