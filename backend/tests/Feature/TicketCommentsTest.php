<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketCommentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_participants_can_read_the_thread_oldest_first_with_author_roles(): void
    {
        [$agency, $owner, $client, $employee, $project, $ticket] = $this->fixture();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $teammate = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $first = $this->addComment($ticket, $client, 'First comment');
        $second = $this->addComment($ticket, $employee, "Second\nline two");

        foreach ([$admin, $owner, $client, $teammate, $employee] as $reader) {
            $this->actingAs($reader)->getJson('/api/tickets/'.$ticket->id.'/comments')
                ->assertOk()
                ->assertJsonPath('total', 2)
                ->assertJsonCount(2, 'comments')
                ->assertJsonPath('comments.0.id', $first->id)
                ->assertJsonPath('comments.0.ticket_id', $ticket->id)
                ->assertJsonPath('comments.0.user.id', $client->id)
                ->assertJsonPath('comments.0.user.name', $client->name)
                ->assertJsonPath('comments.0.user.role', 'agency_client')
                ->assertJsonPath('comments.0.body', 'First comment')
                ->assertJsonPath('comments.1.id', $second->id)
                ->assertJsonPath('comments.1.user.role', 'employee')
                ->assertJsonPath('comments.1.body', "Second\nline two");
        }
        $response = $this->getJson('/api/tickets/'.$ticket->id.'/comments');
        $this->assertNotNull($response->json('comments.0.created_at'));
        $this->assertNotNull($response->json('comments.0.updated_at'));
    }

    public function test_posting_returns_the_same_comment_shape_including_the_author_role(): void
    {
        [, , $client, , , $ticket] = $this->fixture();

        $this->actingAs($client)->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'Hello'])
            ->assertCreated()
            ->assertJsonPath('comment.user.id', $client->id)
            ->assertJsonPath('comment.user.role', 'agency_client')
            ->assertJsonPath('comment.body', 'Hello');
        $this->getJson('/api/tickets/'.$ticket->id.'/comments')->assertJsonPath('total', 1);
    }

    public function test_only_the_latest_two_hundred_comments_are_returned_with_the_real_total(): void
    {
        [, , $client, , , $ticket] = $this->fixture();
        foreach (range(1, 203) as $number) {
            $this->addComment($ticket, $client, 'Comment '.$number);
        }

        $response = $this->actingAs($client)->getJson('/api/tickets/'.$ticket->id.'/comments')
            ->assertOk()
            ->assertJsonPath('total', 203)
            ->assertJsonCount(200, 'comments')
            ->assertJsonPath('comments.0.body', 'Comment 4')
            ->assertJsonPath('comments.199.body', 'Comment 203');
        $this->assertSame('Comment 4', $response->json('comments.0.body'));
    }

    public function test_reading_follows_the_same_access_rule_as_writing(): void
    {
        [$agency, , , $employee, $project, $ticket] = $this->fixture();
        $otherAgency = Agency::create(['name' => 'Other agency']);
        $outsiders = [
            'other agency client' => User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]),
            'other agency owner' => User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $otherAgency->id]),
            'unassigned employee' => User::factory()->create(['role' => Role::Employee]),
            'team lead' => User::factory()->create(['role' => Role::TeamLead]),
            'legacy agency member' => User::factory()->create(['role' => Role::AgencyMember, 'agency_id' => $agency->id]),
        ];

        $this->getJson('/api/tickets/'.$ticket->id.'/comments')->assertUnauthorized();
        foreach ($outsiders as $label => $user) {
            $this->actingAs($user)->getJson('/api/tickets/'.$ticket->id.'/comments')->assertForbidden();
            $this->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'x'])->assertForbidden();
        }
        $this->assertSame(0, TicketComment::count());

        $agency->update(['status' => 'suspended']);
        $client = User::query()->where('role', Role::AgencyClient->value)->where('agency_id', $agency->id)->first();
        $this->actingAs($client)->getJson('/api/tickets/'.$ticket->id.'/comments')->assertForbidden();
        $this->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'x'])->assertForbidden();
    }

    public function test_comment_thread_query_count_does_not_grow_with_the_number_of_comments(): void
    {
        [$agency, , $client, $employee, , $ticket] = $this->fixture();
        $this->addComment($ticket, $client, 'one');
        $this->addComment($ticket, $employee, 'two');
        $this->actingAs($client);
        $measure = function () use ($ticket): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/tickets/'.$ticket->id.'/comments')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $this->getJson('/api/tickets/'.$ticket->id.'/comments')->assertOk(); // warm up
        $before = $measure();

        foreach (range(1, 12) as $number) {
            $author = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
            $this->addComment($ticket, $author, 'More '.$number);
        }

        $this->assertSame($before, $measure());
    }

    public function test_comments_count_is_on_every_ticket_listing_and_payload(): void
    {
        [$agency, $owner, $client, $employee, $project, $ticket] = $this->fixture();
        $quiet = Ticket::create([
            'work_item_id' => $project->id, 'title' => 'Quiet', 'description' => 'No comments.',
            'estimated_hours' => 1, 'position' => 1,
        ]);
        $this->addComment($ticket, $client, 'a');
        $this->addComment($ticket, $employee, 'b');
        $this->addComment($ticket, $owner, 'c');
        $admin = User::factory()->create(['role' => Role::Admin]);

        $byId = fn ($response, string $key = 'tickets') => collect($response->json($key))->pluck('comments_count', 'id')->all();

        $this->actingAs($client);
        $this->assertSame([$ticket->id => 3, $quiet->id => 0], $byId($this->getJson('/api/agency/projects/'.$project->id.'/tickets')->assertOk()));
        $this->actingAs($employee);
        $this->assertSame([$ticket->id => 3, $quiet->id => 0], $byId($this->getJson('/api/work-items/mine/tickets')->assertOk()));
        $this->actingAs($admin);
        $this->assertSame([$ticket->id => 3, $quiet->id => 0], $byId($this->getJson('/api/admin/tickets')->assertOk()));
        $this->assertSame([$ticket->id => 3, $quiet->id => 0], $byId($this->getJson('/api/admin/projects/'.$project->id)->assertOk()));

        // Single-ticket payloads fall back to a query.
        $this->actingAs($client)->patchJson('/api/agency/tickets/'.$ticket->id, ['title' => 'Renamed'])
            ->assertOk()->assertJsonPath('ticket.comments_count', 3);
        $created = $this->postJson('/api/agency/projects/'.$project->id.'/tickets', [
            'title' => 'Fresh', 'description' => 'New ticket.', 'estimated_hours' => 1,
        ])->assertCreated()->assertJsonPath('ticket.comments_count', 0);
        $this->assertNotNull($created->json('ticket.id'));
    }

    /**
     * @return array{Agency, User, User, User, WorkItem, Ticket}
     */
    private function fixture(): array
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = EmployeeAssignment::create([
            'holder_id' => $client->id,
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
        $project = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => 'Project',
            'description' => 'Description.',
            'estimated_hours' => 6,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDays(5)->toDateString(),
            'status' => 'scheduled',
        ]);
        $project->allocations()->create(['employee_id' => $employee->id, 'work_date' => $monday->toDateString(), 'hours' => 6]);
        $ticket = Ticket::create([
            'work_item_id' => $project->id, 'title' => 'Ticket', 'description' => 'Ticket description.',
            'estimated_hours' => 2, 'position' => 0, 'created_by' => $client->id,
        ]);

        return [$agency, $owner, $client, $employee, $project, $ticket];
    }

    private function addComment(Ticket $ticket, User $author, string $body): TicketComment
    {
        return TicketComment::create(['ticket_id' => $ticket->id, 'user_id' => $author->id, 'body' => $body]);
    }
}
