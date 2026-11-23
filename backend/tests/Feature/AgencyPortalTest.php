<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** The white-label portal: slugs, the anonymous brand lookup, and portal-only sign-in for agency users. */
class AgencyPortalTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'GoodPassword123!';

    private function user(Role $role, ?Agency $agency = null, array $extra = []): User
    {
        return User::factory()->create(['role' => $role, 'agency_id' => $agency?->id, 'password' => self::PASSWORD, 'is_active' => true, ...$extra]);
    }

    private function login(User $user, ?string $slug)
    {
        return $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, ...($slug !== null ? ['agency_slug' => $slug] : [])]);
    }

    public function test_slugs_are_generated_unique_and_never_reserved(): void
    {
        $a = Agency::create(['name' => 'Northwind Studio']);
        $b = Agency::create(['name' => 'Northwind Studio']);
        $c = Agency::create(['name' => 'Admin']);
        $d = Agency::create(['name' => '!!']);

        $this->assertSame('northwind-studio', $a->slug);
        $this->assertSame('northwind-studio-2', $b->slug);
        $this->assertSame('admin-2', $c->slug);
        $this->assertGreaterThanOrEqual(3, strlen($d->slug));
        foreach ([$a, $b, $c, $d] as $agency) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]{3,40}$/', $agency->slug);
        }
    }

    public function test_the_public_brand_lookup_returns_only_the_allowed_keys(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $agency->branding()->create(['display_name' => 'Northwind Digital', 'tagline' => 'Make it work', 'primary_color' => '#0F766E', 'support_email' => 'help@northwind.test', 'logo_url' => 'https://cdn.example/logo.png']);

        $response = $this->getJson('/api/public/agencies/northwind/branding')->assertOk();

        $this->assertSame(['display_name', 'favicon_url', 'logo_url', 'primary_color', 'slug', 'support_email', 'tagline'], collect($response->json('branding'))->keys()->sort()->values()->all());
        $response->assertJsonPath('branding.display_name', 'Northwind Digital')->assertJsonPath('branding.slug', 'northwind');
        $this->assertStringNotContainsString((string) $agency->id.'"', json_encode($response->json()));
        $this->assertStringNotContainsString('status', $response->getContent());
    }

    public function test_unknown_draft_and_suspended_agencies_look_identical(): void
    {
        Agency::create(['name' => 'Gone', 'status' => 'suspended']);
        Agency::create(['name' => 'Soon', 'status' => 'draft']);

        $bodies = [];
        foreach (['gone', 'soon', 'never-existed'] as $slug) {
            $response = $this->getJson("/api/public/agencies/{$slug}/branding")->assertNotFound();
            $bodies[] = $response->getContent();
        }
        $this->assertSame(1, count(array_unique($bodies)));
    }

    public function test_the_public_brand_lookup_is_throttled(): void
    {
        Agency::create(['name' => 'Northwind']);
        RateLimiter::clear('public-branding');

        foreach (range(1, 30) as $_) {
            $this->getJson('/api/public/agencies/northwind/branding')->assertOk();
        }
        $this->getJson('/api/public/agencies/northwind/branding')->assertStatus(429);
    }

    public function test_agency_users_sign_in_only_through_their_own_portal(): void
    {
        $north = Agency::create(['name' => 'Northwind']);
        $sun = Agency::create(['name' => 'Sunrise']);
        $owner = $this->user(Role::AgencyOwner, $north);
        $client = $this->user(Role::AgencyClient, $north);

        $this->login($owner, 'northwind')->assertOk()->assertJsonPath('user.agency.slug', 'northwind');
        $this->app['auth']->forgetGuards();
        $this->login($client, 'NORTHWIND')->assertOk();                                           // case-insensitive
        $this->app['auth']->forgetGuards();

        $generic = ['message' => 'The provided credentials are incorrect.'];
        foreach ([null, 'sunrise', 'nope'] as $wrong) {
            $this->login($owner, $wrong)->assertUnprocessable()->assertExactJson($generic);
            $this->assertGuest();
        }
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'wrong', 'agency_slug' => 'northwind'])->assertUnprocessable()->assertExactJson($generic);
        $this->assertNotNull($sun);
    }

    public function test_internal_roles_sign_in_without_a_slug(): void
    {
        foreach ([Role::Admin, Role::Employee, Role::TeamLead, Role::Qa] as $role) {
            $this->login($this->user($role), null)->assertOk();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_a_suspended_agency_cannot_sign_in_even_through_its_portal(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = $this->user(Role::AgencyOwner, $agency);
        $agency->update(['status' => 'suspended']);

        $this->login($owner, 'northwind')->assertUnprocessable()->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    public function test_the_login_throttle_counts_per_portal(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = $this->user(Role::AgencyOwner, $agency);

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'wrong', 'agency_slug' => 'northwind'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'wrong', 'agency_slug' => 'northwind'])->assertStatus(429);
    }

    public function test_the_agency_block_in_me_uses_the_display_name_and_nothing_internal(): void
    {
        $agency = Agency::create(['name' => 'Northwind Legal Entity Ltd']);
        $agency->branding()->create(['display_name' => 'Northwind']);
        $owner = $this->user(Role::AgencyOwner, $agency);

        $response = $this->actingAs($owner)->getJson('/api/auth/me')->assertOk();

        $response->assertJsonPath('user.agency_name', 'Northwind')->assertJsonPath('user.agency.display_name', 'Northwind');
        $this->assertSame(['display_name', 'favicon_url', 'logo_url', 'primary_color', 'slug', 'support_email', 'tagline'], collect($response->json('user.agency'))->keys()->sort()->values()->all());
        $this->assertStringNotContainsString('Legal Entity', $response->getContent());
    }
}
