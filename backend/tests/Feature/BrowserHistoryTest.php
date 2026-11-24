<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BrowserHistoryEvent;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\ActivityLogger;
use App\Services\MonitoringPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

class BrowserHistoryTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
        $this->u['lead'] = User::factory()->create(['role' => Role::TeamLead]);
        $this->u['employee2'] = User::factory()->create(['role' => Role::Employee]);
        // Browser history is only kept while work is in progress, so both employees are working (on projects
        // without tickets) for the whole time these tests send events. BrowserHistoryWorkLinkTest covers the rules.
        $this->project->update(['status' => 'in_progress', 'started_at' => now()->subYears(2)]);
        WorkItem::create([
            'agency_id' => $this->agency->id, 'client_id' => $this->u['client']->id, 'employee_id' => $this->u['employee2']->id,
            'title' => 'Second employee project', 'description' => 'd', 'estimated_hours' => 6, 'start_date' => now()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(), 'status' => 'in_progress', 'started_at' => now()->subYears(2),
        ]);
    }

    private function consent(string $who = 'employee'): void
    {
        app(MonitoringPolicy::class)->accept($this->u[$who], null);
    }

    private function event(string $id, array $overrides = []): array
    {
        return array_merge([
            'client_event_id' => $id,
            'event_type' => 'navigated',
            'tab_session_id' => 'tab-1',
            'tab_id' => 7,
            'url' => 'https://Example.com/docs/page?token=SECRET#frag',
            'title' => 'Docs',
            'favicon_url' => 'https://example.com/favicon.ico',
            'duration_seconds' => 30,
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ], $overrides);
    }

    /* ---- consent gate ----------------------------------------------------------------------- */

    public function test_nothing_is_stored_before_the_employee_accepts_the_notice(): void
    {
        $this->actingAs($this->u['employee'])->getJson('/api/browser-history/status')->assertOk()->assertJsonPath('tracking_allowed', false);
        $this->postJson('/api/browser-history/sync', ['events' => [$this->event('a')]])->assertForbidden()->assertJsonPath('consent_required', true);
        $this->assertSame(0, BrowserHistoryEvent::count());

        $this->consent();
        $this->getJson('/api/browser-history/status')->assertJsonPath('tracking_allowed', true);
        $this->postJson('/api/browser-history/sync', ['events' => [$this->event('a')]])->assertOk()->assertJsonPath('accepted', 1);
    }

    public function test_the_category_must_be_listed_in_the_disclosure(): void
    {
        $this->consent();
        $categories = config('monitoring.categories');
        unset($categories['browser_history']);
        config(['monitoring.categories' => $categories]);

        $this->actingAs($this->u['employee'])->postJson('/api/browser-history/sync', ['events' => [$this->event('a')]])->assertForbidden();
        $this->assertSame(0, BrowserHistoryEvent::count());
    }

    public function test_the_notice_and_invite_email_disclose_browser_history(): void
    {
        $this->assertArrayHasKey('browser_history', config('monitoring.categories'));
        $text = implode("\n", app(MonitoringPolicy::class)->disclosureLines());
        $this->assertStringContainsString('Browser tab history', $text);
        $this->assertStringContainsString('version '.config('monitoring.version'), $text);
        $this->assertNotSame('1.0', config('monitoring.version'));
    }

    /* ---- sync ------------------------------------------------------------------------------- */

    public function test_urls_are_stored_without_query_or_fragment_and_non_web_pages_are_skipped(): void
    {
        $this->consent();
        $response = $this->actingAs($this->u['employee'])->postJson('/api/browser-history/sync', ['events' => [
            $this->event('a'),
            $this->event('b', ['url' => 'chrome://settings/passwords']),
            $this->event('c', ['url' => 'file:///C:/secret.txt']),
            $this->event('d', ['favicon_url' => 'http://insecure.example/f.ico']),
        ]])->assertOk();

        $response->assertJsonPath('accepted', 2)->assertJsonPath('skipped', 2);
        $row = BrowserHistoryEvent::where('client_event_id', 'a')->firstOrFail();
        $this->assertSame('https://example.com/docs/page', $row->url);
        $this->assertSame('example.com', $row->domain);
        $this->assertStringNotContainsString('SECRET', $row->url);
        $this->assertNull(BrowserHistoryEvent::where('client_event_id', 'd')->value('favicon_url'));
        $this->assertSame($this->u['employee']->id, $row->user_id);
    }

    public function test_resending_a_batch_does_not_create_duplicates(): void
    {
        $this->consent();
        $batch = ['events' => [$this->event('a'), $this->event('b', ['event_type' => 'tab_closed'])]];
        $this->actingAs($this->u['employee'])->postJson('/api/browser-history/sync', $batch)->assertOk()->assertJsonPath('accepted', 2);
        $this->postJson('/api/browser-history/sync', $batch)->assertOk()->assertJsonPath('accepted', 0)->assertJsonPath('duplicates', 2);
        $this->assertSame(2, BrowserHistoryEvent::count());
    }

    public function test_the_same_client_id_from_two_employees_is_two_records(): void
    {
        $this->consent();
        $this->consent('employee2');
        $this->actingAs($this->u['employee'])->postJson('/api/browser-history/sync', ['events' => [$this->event('same')]])->assertOk();
        $this->actingAs($this->u['employee2'])->postJson('/api/browser-history/sync', ['events' => [$this->event('same')]])->assertOk();
        $this->assertSame(2, BrowserHistoryEvent::count());
    }

    public function test_input_is_validated(): void
    {
        $this->consent();
        $this->actingAs($this->u['employee']);
        $this->postJson('/api/browser-history/sync', [])->assertUnprocessable();
        $this->postJson('/api/browser-history/sync', ['events' => [$this->event('a', ['event_type' => 'keystroke'])]])->assertUnprocessable();
        $this->postJson('/api/browser-history/sync', ['events' => [$this->event('a', ['duration_seconds' => 999999])]])->assertUnprocessable();
        $this->postJson('/api/browser-history/sync', ['events' => array_map(fn ($i) => $this->event("e{$i}"), range(1, 201))])->assertUnprocessable();
        // Far-future and ancient timestamps are skipped, not stored.
        $this->postJson('/api/browser-history/sync', ['events' => [
            $this->event('future', ['occurred_at' => now()->addDays(2)->toIso8601String()]),
            $this->event('ancient', ['occurred_at' => now()->subYears(5)->toIso8601String()]),
        ]])->assertOk()->assertJsonPath('accepted', 0)->assertJsonPath('skipped', 2);
    }

    public function test_only_employees_can_sync(): void
    {
        foreach (['owner', 'client', 'admin', 'lead'] as $who) {
            $this->actingAs($this->u[$who])->postJson('/api/browser-history/sync', ['events' => [$this->event('a')]])->assertForbidden();
            $this->actingAs($this->u[$who])->getJson('/api/browser-history/status')->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/browser-history/sync', ['events' => [$this->event('a')]])->assertUnauthorized();
        $this->assertSame(0, BrowserHistoryEvent::count());
    }

    /* ---- admin: read only ------------------------------------------------------------------- */

    private function seedHistory(): void
    {
        // The employee has been assigned to the agency since yesterday, so today's events fall inside it.
        EmployeeAssignment::query()->update(['started_at' => now()->subDay()]);
        $this->consent();
        $this->consent('employee2');
        $this->actingAs($this->u['employee'])->postJson('/api/browser-history/sync', ['events' => [
            $this->event('a1', ['event_type' => 'tab_opened', 'url' => 'https://github.com/acme/repo', 'title' => 'Repo', 'duration_seconds' => null]),
            $this->event('a2', ['url' => 'https://github.com/acme/issues', 'title' => 'Issues', 'duration_seconds' => 60]),
            $this->event('a3', ['url' => 'https://news.example.org/a', 'title' => 'Headlines', 'duration_seconds' => 120]),
            $this->event('a4', ['event_type' => 'tab_closed', 'url' => 'https://news.example.org/a', 'duration_seconds' => 10]),
        ]])->assertOk();
        $this->actingAs($this->u['employee2'])->postJson('/api/browser-history/sync', ['events' => [
            $this->event('b1', ['url' => 'https://other.example.com/x', 'title' => 'Other', 'duration_seconds' => 5]),
        ]])->assertOk();
    }

    public function test_admin_can_view_search_and_filter_history(): void
    {
        $this->seedHistory();
        $admin = $this->actingAs($this->u['admin']);

        $all = $admin->getJson('/api/admin/browser-history/events')->assertOk();
        $all->assertJsonPath('pagination.total', 5);
        $this->assertSame('https://other.example.com/x', collect($all->json('events'))->firstWhere('id', BrowserHistoryEvent::where('client_event_id', 'b1')->value('id'))['url']);

        $this->getJson('/api/admin/browser-history/events?employee_id='.$this->u['employee']->id)->assertJsonPath('pagination.total', 4);
        $this->getJson('/api/admin/browser-history/events?domain=github')->assertJsonPath('pagination.total', 2);
        $this->getJson('/api/admin/browser-history/events?q=headlines')->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/admin/browser-history/events?event_type=tab_closed')->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/admin/browser-history/events?from='.now()->addDay()->toDateString())->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/admin/browser-history/events?agency_id='.$this->agency->id)->assertJsonPath('pagination.total', 4);
        $this->getJson('/api/admin/browser-history/events?per_page=2&page=2')->assertJsonCount(2, 'events')->assertJsonPath('pagination.last_page', 3);
        $this->getJson('/api/admin/browser-history/events?event_type=bogus')->assertUnprocessable();
    }

    public function test_admin_summary_statistics(): void
    {
        $this->seedHistory();
        $summary = $this->actingAs($this->u['admin'])->getJson('/api/admin/browser-history/summary?employee_id='.$this->u['employee']->id)->assertOk();

        $summary->assertJsonPath('totals.visits', 3)   // a1 tab_opened, a2, a3 navigated
            ->assertJsonPath('totals.tabs_opened', 1)
            ->assertJsonPath('totals.unique_domains', 2)
            ->assertJsonPath('totals.seconds', 190);
        $top = collect($summary->json('top_domains'));
        $this->assertSame('github.com', $top->first()['domain']);
        $this->assertSame(2, $top->first()['visits']);
        $this->assertCount(1, $summary->json('daily'));
    }

    public function test_other_roles_cannot_read_history(): void
    {
        $this->seedHistory();
        foreach (['employee', 'owner', 'client', 'lead'] as $who) {
            $this->actingAs($this->u[$who])->getJson('/api/admin/browser-history/events')->assertForbidden();
            $this->actingAs($this->u[$who])->getJson('/api/admin/browser-history/summary')->assertForbidden();
        }
    }

    public function test_admin_access_is_strictly_read_only(): void
    {
        $this->seedHistory();
        $id = BrowserHistoryEvent::firstOrFail()->id;
        $before = BrowserHistoryEvent::orderBy('id')->get()->toArray();
        $this->actingAs($this->u['admin']);

        // No write route exists for admins: every write method is refused.
        foreach (['/api/admin/browser-history/events', "/api/admin/browser-history/events/{$id}", '/api/admin/browser-history/summary', '/api/browser-history/sync'] as $url) {
            foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
                $status = $this->{$method}($url, ['events' => [$this->event('hack')], 'url' => 'https://evil.test'])->getStatusCode();
                $this->assertContains($status, [403, 404, 405], "{$method} {$url} returned {$status}");
            }
        }

        $this->assertSame($before, BrowserHistoryEvent::orderBy('id')->get()->toArray());
    }

    public function test_policy_and_model_refuse_every_write_even_for_admins(): void
    {
        $this->seedHistory();
        $event = BrowserHistoryEvent::firstOrFail();
        $admin = $this->u['admin'];

        $this->assertTrue($admin->can('viewAny', BrowserHistoryEvent::class));
        $this->assertTrue($admin->can('view', $event));
        $this->assertFalse($admin->can('create', BrowserHistoryEvent::class));
        $this->assertFalse($admin->can('update', $event));
        $this->assertFalse($admin->can('delete', $event));
        $this->assertFalse($this->u['employee']->can('viewAny', BrowserHistoryEvent::class));

        $this->expectException(LogicException::class);
        $event->update(['title' => 'tampered']);
    }

    public function test_model_refuses_deletion(): void
    {
        $this->seedHistory();
        $this->expectException(LogicException::class);
        BrowserHistoryEvent::firstOrFail()->delete();
    }

    public function test_retention_pruning_removes_old_events_only(): void
    {
        $this->seedHistory();
        BrowserHistoryEvent::query()->where('client_event_id', 'a1')->toBase()->update(['occurred_at' => now()->subDays(400)]);

        app(ActivityLogger::class)->prune();

        $this->assertSame(4, BrowserHistoryEvent::count());
        $this->assertFalse(BrowserHistoryEvent::where('client_event_id', 'a1')->exists());
    }
}
