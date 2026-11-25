<?php

namespace Tests\Feature;

use App\Models\EmployeeFeedback;
use App\Notifications\FeedbackNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** An agency owner's feedback about an employee they held: who writes, who reads, edits, replies and removal. */
class EmployeeFeedbackTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    private function body(array $extra = []): array
    {
        return ['rating' => 4, 'ratings' => ['quality' => 5, 'communication' => 4], 'would_again' => 'yes', 'went_well' => 'Clear updates and tidy workflows.',
            'highlights' => ['proactive'], 'private_note' => 'Great, keep him on our shortlist.', ...$extra];
    }

    private function url(?int $employee = null): string
    {
        return '/api/agency/employees/'.($employee ?? $this->u['employee']->id).'/feedback';
    }

    private function give(array $extra = [])
    {
        return $this->actingAs($this->u['owner'])->postJson($this->url(), $this->body($extra));
    }

    public function test_the_owner_writes_feedback_and_the_summary_adds_up(): void
    {
        $this->give()->assertCreated()->assertJsonPath('feedback.rating', 4)->assertJsonPath('feedback.highlights.0.label', 'Proactive');
        $res = $this->actingAs($this->u['owner'])->getJson($this->url())->assertOk();
        $res->assertJsonPath('summary.count', 1)->assertJsonPath('summary.average', 4)->assertJsonPath('summary.would_again_percent', 100)->assertJsonPath('can_write', true);
        $this->assertEquals(5, collect($res->json('summary.categories'))->firstWhere('key', 'quality')['average']);
        $this->assertNull(collect($res->json('summary.categories'))->firstWhere('key', 'ghl_skill')['average']);
    }

    public function test_only_the_owner_writes_clients_read_and_other_agencies_see_nothing(): void
    {
        $this->actingAs($this->u['client'])->postJson($this->url(), $this->body())->assertForbidden();
        $this->actingAs($this->u['employee'])->postJson($this->url(), $this->body())->assertForbidden();
        $this->actingAs($this->u['admin'])->postJson($this->url(), $this->body())->assertForbidden();
        $this->give()->assertCreated();

        $client = $this->actingAs($this->u['client'])->getJson($this->url())->assertOk();
        $client->assertJsonCount(1, 'feedback')->assertJsonPath('can_write', false);
        $this->assertArrayNotHasKey('private_note', $client->json('feedback.0'), 'clients never get the private note');

        $outsider = $this->outsider();
        $this->actingAs($outsider)->getJson($this->url())->assertNotFound();
        $this->actingAs($outsider)->postJson($this->url(), $this->body())->assertNotFound();
        $this->assertSame(1, EmployeeFeedback::count());
    }

    public function test_an_employee_the_agency_never_held_cannot_be_reviewed(): void
    {
        $stranger = \App\Models\User::factory()->create(['role' => \App\Enums\Role::Employee]);
        $this->actingAs($this->u['owner'])->postJson($this->url($stranger->id), $this->body())->assertNotFound();
    }

    public function test_an_ended_assignment_can_still_be_reviewed(): void
    {
        \App\Models\EmployeeAssignment::query()->update(['status' => 'ended', 'active_employee_id' => null, 'ended_at' => now()]);
        $this->give()->assertCreated();
    }

    public function test_validation_and_the_one_per_project_and_per_day_limits(): void
    {
        $this->give(['rating' => 6])->assertStatus(422)->assertJsonValidationErrors('rating');
        $this->give(['would_again' => 'sure'])->assertStatus(422)->assertJsonValidationErrors('would_again');
        $this->give(['went_well' => '', 'to_improve' => ''])->assertStatus(422)->assertJsonValidationErrors('went_well');
        $this->give(['highlights' => ['made_up']])->assertStatus(422);
        $this->give(['ratings' => ['quality' => 9]])->assertStatus(422);

        $this->give()->assertCreated();
        // The same assignment without a project is general feedback, which exists already.
        $this->give()->assertStatus(422);
        // A project of the employee allows another, but not on the same day from the same author.
        $this->give(['work_item_id' => $this->project->id])->assertStatus(429);
        $this->travel(1)->days();
        $this->give(['work_item_id' => $this->project->id])->assertCreated()->assertJsonPath('feedback.project.title', 'Project');
        $this->give(['work_item_id' => $this->project->id])->assertStatus(422);
    }

    public function test_a_project_of_someone_else_is_refused(): void
    {
        $other = $this->project->replicate();
        $other->employee_id = \App\Models\User::factory()->create(['role' => \App\Enums\Role::Employee])->id;
        $other->save();
        $this->give(['work_item_id' => $other->id])->assertStatus(422);
    }

    public function test_it_can_be_edited_for_seven_days_then_locks_and_cannot_be_deleted_by_the_owner(): void
    {
        $id = $this->give()->json('feedback.id');
        $this->actingAs($this->u['owner'])->patchJson('/api/agency/feedback/'.$id, $this->body(['rating' => 5, 'went_well' => 'Even better.']))->assertOk()->assertJsonPath('feedback.rating', 5);
        $this->actingAs($this->u['owner'])->patchJson('/api/agency/feedback/'.$id, $this->body(['work_item_id' => $this->project->id]))->assertStatus(422);
        $this->actingAs($this->u['client'])->patchJson('/api/agency/feedback/'.$id, $this->body())->assertForbidden();
        $this->actingAs($this->outsider())->patchJson('/api/agency/feedback/'.$id, $this->body())->assertNotFound();

        $this->travel(8)->days();
        $this->actingAs($this->u['owner'])->patchJson('/api/agency/feedback/'.$id, $this->body())->assertStatus(422);
        $this->actingAs($this->u['owner'])->deleteJson('/api/admin/feedback/'.$id, ['reason' => 'I would like this gone'])->assertForbidden();
        $this->assertSame(5, EmployeeFeedback::find($id)->rating);
    }

    public function test_the_employee_reads_a_shared_version_and_replies_once(): void
    {
        Notification::fake();
        $this->give(['rating' => 5])->assertCreated();
        Notification::assertSentTo($this->u['employee'], FeedbackNotice::class);
        Notification::assertNotSentTo($this->u['admin'], FeedbackNotice::class);

        $mine = $this->actingAs($this->u['employee'])->getJson('/api/my/feedback')->assertOk();
        $mine->assertJsonPath('summary.count', 1);
        $row = $mine->json('feedback.0');
        $this->assertArrayNotHasKey('private_note', $row);
        $this->assertArrayNotHasKey('author', $row);
        $this->assertStringNotContainsString('shortlist', json_encode($mine->json()));

        $this->actingAs($this->u['employee'])->postJson('/api/my/feedback/'.$row['id'].'/reply', ['body' => 'Thank you, happy to help again.'])->assertCreated()->assertJsonPath('feedback.reply.body', 'Thank you, happy to help again.');
        $this->actingAs($this->u['employee'])->postJson('/api/my/feedback/'.$row['id'].'/reply', ['body' => 'Second try'])->assertStatus(422);
        Notification::assertSentTo($this->u['owner'], FeedbackNotice::class);
        Notification::assertSentTo($this->u['admin'], FeedbackNotice::class);
        // The owner sees the reply; other roles cannot reply.
        $this->actingAs($this->u['owner'])->getJson($this->url())->assertJsonPath('feedback.0.reply.body', 'Thank you, happy to help again.');
        $this->actingAs($this->u['owner'])->postJson('/api/my/feedback/'.$row['id'].'/reply', ['body' => 'Hello there'])->assertForbidden();
    }

    public function test_another_employee_cannot_reply_to_or_read_it(): void
    {
        $id = $this->give()->json('feedback.id');
        $other = \App\Models\User::factory()->create(['role' => \App\Enums\Role::Employee]);
        $this->actingAs($other)->postJson('/api/my/feedback/'.$id.'/reply', ['body' => 'Not mine'])->assertNotFound();
        $this->actingAs($other)->getJson('/api/my/feedback')->assertJsonPath('summary.count', 0);
    }

    public function test_low_ratings_and_a_no_alert_the_admins(): void
    {
        Notification::fake();
        $this->give(['rating' => 5, 'would_again' => 'yes'])->assertCreated();
        Notification::assertNotSentTo($this->u['admin'], FeedbackNotice::class);
        $this->travel(1)->days();
        $this->give(['rating' => 2, 'would_again' => 'no', 'work_item_id' => $this->project->id])->assertCreated();
        Notification::assertSentTo($this->u['admin'], FeedbackNotice::class);
    }

    public function test_admins_see_everything_per_agency_and_may_remove_with_a_reason(): void
    {
        $id = $this->give()->json('feedback.id');
        $res = $this->actingAs($this->u['admin'])->getJson('/api/admin/employees/'.$this->u['employee']->id.'/feedback')->assertOk();
        $res->assertJsonPath('feedback.0.private_note', 'Great, keep him on our shortlist.')->assertJsonPath('per_agency.0.count', 1)->assertJsonPath('summary.average', 4);

        $this->actingAs($this->u['admin'])->deleteJson('/api/admin/feedback/'.$id, [])->assertStatus(422);
        $this->actingAs($this->u['admin'])->deleteJson('/api/admin/feedback/'.$id, ['reason' => 'Written about the wrong person'])->assertOk()->assertJsonPath('feedback.status', 'removed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'removed_feedback', 'subject_id' => $id]);
        // Removed feedback leaves every average and every other reader's list.
        $this->actingAs($this->u['owner'])->getJson($this->url())->assertJsonPath('summary.count', 0);
        $this->actingAs($this->u['employee'])->getJson('/api/my/feedback')->assertJsonPath('summary.count', 0);
        $this->actingAs($this->u['admin'])->getJson('/api/admin/feedback/summary')->assertJsonCount(0, 'summaries');
        foreach (['owner', 'client', 'employee'] as $who) {
            $this->actingAs($this->u[$who])->getJson('/api/admin/employees/'.$this->u['employee']->id.'/feedback')->assertForbidden();
        }
    }

    public function test_the_team_table_gets_one_average_per_employee_of_its_own_agency_only(): void
    {
        $this->give(['rating' => 3])->assertCreated();
        $this->actingAs($this->u['client'])->getJson('/api/agency/feedback/summary')->assertOk()->assertJsonPath('summaries.'.$this->u['employee']->id.'.average', 3);
        $this->actingAs($this->outsider())->getJson('/api/agency/feedback/summary')->assertOk()->assertJsonCount(0, 'summaries');
    }

    public function test_the_form_lists_categories_and_chips_from_the_config(): void
    {
        $res = $this->actingAs($this->u['owner'])->getJson('/api/agency/feedback/form')->assertOk();
        $this->assertCount(5, $res->json('categories'));
        $this->assertContains('missed_deadlines', array_column($res->json('concerns'), 'key'));
        $res->assertJsonPath('edit_days', 7);
    }

    public function test_a_suspended_agency_cannot_write(): void
    {
        $this->agency->update(['status' => 'suspended']);
        $this->give()->assertForbidden();
    }
}
