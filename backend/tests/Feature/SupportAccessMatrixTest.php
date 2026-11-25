<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** Every support endpoint, asked by someone who must not get through: another agency, and the agency itself on staff routes. */
class SupportAccessMatrixTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    public function test_no_endpoint_lets_the_wrong_person_in(): void
    {
        $ref = $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets', ['type' => 'technical_support', 'title' => 'Help', 'description' => 'Please.', 'expected_result' => 'Fixed.', 'details' => ['goal' => 'x']])->assertCreated()->json('ticket.ref');
        $ticket = Ticket::query()->where('ref', $ref)->firstOrFail();
        $id = $ticket->id;
        $outsider = $this->outsider();

        // Another agency: nothing about this ticket exists for them.
        $agency = [
            ['getJson', "/api/agency/support/tickets/$ref"], ['postJson', "/api/agency/support/tickets/$ref/reopen", ['reason' => 'x']], ['postJson', "/api/agency/support/tickets/$ref/confirm"],
            ['postJson', "/api/agency/support/tickets/$ref/feedback", ['rating' => 3]], ['getJson', "/api/tickets/$id/comments"], ['postJson', "/api/tickets/$id/comments", ['body' => 'x']],
            ['getJson', "/api/tickets/$id/attachments"], ['postJson', "/api/tickets/$id/links", ['url' => 'https://example.com']], ['getJson', "/api/tickets/$id/timeline"],
            ['getJson', "/api/tickets/$id/time"],
        ];
        foreach ($agency as $row) {
            [$method, $url] = $row;
            $body = $row[2] ?? [];
            $status = $this->actingAs($outsider)->{$method}($url, $body)->getStatusCode();
            $this->assertContains($status, [403, 404], "other agency: $method $url answered $status");
        }
        // The listing is theirs only.
        $this->assertSame([], $this->actingAs($outsider)->getJson('/api/agency/support/tickets?tab=all')->json('tickets'));

        // The agency itself, on every staff route.
        $staff = [
            ['getJson', '/api/staff/tickets'], ['getJson', "/api/staff/tickets/$ref"], ['postJson', "/api/staff/tickets/$ref/transition", ['to' => 'triage']], ['postJson', "/api/staff/tickets/$ref/assign", ['assigned_to_id' => 1]],
            ['patchJson', "/api/staff/tickets/$ref", ['priority' => 'low']], ['postJson', "/api/staff/tickets/$ref/convert", ['to' => 'change_request']], ['getJson', '/api/staff/dashboard'], ['getJson', '/api/staff/people'],
            ['postJson', "/api/staff/tickets/$ref/scope", ['scope_classification' => 'bug']], ['getJson', "/api/staff/tickets/$ref/time"], ['postJson', "/api/staff/tickets/$ref/time", ['minutes' => 5]],
            ['getJson', "/api/staff/tickets/$ref/qa"], ['postJson', "/api/staff/tickets/$ref/qa", ['items' => []]], ['putJson', "/api/staff/tickets/$ref/bug", ['severity' => 'low']],
            ['getJson', "/api/staff/tickets/$ref/approvals"], ['postJson', "/api/staff/tickets/$ref/approvals", ['kind' => 'scope']], ['getJson', "/api/staff/tickets/$ref/relations"],
            ['postJson', "/api/staff/tickets/$ref/watch"], ['getJson', '/api/staff/settings'], ['putJson', '/api/staff/settings', ['reopen_days' => 1]], ['getJson', '/api/staff/canned-responses'],
            ['getJson', "/api/staff/agencies/{$this->agency->id}/entitlement"], ['putJson', "/api/staff/agencies/{$this->agency->id}/entitlement", ['included_revisions_per_month' => 9]],
            ['patchJson', "/api/staff/people/{$this->u['employee']->id}/capabilities", ['capabilities' => ['qa']]],
        ];
        foreach ($staff as $row) {
            [$method, $url] = $row;
            $body = $row[2] ?? [];
            foreach (['client', 'owner'] as $who) {
                $status = $this->actingAs($this->u[$who])->{$method}($url, $body)->getStatusCode();
                $this->assertSame(403, $status, "$who: $method $url answered $status");
            }
        }
        // A member of staff with no capability: not the queue's contents, and no management.
        $plain = User::factory()->create(['role' => Role::Employee, 'staff_capabilities' => [], 'is_active' => true]);
        foreach ([['getJson', "/api/staff/tickets/$ref"], ['postJson', "/api/staff/tickets/$ref/transition", ['to' => 'triage']], ['getJson', '/api/staff/dashboard'], ['putJson', '/api/staff/settings', ['reopen_days' => 1]]] as $row) {
            [$method, $url] = $row;
            $body = $row[2] ?? [];
            $status = $this->actingAs($plain)->{$method}($url, $body)->getStatusCode();
            $this->assertContains($status, [403, 404], "no capability: $method $url answered $status");
        }
        $this->assertSame('new', $ticket->fresh()->status);
    }
}