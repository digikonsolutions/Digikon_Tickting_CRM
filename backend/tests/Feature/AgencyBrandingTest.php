<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\AgencyBranding;
use App\Models\User;
use App\Support\ColorContrast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgencyBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function agency(string $name = 'Northwind', string $prefix = 'NORT'): Agency
    {
        return Agency::firstOrCreate(['name' => $name], ['ticket_prefix' => $prefix]);
    }

    private function userIn(Agency $agency, Role $role): User
    {
        return User::factory()->create(['role' => $role, 'agency_id' => $agency->id]);
    }

    private function owner(?Agency $agency = null): User
    {
        return $this->userIn($agency ?? $this->agency(), Role::AgencyOwner);
    }

    /** This machine's PHP has no GD, so build real PNG bytes by hand. */
    private function pngBytes(int $w, int $h): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\x00".str_repeat("\x80", $w);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat($row, $h)))
            .$chunk('IEND', '');
    }

    private function png(string $name = 'logo.png', int $size = 128, int $padKb = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBytes($size, $size).str_repeat('0', $padKb * 1024));
    }

    private function ico(string $name = 'favicon.ico'): UploadedFile
    {
        $png = $this->pngBytes(32, 32);
        $ico = pack('vvv', 0, 1, 1).pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22).$png;

        return UploadedFile::fake()->createWithContent($name, $ico);
    }

    private function files(string $agencyId): array
    {
        return Storage::disk('public')->allFiles("agency-branding/{$agencyId}");
    }

    // ---- reading ----------------------------------------------------------------------------------------

    public function test_every_agency_role_can_read_their_agencys_branding_with_defaults(): void
    {
        $agency = $this->agency();
        foreach ([Role::AgencyOwner, Role::AgencyClient, Role::AgencyMember] as $role) {
            $this->actingAs($this->userIn($agency, $role))
                ->getJson('/api/agency/branding')
                ->assertOk()
                ->assertJsonPath('branding.agency_name', 'Northwind')
                ->assertJsonPath('branding.display_name', null)
                ->assertJsonPath('branding.primary_color', null)
                ->assertJsonPath('branding.theme_mode', 'light')
                ->assertJsonPath('branding.sidebar_style', 'light')
                ->assertJsonPath('branding.corner_style', 'rounded')
                ->assertJsonPath('branding.logo_url', null);
        }
    }

    public function test_guests_get_401_and_internal_roles_get_403_on_every_branding_endpoint(): void
    {
        $this->getJson('/api/agency/branding')->assertUnauthorized();
        $this->putJson('/api/agency/branding', [])->assertUnauthorized();
        $this->deleteJson('/api/agency/branding')->assertUnauthorized();
        $this->postJson('/api/agency/branding/logo', [])->assertUnauthorized();

        foreach ([Role::Admin, Role::Employee, Role::TeamLead, Role::Qa] as $role) {
            $user = User::factory()->create(['role' => $role, 'agency_id' => null]);
            $this->actingAs($user)->getJson('/api/agency/branding')->assertForbidden();
            $this->actingAs($user)->putJson('/api/agency/branding', ['display_name' => 'X'])->assertForbidden();
            $this->actingAs($user)->postJson('/api/agency/branding/logo', ['file' => $this->png()])->assertForbidden();
            $this->actingAs($user)->deleteJson('/api/agency/branding')->assertForbidden();
        }
    }

    public function test_suspended_agencies_get_403(): void
    {
        $agency = $this->agency();
        $owner = $this->owner($agency);
        $agency->update(['status' => 'suspended']);

        $this->actingAs($owner)->getJson('/api/agency/branding')->assertForbidden();
        $this->actingAs($owner)->putJson('/api/agency/branding', ['display_name' => 'X'])->assertForbidden();
    }

    // ---- owner updates ----------------------------------------------------------------------------------

    public function test_owner_can_update_text_colour_and_enum_fields(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->putJson('/api/agency/branding', [
            'display_name' => '  Northwind   Studio ',
            'tagline' => 'Design that ships',
            'welcome_message' => 'Welcome back to your workspace.',
            'primary_color' => '#0f766e',
            'accent_color' => '#4338ca',
            'theme_mode' => 'system',
            'sidebar_style' => 'brand',
            'corner_style' => 'sharp',
        ])->assertOk()
            ->assertJsonPath('branding.display_name', 'Northwind Studio')
            ->assertJsonPath('branding.primary_color', '#0F766E')
            ->assertJsonPath('branding.theme_mode', 'system')
            ->assertJsonPath('branding.sidebar_style', 'brand')
            ->assertJsonPath('branding.corner_style', 'sharp');

        $this->assertDatabaseHas('agency_branding', [
            'agency_id' => $owner->agency_id,
            'tagline' => 'Design that ships',
            'accent_color' => '#4338CA',
        ]);

        // Partial update leaves the other fields alone; empty strings clear text and colours.
        $this->actingAs($owner)->putJson('/api/agency/branding', ['tagline' => '', 'accent_color' => ''])->assertOk()
            ->assertJsonPath('branding.tagline', null)
            ->assertJsonPath('branding.accent_color', null)
            ->assertJsonPath('branding.primary_color', '#0F766E');
    }

    public function test_client_and_member_can_read_but_not_change_anything(): void
    {
        $agency = $this->agency();
        $owner = $this->owner($agency);
        $this->actingAs($owner)->putJson('/api/agency/branding', ['display_name' => 'Brand'])->assertOk();
        $this->actingAs($owner)->postJson('/api/agency/branding/logo', ['file' => $this->png()])->assertOk();

        foreach ([Role::AgencyClient, Role::AgencyMember] as $role) {
            $user = $this->userIn($agency, $role);
            $this->actingAs($user)->getJson('/api/agency/branding')->assertOk()->assertJsonPath('branding.display_name', 'Brand');
            $this->actingAs($user)->putJson('/api/agency/branding', ['display_name' => 'Hacked'])->assertForbidden();
            $this->actingAs($user)->postJson('/api/agency/branding/logo', ['file' => $this->png()])->assertForbidden();
            $this->actingAs($user)->postJson('/api/agency/branding/favicon', ['file' => $this->ico()])->assertForbidden();
            $this->actingAs($user)->deleteJson('/api/agency/branding/logo')->assertForbidden();
            $this->actingAs($user)->deleteJson('/api/agency/branding')->assertForbidden();
        }

        $this->assertSame('Brand', AgencyBranding::first()->display_name);
        $this->assertNotNull(AgencyBranding::first()->logo_path);
    }

    // ---- tenant isolation -------------------------------------------------------------------------------

    public function test_an_owner_only_ever_touches_their_own_agency_even_when_passing_another_agency_id(): void
    {
        $a = $this->agency('Alpha', 'ALPH');
        $b = $this->agency('Beta', 'BETA');
        $ownerA = $this->owner($a);
        $ownerB = $this->owner($b);
        $this->actingAs($ownerB)->putJson('/api/agency/branding', ['display_name' => 'Beta Brand', 'primary_color' => '#0F766E'])->assertOk();
        $this->actingAs($ownerB)->postJson('/api/agency/branding/logo', ['file' => $this->png()])->assertOk();
        $betaFiles = $this->files((string) $b->id);

        // A reads its own (empty) branding, never B's.
        $this->actingAs($ownerA)->getJson('/api/agency/branding?agency_id='.$b->id)->assertOk()
            ->assertJsonPath('branding.display_name', null)
            ->assertJsonPath('branding.logo_url', null);

        // agency_id in the body is rejected outright.
        $this->actingAs($ownerA)->putJson('/api/agency/branding', ['display_name' => 'Pwn', 'agency_id' => $b->id])
            ->assertStatus(422)->assertJsonValidationErrors('agency_id');
        $this->actingAs($ownerA)->postJson('/api/agency/branding/logo', ['file' => $this->png(), 'agency_id' => $b->id])
            ->assertStatus(422)->assertJsonValidationErrors('agency_id');

        // A's writes and deletes land on A only.
        $this->actingAs($ownerA)->putJson('/api/agency/branding', ['display_name' => 'Alpha Brand'])->assertOk();
        $this->actingAs($ownerA)->deleteJson('/api/agency/branding')->assertOk();

        $beta = AgencyBranding::where('agency_id', $b->id)->first();
        $this->assertSame('Beta Brand', $beta->display_name);
        $this->assertSame($betaFiles, $this->files((string) $b->id));
        $this->assertNotEmpty($betaFiles);
    }

    public function test_a_client_of_another_agency_does_not_see_the_branding(): void
    {
        $a = $this->agency('Alpha', 'ALPH');
        $b = $this->agency('Beta', 'BETA');
        $this->actingAs($this->owner($a))->putJson('/api/agency/branding', ['display_name' => 'Alpha Brand'])->assertOk();

        $clientB = $this->userIn($b, Role::AgencyClient);
        $this->actingAs($clientB)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.branding.display_name', null);
    }

    // ---- uploads ----------------------------------------------------------------------------------------

    public function test_owner_can_upload_and_remove_logo_collapsed_logo_and_favicon(): void
    {
        $owner = $this->owner();
        $dir = 'agency-branding/'.$owner->agency_id;

        $r = $this->actingAs($owner)->postJson('/api/agency/branding/logo', ['file' => $this->png('../../evil name.PNG')])->assertOk();
        $logoUrl = $r->json('branding.logo_url');
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/storage/'.$dir.'/', $logoUrl);
        $this->assertStringNotContainsString('evil', $logoUrl);
        $this->assertMatchesRegularExpression('#/[A-Za-z0-9]{40}\.png$#', $logoUrl);

        $this->actingAs($owner)->postJson('/api/agency/branding/logo-collapsed', ['file' => $this->png('mark.png', 96)])
            ->assertOk()->assertJsonPath('branding.logo_collapsed_url', fn ($v) => $v !== null);
        $this->actingAs($owner)->postJson('/api/agency/branding/favicon', ['file' => $this->ico()])
            ->assertOk()->assertJsonPath('branding.favicon_url', fn ($v) => str_ends_with($v, '.ico'));

        $this->assertCount(3, $this->files((string) $owner->agency_id));

        $this->actingAs($owner)->deleteJson('/api/agency/branding/logo')->assertOk()->assertJsonPath('branding.logo_url', null);
        $this->actingAs($owner)->deleteJson('/api/agency/branding/favicon')->assertOk()->assertJsonPath('branding.favicon_url', null);
        $this->assertCount(1, $this->files((string) $owner->agency_id));
    }

    public function test_replacing_a_file_deletes_the_old_one(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/api/agency/branding/logo', ['file' => $this->png()])->assertOk();
        $first = AgencyBranding::first()->logo_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($owner)->postJson('/api/agency/branding/logo', ['file' => $this->png('b.png', 100)])->assertOk();
        $second = AgencyBranding::first()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->assertCount(1, $this->files((string) $owner->agency_id));
    }

    public function test_reset_clears_everything_and_deletes_the_files(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->putJson('/api/agency/branding', [
            'display_name' => 'Brand', 'primary_color' => '#0F766E', 'theme_mode' => 'dark', 'corner_style' => 'sharp',
        ])->assertOk();
        $this->actingAs($owner)->postJson('/api/agency/branding/logo', ['file' => $this->png()])->assertOk();
        $this->actingAs($owner)->postJson('/api/agency/branding/favicon', ['file' => $this->ico()])->assertOk();

        $this->actingAs($owner)->deleteJson('/api/agency/branding')->assertOk()
            ->assertJsonPath('branding.display_name', null)
            ->assertJsonPath('branding.primary_color', null)
            ->assertJsonPath('branding.theme_mode', 'light')
            ->assertJsonPath('branding.corner_style', 'rounded')
            ->assertJsonPath('branding.logo_url', null)
            ->assertJsonPath('branding.favicon_url', null);

        $this->assertSame([], $this->files((string) $owner->agency_id));
    }

    // ---- validation -------------------------------------------------------------------------------------

    public function test_invalid_colours_enums_and_lengths_are_rejected(): void
    {
        $owner = $this->owner();

        foreach (['red', '#FFF', '#12345G', '0F766E', '#0F766E00'] as $bad) {
            $this->actingAs($owner)->putJson('/api/agency/branding', ['primary_color' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('primary_color');
        }
        $this->actingAs($owner)->putJson('/api/agency/branding', ['accent_color' => 'blue'])->assertStatus(422)->assertJsonValidationErrors('accent_color');
        $this->actingAs($owner)->putJson('/api/agency/branding', ['theme_mode' => 'neon'])->assertStatus(422)->assertJsonValidationErrors('theme_mode');
        $this->actingAs($owner)->putJson('/api/agency/branding', ['sidebar_style' => 'x'])->assertStatus(422)->assertJsonValidationErrors('sidebar_style');
        $this->actingAs($owner)->putJson('/api/agency/branding', ['corner_style' => 'x'])->assertStatus(422)->assertJsonValidationErrors('corner_style');
        $this->actingAs($owner)->putJson('/api/agency/branding', ['display_name' => str_repeat('a', 121)])->assertStatus(422)->assertJsonValidationErrors('display_name');
        $this->actingAs($owner)->putJson('/api/agency/branding', ['tagline' => str_repeat('a', 161)])->assertStatus(422)->assertJsonValidationErrors('tagline');
        $this->actingAs($owner)->putJson('/api/agency/branding', ['welcome_message' => str_repeat('a', 241)])->assertStatus(422)->assertJsonValidationErrors('welcome_message');
    }

    public function test_low_contrast_colours_are_rejected_with_a_clear_message(): void
    {
        $owner = $this->owner();

        // The stock ClientHub teal (#0D9488) is 3.7:1 on white, so it is too light for white button text.
        $this->actingAs($owner)->putJson('/api/agency/branding', ['primary_color' => '#0D9488'])
            ->assertStatus(422)->assertJsonValidationErrors('primary_color')
            ->assertJsonPath('errors.primary_color.0', fn ($m) => str_contains($m, 'too light') && str_contains($m, '4.5:1'));
        $this->actingAs($owner)->putJson('/api/agency/branding', ['primary_color' => '#FFFF00'])->assertStatus(422);
        $this->actingAs($owner)->putJson('/api/agency/branding', ['accent_color' => '#F59E0B'])
            ->assertStatus(422)->assertJsonValidationErrors('accent_color');

        $this->assertNull(AgencyBranding::first()?->primary_color);
        $this->actingAs($owner)->putJson('/api/agency/branding', ['primary_color' => '#0F766E', 'accent_color' => '#4338CA'])->assertOk();
    }

    public function test_contrast_maths_matches_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, ColorContrast::ratio('#000000', '#FFFFFF'), 0.01);
        $this->assertEqualsWithDelta(1.0, ColorContrast::ratio('#FFFFFF', '#FFFFFF'), 0.001);
        $this->assertTrue(ColorContrast::passesOnWhite('#0F766E'));
        $this->assertFalse(ColorContrast::passesOnWhite('#0D9488'));
    }

    public function test_bad_types_sizes_and_dimensions_are_rejected(): void
    {
        $owner = $this->owner();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert(1)</script></svg>');
        $php = UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;');
        $pdf = UploadedFile::fake()->createWithContent('logo.pdf', "%PDF-1.4\n");

        // SVG and non-image content are refused for logos and favicons alike.
        foreach (['logo', 'logo-collapsed', 'favicon'] as $kind) {
            foreach ([$svg, $php, $pdf] as $bad) {
                $this->actingAs($owner)->postJson("/api/agency/branding/{$kind}", ['file' => $bad])
                    ->assertStatus(422)->assertJsonValidationErrors('file');
            }
        }
        // An .ico is not a valid logo format.
        $this->actingAs($owner)->postJson('/api/agency/branding/logo', ['file' => $this->ico()])->assertStatus(422);

        // Missing file.
        $this->actingAs($owner)->postJson('/api/agency/branding/logo', [])->assertStatus(422)->assertJsonValidationErrors('file');

        // Oversized: logo > 2 MB, favicon > 512 KB.
        $this->actingAs($owner)->postJson('/api/agency/branding/logo', ['file' => $this->png('big.png', 128, 2100)])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($owner)->postJson('/api/agency/branding/favicon', ['file' => $this->png('fav.png', 32, 600)])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        // Too small.
        $this->actingAs($owner)->postJson('/api/agency/branding/logo', ['file' => $this->png('tiny.png', 32)])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame([], $this->files((string) $owner->agency_id));
    }

    // ---- /auth/me ---------------------------------------------------------------------------------------

    public function test_auth_me_carries_branding_for_agency_users_and_null_for_everyone_else(): void
    {
        $agency = $this->agency();
        $owner = $this->owner($agency);
        $this->actingAs($owner)->putJson('/api/agency/branding', ['display_name' => 'Brand', 'primary_color' => '#0F766E'])->assertOk();
        $this->actingAs($owner)->postJson('/api/agency/branding/logo', ['file' => $this->png()])->assertOk();

        foreach ([Role::AgencyOwner, Role::AgencyClient, Role::AgencyMember] as $role) {
            $this->actingAs($this->userIn($agency, $role))->getJson('/api/auth/me')->assertOk()
                ->assertJsonPath('user.branding.display_name', 'Brand')
                ->assertJsonPath('user.branding.primary_color', '#0F766E')
                ->assertJsonPath('user.branding.logo_url', fn ($v) => $v !== null);
        }

        // An agency that never customised anything still gets the (default) payload, not null.
        $other = $this->agency('Other', 'OTHR');
        $this->actingAs($this->userIn($other, Role::AgencyClient))->getJson('/api/auth/me')
            ->assertJsonPath('user.branding.agency_name', 'Other')
            ->assertJsonPath('user.branding.primary_color', null);

        foreach ([Role::Admin, Role::Employee, Role::TeamLead, Role::Qa] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'agency_id' => null]))
                ->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.branding', null);
        }
    }
}
