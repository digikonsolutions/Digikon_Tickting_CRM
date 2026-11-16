<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function user(Role $role = Role::AgencyOwner): User
    {
        $agency = Agency::firstOrCreate(['name' => 'Northwind'], ['ticket_prefix' => 'NORT']);

        return User::factory()->create(['role' => $role, 'agency_id' => $role->isAgencyRole() ? $agency->id : null, 'name' => 'Original Name']);
    }

    // This machine's PHP has no GD, so tests use real (tiny) image bytes instead of UploadedFile::fake()->image().
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const JPG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private const WEBP = 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';

    private function png(string $name = 'me.png', int $padKb = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG).str_repeat('0', $padKb * 1024));
    }

    private function jpg(string $name = 'me.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::JPG));
    }

    public function test_every_role_can_upload_a_photo_and_other_fields_are_untouched(): void
    {
        foreach (Role::cases() as $role) {
            $user = $this->user($role);
            $email = $user->email;

            $response = $this->actingAs($user)->postJson('/api/auth/profile/photo', ['photo' => $this->png()])->assertOk();
            $url = $response->json('user.photo_url');
            $this->assertNotNull($url, $role->value);
            $user->refresh();
            Storage::disk('public')->assertExists($user->photo_path);
            $this->assertSame('Original Name', $user->name);
            $this->assertSame($email, $user->email);
            $this->getJson('/api/auth/me')->assertJsonPath('user.photo_url', $url);
        }
    }

    public function test_replacing_a_photo_removes_the_old_file(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/api/auth/profile/photo', ['photo' => $this->png('a.png')])->assertOk();
        $first = $user->fresh()->photo_path;

        $this->postJson('/api/auth/profile/photo', ['photo' => $this->jpg('b.jpg')])->assertOk();
        $second = $user->fresh()->photo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_jpg_jpeg_png_and_webp_are_accepted(): void
    {
        $user = $this->user();
        foreach (['a.jpg', 'a.jpeg', 'a.png', 'a.webp'] as $name) {
            $file = match (pathinfo($name, PATHINFO_EXTENSION)) {
                'webp' => UploadedFile::fake()->createWithContent($name, base64_decode(self::WEBP)),
                'png' => $this->png($name),
                default => $this->jpg($name),
            };
            $this->actingAs($user)->postJson('/api/auth/profile/photo', ['photo' => $file])->assertOk();
        }
    }

    public function test_bad_files_are_rejected_on_the_server(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->postJson('/api/auth/profile/photo', [])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->postJson('/api/auth/profile/photo', ['photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->postJson('/api/auth/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('x.gif', 'GIF89a'.str_repeat('0', 40))])->assertUnprocessable()->assertJsonValidationErrors('photo');
        // Content must match the extension: a script renamed to .png is refused.
        $this->postJson('/api/auth/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1;')])->assertUnprocessable();
        // Too large.
        $this->postJson('/api/auth/profile/photo', ['photo' => $this->png('big.png', 6000)])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertNull($user->fresh()->photo_path);
    }

    public function test_photo_can_be_removed_and_guests_cannot_upload(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/api/auth/profile/photo', ['photo' => $this->png()])->assertOk();
        $path = $user->fresh()->photo_path;

        $this->deleteJson('/api/auth/profile/photo')->assertOk()->assertJsonPath('user.photo_url', null);
        Storage::disk('public')->assertMissing($path);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/profile/photo', ['photo' => $this->png()])->assertUnauthorized();
    }

    public function test_photo_url_is_not_mass_assignable_through_profile_update(): void
    {
        $user = $this->user();
        $this->actingAs($user)->patchJson('/api/auth/profile', ['name' => 'New Name', 'photo_path' => 'avatars/evil.png'])->assertOk();
        $this->assertNull($user->fresh()->photo_path);
    }
}
