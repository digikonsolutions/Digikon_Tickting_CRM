<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\SetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/** Regression tests for closed-ticket edits, the project cancel race, the availability endpoint and reset emails. */
class BackendCorrectnessTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWeek();
    }

    /** @return array{0: User, 1: EmployeeAssignment, 2: WorkItem} */
    private function openProject(string $status = 'in_progress'): array
    {
        $agency = $this->makeAgency('Northwind');
        $client = $this->makeClient($agency);
        $assignment = $this->hold($this->makeEmployee('Ann'), $agency, $client);
        $project = $this->book($assignment, $status, [[$this->today->addDays(2), 2], [$this->today->addDays(3), 2]]);

        return [$client, $assignment, $project];
    }

    private function ticket(WorkItem $project, string $status = 'todo'): Ticket
    {
        $ticket = Ticket::create(['work_item_id' => $project->id, 'title' => 'Original', 'description' => 'd', 'estimated_hours' => 1, 'position' => 0]);
        $ticket->forceFill(['status' => $status])->save();

        return $ticket;
    }

    public function test_closed_tickets_cannot_be_edited(): void
    {
        [$client, , $project] = $this->openProject();

        foreach (['done', 'cancelled'] as $status) {
            $ticket = $this->ticket($project, $status);
            $this->actingAs($client)->patchJson("/api/agency/tickets/{$ticket->id}", ['title' => 'Changed'])
                ->assertUnprocessable()->assertJsonPath('message', 'This ticket is already closed.');
            $this->assertSame('Original', $ticket->fresh()->title);
            $this->assertSame($status, $ticket->fresh()->status);
        }
    }

    public function test_a_project_can_only_be_cancelled_once_and_cancelling_closes_its_open_work(): void
    {
        [$client, , $project] = $this->openProject();
        $open = $this->ticket($project, 'in_progress');
        $done = $this->ticket($project, 'done');
        $project->allocations()->count();
        $this->assertGreaterThan(0, $project->allocations()->count());

        $this->actingAs($client)->patchJson("/api/agency/projects/{$project->id}/cancel")->assertOk()->assertJsonPath('project.status', 'cancelled');

        $this->assertSame('cancelled', $project->fresh()->status);
        $this->assertSame('cancelled', $open->fresh()->status);
        $this->assertSame('done', $done->fresh()->status);                                  // finished work stays finished
        $this->assertSame(0, DB::table('work_item_allocations')->where('work_item_id', $project->id)->count());
        $this->patchJson("/api/agency/projects/{$project->id}/cancel")->assertUnprocessable();
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $open->id)->where('type', 'cancelled')->count());
    }

    public function test_a_completed_project_cannot_be_cancelled(): void
    {
        [$client, , $project] = $this->openProject('completed');

        $this->actingAs($client)->patchJson("/api/agency/projects/{$project->id}/cancel")->assertUnprocessable();
        $this->assertSame('completed', $project->fresh()->status);
    }

    public function test_availability_uses_the_scheduler_rules_and_every_holders_bookings(): void
    {
        $agency = $this->makeAgency('Northwind');
        $client = $this->makeClient($agency);
        $ann = $this->makeEmployee('Ann');
        $assignment = $this->hold($ann, $agency, $client);
        $monday = $this->monday->addDays(7);
        $tuesday = $monday->addDay();
        $url = "/api/agency/assignments/{$assignment->id}/availability?start_date={$monday->toDateString()}&due_date={$tuesday->toDateString()}";

        $this->actingAs($client)->getJson($url)->assertOk()
            ->assertJsonPath('available_hours', '12.00')->assertJsonPath('capacity.daily_hours', 6)->assertJsonCount(2, 'days');

        // Hours booked on this employee by this assignment, and by a previous holder, both count.
        $this->book($assignment, 'in_progress', [[$monday, 4]]);
        $otherAgency = $this->makeAgency('Earlier Holder');
        $earlier = $this->hold($this->makeEmployee('Zed'), $otherAgency, $this->makeClient($otherAgency));
        $earlier->update(['employee_id' => $ann->id, 'active_employee_id' => null, 'status' => 'ended']);
        $this->book($earlier, 'in_progress', [[$tuesday, 3]]);
        $this->getJson($url)->assertOk()->assertJsonPath('available_hours', '5.00');       // 2 left on Monday + 3 on Tuesday

        // A non-working date and a Sunday contribute nothing.
        config(['scheduling.non_working_dates' => [$tuesday->toDateString()]]);
        $this->getJson($url)->assertOk()->assertJsonPath('available_hours', '2.00');
        $sunday = $this->monday->addDays(13);
        $this->getJson("/api/agency/assignments/{$assignment->id}/availability?start_date={$sunday->toDateString()}&due_date={$sunday->toDateString()}")
            ->assertOk()->assertJsonPath('available_hours', '0.00');
    }

    public function test_availability_is_validated_and_limited_to_the_holder(): void
    {
        $agency = $this->makeAgency('Northwind');
        $client = $this->makeClient($agency);
        $assignment = $this->hold($this->makeEmployee('Ann'), $agency, $client);
        $base = "/api/agency/assignments/{$assignment->id}/availability";
        $start = $this->today->addDays(3)->toDateString();

        $this->getJson($base)->assertUnauthorized();
        $this->actingAs($client)->getJson($base)->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'due_date']);
        $this->getJson("{$base}?start_date={$start}&due_date={$this->today->toDateString()}")->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->getJson("{$base}?start_date={$start}&due_date={$this->today->addYears(3)->toDateString()}")->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->getJson("{$base}?start_date=tomorrow&due_date={$start}")->assertUnprocessable()->assertJsonValidationErrors('start_date');

        // Someone else's assignment (same agency, other client; other agency; wrong role) is not found or forbidden.
        $this->actingAs($this->makeClient($agency, 'Colleague'))->getJson("{$base}?start_date={$start}&due_date={$start}")->assertOk();      // clients work with the agency's employees
        $rival = $this->makeClient($this->makeAgency('Rival'));
        $this->actingAs($rival)->getJson("{$base}?start_date={$start}&due_date={$start}")->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => Role::Employee]))->getJson("{$base}?start_date={$start}&due_date={$start}")->assertForbidden();
        $assignment->update(['status' => 'ended', 'active_employee_id' => null]);
        $this->actingAs($client)->getJson("{$base}?start_date={$start}&due_date={$start}")->assertNotFound();
    }

    public function test_deactivated_users_get_no_reset_email_but_the_same_answer(): void
    {
        Notification::fake();
        $active = User::factory()->create(['email' => 'active@example.com']);
        $inactive = User::factory()->create(['email' => 'gone@example.com', 'is_active' => false]);

        $a = $this->postJson('/api/auth/forgot-password', ['email' => 'active@example.com'])->assertStatus(202);
        $b = $this->postJson('/api/auth/forgot-password', ['email' => 'gone@example.com'])->assertStatus(202);
        $c = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertStatus(202);

        Notification::assertSentTo($active, SetPasswordNotification::class);
        Notification::assertNotSentTo($inactive, SetPasswordNotification::class);
        $this->assertSame($a->json(), $b->json());
        $this->assertSame($a->json(), $c->json());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'gone@example.com']);
    }
}
