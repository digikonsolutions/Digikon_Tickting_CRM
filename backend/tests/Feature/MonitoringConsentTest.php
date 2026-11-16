<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmployeeActivityLog;
use App\Models\EmployeeAssignment;
use App\Models\MonitoringConsent;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkSession;
use App\Notifications\SetPasswordNotification;
use App\Services\ActivityLogger;
use App\Services\MonitoringPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

class MonitoringConsentTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
        $this->u['lead'] = User::factory()->create(['role' => Role::TeamLead]);
        $this->u['qa'] = User::factory()->create(['role' => Role::Qa]);
        $this->u['member'] = User::factory()->create(['role' => Role::AgencyMember, 'agency_id' => $this->agency->id]);
        $this->u['employee']->forceFill(['password' => 'Passw0rd!Passw0rd'])->save();
        $this->ticket = $this->makeTicket('other', 'Landing page', 4);
    }

    /* ---- consent ---------------------------------------------------------------------------- */

    public function test_employee_must_accept_and_acceptance_records_time_and_policy_version(): void
    {
        $me = $this->actingAs($this->u['employee'])->getJson('/api/auth/me')->assertOk();
        $me->assertJsonPath('user.monitoring_consent_required', true);

        $policy = $this->getJson('/api/employee/monitoring-policy')->assertOk();
        $policy->assertJsonPath('policy.version', config('monitoring.version'))->assertJsonPath('consent.accepted', false);
        $this->assertCount(count(config('monitoring.categories')), $policy->json('policy.categories'));

        $this->postJson('/api/employee/monitoring-policy/accept', ['policy_version' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/employee/monitoring-policy/accept', [])->assertUnprocessable();
        $this->assertSame(0, MonitoringConsent::count());

        $this->postJson('/api/employee/monitoring-policy/accept', ['policy_version' => config('monitoring.version')])
            ->assertOk()->assertJsonPath('consent.accepted', true);

        $consent = MonitoringConsent::firstOrFail();
        $this->assertSame($this->u['employee']->id, $consent->user_id);
        $this->assertSame(config('monitoring.version'), $consent->policy_version);
        $this->assertNotNull($consent->accepted_at);
        $this->getJson('/api/auth/me')->assertJsonPath('user.monitoring_consent_required', false);

        // Accepting twice keeps the first record.
        $this->postJson('/api/employee/monitoring-policy/accept', ['policy_version' => config('monitoring.version')])->assertOk();
        $this->assertSame(1, MonitoringConsent::count());
    }

    public function test_a_new_policy_version_asks_again(): void
    {
        app(MonitoringPolicy::class)->accept($this->u['employee'], null);
        config(['monitoring.version' => '99.0']);

        $this->actingAs($this->u['employee'])->getJson('/api/auth/me')->assertJsonPath('user.monitoring_consent_required', true);
    }

    public function test_only_employees_use_the_consent_endpoints(): void
    {
        foreach (['owner', 'client', 'admin', 'lead', 'qa', 'member'] as $who) {
            $this->actingAs($this->u[$who])->getJson('/api/employee/monitoring-policy')->assertForbidden();
            $this->actingAs($this->u[$who])->postJson('/api/employee/monitoring-policy/accept', ['policy_version' => config('monitoring.version')])->assertForbidden();
        }
        $this->assertArrayNotHasKey('monitoring_consent_required', $this->actingAs($this->u['admin'])->getJson('/api/auth/me')->json('user'));
    }

    public function test_nothing_is_recorded_until_the_employee_accepts(): void
    {
        $this->postJson('/api/auth/login', ['email' => $this->u['employee']->email, 'password' => 'Passw0rd!Passw0rd'])->assertOk();
        $this->postJson('/api/employee/heartbeat', ['idle' => false])->assertOk();
        $this->transition('employee', $this->ticket, 'start');

        $this->assertSame(0, EmployeeActivityLog::count());
        $this->assertSame(0, WorkSession::count());

        app(MonitoringPolicy::class)->accept($this->u['employee'], null);
        $this->postJson('/api/employee/heartbeat', ['idle' => false])->assertOk();
        $this->assertSame(1, WorkSession::count());
        $this->assertContains('session_start', EmployeeActivityLog::pluck('event_type')->all());
    }

    /* ---- collection limits ------------------------------------------------------------------ */

    public function test_every_event_type_maps_to_a_category_in_the_disclosure(): void
    {
        foreach (\App\Enums\ActivityEvent::cases() as $event) {
            $this->assertArrayHasKey($event->category(), config('monitoring.categories'), $event->value);
        }
    }

    public function test_events_in_categories_missing_from_the_disclosure_are_not_stored(): void
    {
        app(MonitoringPolicy::class)->accept($this->u['employee'], null);
        $categories = config('monitoring.categories');
        unset($categories['ticket_activity'], $categories['work_sessions'], $categories['connection']);
        config(['monitoring.categories' => $categories]);

        $this->transition('employee', $this->ticket, 'start');
        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$this->ticket->id}/comments", ['body' => 'Hello'])->assertCreated();
        $this->postJson('/api/auth/login', ['email' => $this->u['employee']->email, 'password' => 'Passw0rd!Passw0rd'])->assertOk();
        $this->postJson('/api/employee/heartbeat')->assertOk();

        $this->assertSame(0, WorkSession::count());
        $types = EmployeeActivityLog::pluck('event_type')->all();
        $this->assertNotContains('ticket_status_changed', $types);
        $this->assertNotContains('ticket_comment_added', $types);
        $this->assertNotContains('session_start', $types);
        $this->assertEqualsCanonicalizing(['project_status_changed', 'login'], $types);
        $this->assertNull(EmployeeActivityLog::first()->ip_address);
        $this->assertNull(EmployeeActivityLog::first()->user_agent);
    }

    /* ---- access ----------------------------------------------------------------------------- */

    public function test_non_permitted_roles_get_403_on_every_monitoring_endpoint(): void
    {
        $id = $this->u['employee']->id;
        $query = '?from=2026-01-01&to=2026-01-05';

        foreach (['employee', 'lead', 'qa', 'client', 'member'] as $who) {
            foreach (['admin', 'agency'] as $prefix) {
                foreach (['activity', 'performance'] as $endpoint) {
                    $this->actingAs($this->u[$who])->getJson("/api/{$prefix}/employees/{$id}/{$endpoint}{$query}")
                        ->assertForbidden();
                }
            }
        }

        // Owners may not use the admin-prefixed routes; admins have no agency route.
        foreach (['activity', 'performance'] as $endpoint) {
            $this->actingAs($this->u['owner'])->getJson("/api/admin/employees/{$id}/{$endpoint}")->assertForbidden();
            $this->actingAs($this->u['admin'])->getJson("/api/agency/employees/{$id}/{$endpoint}")->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/admin/employees/{$id}/activity")->assertUnauthorized();
        $this->getJson("/api/agency/employees/{$id}/activity")->assertUnauthorized();
    }

    public function test_owner_sees_only_employees_assigned_to_their_own_agency(): void
    {
        $employee = $this->u['employee'];
        app(MonitoringPolicy::class)->accept($employee, null);
        $assignment = EmployeeAssignment::firstOrFail();
        $started = $assignment->started_at;

        // Recorded before this assignment began: not visible to the owner, visible to the admin.
        EmployeeActivityLog::create(['employee_id' => $employee->id, 'event_type' => 'login', 'occurred_at' => $started->copy()->subDays(3), 'ip_address' => '10.0.0.1']);
        EmployeeActivityLog::create(['employee_id' => $employee->id, 'event_type' => 'login', 'occurred_at' => $started->copy()->addMinute(), 'ip_address' => '10.0.0.2']);
        $range = 'from='.$started->copy()->subDays(10)->toDateString().'&to='.now()->toDateString();

        $owner = $this->actingAs($this->u['owner'])->getJson("/api/agency/employees/{$employee->id}/activity?{$range}")->assertOk();
        $owner->assertJsonPath('pagination.total', 1)->assertJsonPath('events.0.ip_address', null)->assertJsonPath('events.0.user_agent', null);
        $this->actingAs($this->u['owner'])->getJson("/api/agency/employees/{$employee->id}/performance?{$range}")->assertOk();

        $admin = $this->actingAs($this->u['admin'])->getJson("/api/admin/employees/{$employee->id}/activity?{$range}")->assertOk();
        $admin->assertJsonPath('pagination.total', 2);
        $this->assertNotNull($admin->json('events.0.ip_address'));

        // Another agency's owner gets 404, as does everyone once the assignment has ended.
        $outsider = $this->outsider();
        $this->actingAs($outsider)->getJson("/api/agency/employees/{$employee->id}/activity")->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/agency/employees/{$employee->id}/performance")->assertNotFound();

        $assignment->forceFill(['status' => 'ended', 'active_employee_id' => null, 'ended_at' => now()])->save();
        $this->actingAs($this->u['owner'])->getJson("/api/agency/employees/{$employee->id}/activity")->assertNotFound();
        $this->actingAs($this->u['owner'])->getJson("/api/agency/employees/{$this->u['client']->id}/activity")->assertNotFound();
    }

    public function test_ticket_timeline_returns_only_ticket_events(): void
    {
        $employee = $this->u['employee'];
        app(MonitoringPolicy::class)->accept($employee, null);
        $this->transition('employee', $this->ticket, 'start');
        EmployeeActivityLog::create(['employee_id' => $employee->id, 'event_type' => 'idle_detected', 'ticket_id' => $this->ticket->id, 'occurred_at' => now(), 'ip_address' => '10.9.9.9']);
        $this->assertGreaterThan(0, EmployeeActivityLog::where('ticket_id', $this->ticket->id)->count());

        foreach (['admin', 'employee', 'owner', 'client'] as $who) {
            $response = $this->actingAs($this->u[$who])->getJson("/api/tickets/{$this->ticket->id}/timeline")->assertOk();
            $items = $response->json('items') ?? $response->json('events') ?? $response->json('data');
            $this->assertNotEmpty($items, $who);
            $ticketEventIds = $this->ticket->events()->pluck('id')->all();
            foreach ($items as $item) {
                $this->assertContains($item['id'], $ticketEventIds, "{$who}: timeline item is not a ticket event");
                $this->assertNotContains($item['type'], \App\Enums\ActivityEvent::values(), $who);
            }
            $this->assertStringNotContainsString('10.9.9.9', $response->getContent());
            $this->assertStringNotContainsString('idle_detected', $response->getContent());
        }
    }

    /* ---- invite email ----------------------------------------------------------------------- */

    public function test_employee_invite_email_contains_the_disclosure_and_version(): void
    {
        $mail = (new SetPasswordNotification('token', true))->toMail($this->u['employee']);
        $text = implode("\n", array_merge($mail->introLines, $mail->outroLines));

        $this->assertStringContainsString('version '.config('monitoring.version'), $text);
        foreach (app(MonitoringPolicy::class)->disclosure()['categories'] as $category) {
            $this->assertStringContainsString($category['label'], $text);
            $this->assertStringContainsString($category['description'], $text);
        }
        $this->assertStringContainsString('Never recorded', $text);
        $this->assertStringContainsString(config('monitoring.viewers'), $text);
        $this->assertStringContainsString('first time you sign in', $text);

        // The accepted text and the emailed text come from the same source.
        $api = $this->actingAs($this->u['employee'])->getJson('/api/employee/monitoring-policy')->json('policy');
        $this->assertSame($api, app(MonitoringPolicy::class)->disclosure());
    }

    public function test_other_roles_and_plain_resets_do_not_get_the_disclosure(): void
    {
        foreach ([[$this->u['client'], true], [$this->u['employee'], false]] as [$user, $invite]) {
            $mail = (new SetPasswordNotification('token', $invite))->toMail($user);
            $this->assertStringNotContainsString('Never recorded', implode("\n", array_merge($mail->introLines, $mail->outroLines)));
        }
    }

    public function test_activity_logger_is_resolvable_with_the_policy(): void
    {
        $this->assertInstanceOf(ActivityLogger::class, app(ActivityLogger::class));
    }
}
