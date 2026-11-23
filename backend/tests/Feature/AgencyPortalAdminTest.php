<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** What an admin sets up for a new agency's portal: address and brand, at creation and afterwards. */
class AgencyPortalAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin, 'is_active' => true]);
        // The sign-up throttle (10 a minute) is covered elsewhere; this class makes many requests.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    private function create(array $setup = [], string $email = 'olivia@example.com', string $name = 'Northwind Digital')
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/agency-owners', [
            'mode' => 'draft', 'owner' => ['name' => 'Olivia Owner', 'email' => $email], 'agency' => ['name' => $name], 'setup' => $setup,
        ]);
    }

    public function test_the_wizard_sets_the_portal_address_and_brand_and_returns_the_portal_path(): void
    {
        Notification::fake();

        $response = $this->create(['slug' => 'Northwind-Co', 'display_name' => 'Northwind', 'tagline' => 'Make it work', 'brand_color' => '#0f766e', 'support_email' => 'help@northwind.test'])->assertCreated();

        $response->assertJsonPath('agency.slug', 'northwind-co')->assertJsonPath('agency.portal_path', '/a/northwind-co');
        $agency = Agency::where('slug', 'northwind-co')->firstOrFail();
        $this->assertSame('Northwind', $agency->branding->display_name);
        $this->assertSame('Make it work', $agency->branding->tagline);
        $this->assertSame('#0F766E', $agency->branding->primary_color);
        $this->assertSame('help@northwind.test', $agency->branding->support_email);
    }

    public function test_without_a_slug_one_is_generated_and_collisions_get_a_suffix(): void
    {
        Notification::fake();

        $this->create([], 'a@example.com', 'Sunrise Studio')->assertCreated()->assertJsonPath('agency.slug', 'sunrise-studio');
        $this->create([], 'b@example.com', 'Sunrise Studio')->assertCreated()->assertJsonPath('agency.slug', 'sunrise-studio-2');
    }

    public function test_bad_slugs_and_colours_are_refused(): void
    {
        Agency::create(['name' => 'Taken', 'slug' => 'taken']);

        foreach (['admin', 'api', 'login', 'a', 'taken', 'Has Space', 'ab', 'x_y', '-lead', str_repeat('a', 41)] as $slug) {
            $this->create(['slug' => $slug], 'z'.md5($slug).'@example.com')->assertUnprocessable()->assertJsonValidationErrors('setup.slug');
        }
        $this->create(['brand_color' => 'teal'])->assertUnprocessable()->assertJsonValidationErrors('setup.brand_color');
        $this->create(['brand_color' => '#FFFF99'])->assertUnprocessable()->assertJsonValidationErrors('setup.brand_color');   // too light for text
        $this->create(['support_email' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('setup.support_email');
        $this->assertSame(1, Agency::count());
    }

    public function test_an_admin_can_change_the_slug_and_brand_later_but_nobody_else_can(): void
    {
        $agency = Agency::create(['name' => 'Northwind', 'slug' => 'northwind']);
        $other = Agency::create(['name' => 'Other', 'slug' => 'other']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);

        $this->actingAs($this->admin)->patchJson("/api/admin/agencies/{$agency->id}/branding", ['slug' => 'northwind-new', 'display_name' => 'Northwind New', 'primary_color' => '#0F766E'])
            ->assertOk()->assertJsonPath('branding.slug', 'northwind-new')->assertJsonPath('branding.display_name', 'Northwind New');
        $this->patchJson("/api/admin/agencies/{$agency->id}/branding", ['slug' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->patchJson("/api/admin/agencies/{$agency->id}/branding", ['slug' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->patchJson("/api/admin/agencies/{$agency->id}/branding", ['slug' => 'northwind-new'])->assertOk();                   // its own slug is fine
        $this->patchJson("/api/admin/agencies/{$agency->id}/branding", ['primary_color' => '#FFFF99'])->assertUnprocessable()->assertJsonValidationErrors('primary_color');

        // The old address no longer resolves; the new one does.
        $this->getJson('/api/public/agencies/northwind/branding')->assertNotFound();
        $this->getJson('/api/public/agencies/northwind-new/branding')->assertOk();

        // An owner cannot use the admin endpoint, and the owner-facing branding route never accepts a slug.
        $this->actingAs($owner)->patchJson("/api/admin/agencies/{$agency->id}/branding", ['slug' => 'hijack'])->assertForbidden();
        $this->putJson('/api/agency/branding', ['slug' => 'hijack', 'display_name' => 'Mine'])->assertOk();
        $this->assertSame('northwind-new', $agency->fresh()->slug);
        $this->assertSame('other', $other->fresh()->slug);
    }
}
