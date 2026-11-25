<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** What the timeline review screen needs to read at a glance: the services asked for, the hours and each ticket's service. */
class TimelineReviewScreenTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    public function test_the_review_payload_lists_services_hours_and_ticket_services(): void
    {
        $flow = $this->project->services()->create(['service_key' => 'workflow', 'details' => ['trigger' => 'form_submitted'], 'estimated_hours' => 10, 'position' => 0]);
        $ticket = $this->makeTicket('owner', 'Flow', 4);
        $ticket->update(['project_service_id' => $flow->id]);

        foreach (['admin' => '/api/admin/projects/', 'owner' => '/api/agency/projects/'] as $who => $base) {
            $res = $this->actingAs($this->u[$who])->getJson($base.$this->project->id.'/timeline')->assertOk();
            $res->assertJsonPath('project.services.0.key', 'workflow')->assertJsonPath('project.services.0.details.trigger', 'form_submitted')
                ->assertJsonPath('project.hours.total', 30)->assertJsonPath('project.hours.allocated', 4)->assertJsonPath('tickets.0.service', 'Workflow / automation');
        }
    }

    public function test_the_queue_names_the_services_of_each_request(): void
    {
        $this->project->services()->create(['service_key' => 'crm', 'details' => [], 'position' => 0]);
        $this->project->services()->create(['service_key' => 'ai_agent', 'details' => [], 'position' => 1]);
        $this->project->forceFill(['timeline_status' => 'proposed', 'timeline_proposed_at' => now()])->save();
        $row = collect($this->actingAs($this->u['admin'])->getJson('/api/admin/timeline-reviews')->assertOk()->json('reviews'))->firstWhere('id', $this->project->id);
        $this->assertCount(2, $row['services']);
    }

    public function test_the_queue_does_not_query_services_once_per_row(): void
    {
        $this->project->forceFill(['timeline_status' => 'proposed', 'timeline_proposed_at' => now()])->save();
        $count = function (): int {
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->actingAs($this->u['admin'])->getJson('/api/admin/timeline-reviews')->assertOk();
            $n = count(\DB::getQueryLog());
            \DB::disableQueryLog();

            return $n;
        };
        $this->project->services()->create(['service_key' => 'crm', 'details' => [], 'position' => 0]);
        $few = $count();
        foreach (range(1, 3) as $i) {
            $copy = $this->project->replicate();$copy->title = "P$i";
            $copy->save();
            $copy->services()->create(['service_key' => 'crm', 'details' => [], 'position' => 0]);
        }
        // The row mapper already runs a per-row urgency check; services must add nothing on top of that.
        $this->assertLessThanOrEqual($few + 3 * 2, $count());
    }
}