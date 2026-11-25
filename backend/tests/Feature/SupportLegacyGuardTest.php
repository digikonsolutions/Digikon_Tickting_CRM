<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** The older project-ticket endpoints must never be a way around the support workflow. */
class SupportLegacyGuardTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    public function test_old_endpoints_refuse_support_tickets_instead_of_failing_or_skipping_the_workflow(): void
    {
        $ref = $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets', ['type' => 'technical_support', 'title' => 'Help', 'description' => 'Please.', 'expected_result' => 'Fixed.', 'details' => ['goal' => 'x']])->assertCreated()->json('ticket.ref');
        $ticket = Ticket::query()->where('ref', $ref)->firstOrFail();
        $ticket->forceFill(['assigned_to_id' => $this->u['employee']->id])->save();
        $before = $ticket->fresh()->only(['status', 'title', 'priority']);

        foreach ([
            ['client', 'patchJson', "/api/agency/tickets/{$ticket->id}", ['title' => 'Changed']],
            ['client', 'deleteJson', "/api/agency/tickets/{$ticket->id}", []],
            ['employee', 'patchJson', "/api/tickets/{$ticket->id}/status", ['status' => 'in_review']],
            ['employee', 'postJson', "/api/tickets/{$ticket->id}/transitions", ['action' => 'start']],
            ['client', 'getJson', "/api/tickets/key/{$ticket->key}", []],
            ['employee', 'postJson', "/api/tickets/{$ticket->id}/hours-requests", ['hours' => 1, 'reason' => 'x']],
        ] as [$who, $method, $url, $body]) {
            $status = $this->actingAs($this->u[$who])->{$method}($url, $body)->getStatusCode();
            $this->assertContains($status, [403, 404, 422], "$method $url answered $status");
        }
        $this->assertSame($before, $ticket->fresh()->only(['status', 'title', 'priority']));
    }
}