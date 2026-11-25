<?php

namespace Tests\Feature;

use App\Models\ProjectService;
use App\Models\TicketEvent;
use App\Models\TimeEntry;
use App\Notifications\TicketRescheduled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** Tickets tied to a project's services, hours per project and per ticket, and the admin changing a ticket's hours. */
class TicketHoursTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    private function service(string $key = 'workflow', ?float $hours = 10): ProjectService
    {
        return $this->project->services()->create(['service_key' => $key, 'details' => [], 'estimated_hours' => $hours, 'position' => $this->project->services()->count()]);
    }

    private function url(int $ticketId): string
    {
        return '/api/admin/tickets/'.$ticketId.'/hours';
    }

    public function test_a_ticket_can_be_for_one_service_of_its_own_project(): void
    {
        $service = $this->service('ai_agent');
        $res = $this->actingAs($this->u['owner'])->postJson('/api/agency/projects/'.$this->project->id.'/tickets', [
            'title' => 'Chatbot', 'description' => 'Build it.', 'estimated_hours' => 3, 'project_service_id' => $service->id,
        ])->assertCreated();
        $res->assertJsonPath('ticket.service.key', 'ai_agent')->assertJsonPath('ticket.service_type', 'ai_agent')->assertJsonPath('ticket.remaining_hours', 3);
    }

    public function test_a_service_of_another_project_is_refused(): void
    {
        $other = $this->project->replicate()->fill(['title' => 'Other']);
        $other->save();
        $foreign = $other->services()->create(['service_key' => 'crm', 'details' => [], 'position' => 0]);
        $this->actingAs($this->u['owner'])->postJson('/api/agency/projects/'.$this->project->id.'/tickets', [
            'title' => 'X', 'description' => 'Y', 'estimated_hours' => 1, 'project_service_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('project_service_id');
    }

    public function test_the_project_reports_total_allocated_remaining_and_per_service_hours(): void
    {
        $a = $this->service('workflow', 10);
        $this->service('crm', 5);
        $ticket = $this->makeTicket('owner', 'Flow', 4);
        $ticket->update(['project_service_id' => $a->id]);
        $this->makeTicket('owner', 'General', 2);
        TimeEntry::create(['ticket_id' => $ticket->id, 'work_item_id' => $this->project->id, 'user_id' => $this->u['employee']->id, 'entry_date' => now()->toDateString(), 'minutes' => 90]);

        $res = $this->actingAs($this->u['admin'])->getJson('/api/admin/projects/'.$this->project->id)->assertOk();
        $res->assertJsonPath('project.hours.total', 30)->assertJsonPath('project.hours.allocated', 6)->assertJsonPath('project.hours.remaining', 24)
            ->assertJsonPath('project.hours.logged', 1.5)->assertJsonPath('project.hours.general', 2);
        $rows = collect($res->json('project.hours.by_service'))->keyBy('key');
        $this->assertEquals(4, $rows['workflow']['ticket_hours']);
        $this->assertEquals(6, $rows['workflow']['remaining']);
        $this->assertEquals(5, $rows['crm']['remaining']);
    }

    public function test_the_project_list_carries_hours_without_extra_queries_per_project(): void
    {
        $this->makeTicket('owner', 'One', 1);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $res = $this->actingAs($this->u['owner'])->getJson('/api/agency/projects')->assertOk();
            $mine = collect($res->json('projects'))->firstWhere('id', $this->project->id);
            $this->assertEquals(1, $mine['hours']['allocated']);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $few = $count();
        foreach (range(1, 3) as $i) {
            $copy = $this->project->replicate()->fill(['title' => "P$i"]);
            $copy->save();
            $copy->services()->create(['service_key' => 'crm', 'details' => [], 'position' => 0]);
        }
        $this->assertSame($few, $count(), 'More projects must not add queries.');
    }

    public function test_an_admin_changes_ticket_hours_with_a_reason_and_everyone_is_told(): void
    {
        Notification::fake();
        $ticket = $this->makeTicket('owner', 'Flow', 4);
        $this->actingAs($this->u['admin'])->patchJson($this->url($ticket->id), ['estimated_hours' => 6, 'reason' => 'More branches than planned'])
            ->assertOk()->assertJsonPath('changed', true)->assertJsonPath('hours.allocated', 6)->assertJsonPath('hours.remaining', 24);
        $this->assertEquals(6, (float) $ticket->fresh()->estimated_hours);
        $this->assertEquals(6.0, (float) $ticket->allocations()->sum('hours'), 'the schedule was replanned');
        $event = TicketEvent::where('ticket_id', $ticket->id)->where('type', 'hours_adjusted')->first();
        $this->assertSame('More branches than planned', $event->payload['reason']);
        $this->assertSame('4.00', $event->payload['from']);
        Notification::assertSentTo($this->u['owner'], TicketRescheduled::class);
        Notification::assertSentTo($this->u['employee'], TicketRescheduled::class);
        Notification::assertNotSentTo($this->u['admin'], TicketRescheduled::class);
    }

    public function test_a_reason_is_required_and_only_admins_may_change_hours(): void
    {
        $ticket = $this->makeTicket('owner', 'Flow', 4);
        $this->actingAs($this->u['admin'])->patchJson($this->url($ticket->id), ['estimated_hours' => 5])->assertStatus(422)->assertJsonValidationErrors('reason');
        foreach (['owner', 'client', 'employee'] as $who) {
            $this->actingAs($this->u[$who])->patchJson($this->url($ticket->id), ['estimated_hours' => 5, 'reason' => 'Because I said so'])->assertForbidden();
        }
        $this->assertEquals(4, (float) $ticket->fresh()->estimated_hours);
    }

    public function test_hours_cannot_go_below_logged_time_above_the_project_or_the_cap(): void
    {
        $ticket = $this->makeTicket('owner', 'Flow', 4);
        $this->makeTicket('owner', 'Other', 20);
        TimeEntry::create(['ticket_id' => $ticket->id, 'work_item_id' => $this->project->id, 'user_id' => $this->u['employee']->id, 'entry_date' => now()->toDateString(), 'minutes' => 150]);
        $this->actingAs($this->u['admin'])->patchJson($this->url($ticket->id), ['estimated_hours' => 2, 'reason' => 'Trim it down'])
            ->assertStatus(422)->assertJsonValidationErrors('estimated_hours');
        $this->actingAs($this->u['admin'])->patchJson($this->url($ticket->id), ['estimated_hours' => 11, 'reason' => 'Too much for it'])
            ->assertStatus(422)->assertJsonValidationErrors('estimated_hours');
        $this->actingAs($this->u['admin'])->patchJson($this->url($ticket->id), ['estimated_hours' => 181, 'reason' => 'Way too much'])->assertStatus(422);
        $this->actingAs($this->u['admin'])->patchJson($this->url($ticket->id), ['estimated_hours' => 10, 'reason' => 'Uses what is left'])->assertOk();
        $this->assertEquals(10, (float) $ticket->fresh()->estimated_hours);
    }

    public function test_closed_tickets_keep_their_hours(): void
    {
        $ticket = $this->makeTicket('owner', 'Flow', 4);
        $ticket->update(['status' => 'done']);
        $this->actingAs($this->u['admin'])->patchJson($this->url($ticket->id), ['estimated_hours' => 5, 'reason' => 'Should not work'])->assertStatus(422);
    }

    public function test_hours_move_between_two_tickets_together_or_not_at_all(): void
    {
        $a = $this->makeTicket('owner', 'A', 5);
        $b = $this->makeTicket('owner', 'B', 3);
        $this->actingAs($this->u['admin'])->postJson('/api/admin/projects/'.$this->project->id.'/hours/move', ['from_ticket_id' => $a->id, 'to_ticket_id' => $b->id, 'hours' => 2, 'reason' => 'B needs more'])->assertOk();
        $this->assertEquals([3.0, 5.0], [(float) $a->fresh()->estimated_hours, (float) $b->fresh()->estimated_hours]);
        $this->assertSame(2, TicketEvent::where('type', 'hours_adjusted')->count());

        // Moving more than the giver has fails and leaves both alone.
        $this->actingAs($this->u['admin'])->postJson('/api/admin/projects/'.$this->project->id.'/hours/move', ['from_ticket_id' => $a->id, 'to_ticket_id' => $b->id, 'hours' => 3, 'reason' => 'Too many hours'])->assertStatus(422);
        $this->assertEquals([3.0, 5.0], [(float) $a->fresh()->estimated_hours, (float) $b->fresh()->estimated_hours]);
        $this->assertSame(2, TicketEvent::where('type', 'hours_adjusted')->count());
    }

    public function test_ticket_lists_do_not_query_each_tickets_service_separately(): void
    {
        $service = $this->service('workflow', 20);
        $make = function (int $count) use ($service): void {
            foreach (range(1, $count) as $i) {
                $this->makeTicket('owner', "Ticket {$i}", 0.5)->update(['project_service_id' => $service->id]);
            }
        };
        $measure = function (string $who, string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->u[$who])->getJson($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $make(2);
        foreach (['admin' => '/api/admin/tickets', 'owner' => '/api/agency/tickets'] as $who => $url) {
            $measure($who, $url);
        }
        $before = ['admin' => $measure('admin', '/api/admin/tickets'), 'owner' => $measure('owner', '/api/agency/tickets')];
        $make(6);

        $this->assertSame($before['admin'], $measure('admin', '/api/admin/tickets'), 'admin list');
        $this->assertSame($before['owner'], $measure('owner', '/api/agency/tickets'), 'agency list');
    }
}