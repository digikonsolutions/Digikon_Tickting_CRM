<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Self-service profile and password endpoints, exercised for every role.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'Old-Passw0rd!123';

    private const NEW_PASSWORD = 'Brand-New-Pass#456';

    /**
     * @return array<string, array{Role}>
     */
    public static function roles(): array
    {
        $cases = [];
        foreach (Role::cases() as $role) {
            $cases[$role->value] = [$role];
        }

        return $cases;
    }

    private function makeUser(Role $role, array $attributes = []): User
    {
        $agencyRoles = [Role::AgencyOwner, Role::AgencyMember, Role::AgencyClient];
        $agencyId = in_array($role, $agencyRoles, true)
            ? Agency::firstOrCreate(['name' => 'Northwind'], ['ticket_prefix' => 'NORT'])->id
            : null;

        return User::factory()->create([
            'name' => 'Original Name',
            'role' => $role,
            'agency_id' => $agencyId,
            'password' => self::OLD_PASSWORD,
            ...$attributes,
        ]);
    }

    /* ---- profile: name ---------------------------------------------------------------------- */

    /**
     * @dataProvider roles
     */
    public function test_every_role_can_update_their_name_and_it_persists(Role $role): void
    {
        $user = $this->makeUser($role);

        $this->actingAs($user)->patchJson('/api/auth/profile', ['name' => 'Ada Lovelace'])
            ->assertOk()
            ->assertExactJson(['user' => [
                'id' => $user->id,
                'name' => 'Ada Lovelace',
                'email' => $user->email,
                'role' => $role->value,
                'agency_id' => $user->agency_id,
                'agency_name' => $user->agency_id ? 'Northwind' : null,
            ]]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Ada Lovelace']);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.name', 'Ada Lovelace');
    }

    /**
     * @dataProvider roles
     */
    public function test_names_are_trimmed_and_whitespace_is_collapsed(Role $role): void
    {
        $user = $this->makeUser($role);

        $this->actingAs($user)->patchJson('/api/auth/profile', ['name' => "  Ada \t  Byron\u{00A0}  Lovelace  "])
            ->assertOk()->assertJsonPath('user.name', 'Ada Byron Lovelace');
    }

    /**
     * @dataProvider roles
     */
    public function test_invalid_names_are_rejected_and_nothing_changes(Role $role): void
    {
        $user = $this->makeUser($role);
        $this->actingAs($user);

        foreach (['', '   ', "\t\n", str_repeat('a', 121)] as $bad) {
            $this->patchJson('/api/auth/profile', ['name' => $bad])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        foreach ([['x'], 123, true, ['a' => 'b']] as $bad) {
            $this->patchJson('/api/auth/profile', ['name' => $bad])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->patchJson('/api/auth/profile', [])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertSame('Original Name', $user->fresh()->name);
        // Exactly 120 characters is allowed.
        $this->patchJson('/api/auth/profile', ['name' => str_repeat('a', 120)])->assertOk();
    }

    /**
     * @dataProvider roles
     */
    public function test_protected_fields_are_rejected_and_unchanged(Role $role): void
    {
        $user = $this->makeUser($role);
        $other = Agency::create(['name' => 'Other agency', 'ticket_prefix' => 'OTHR']);
        $this->actingAs($user);

        $attempts = [
            'email' => 'hijack@example.com',
            'role' => Role::Admin->value,
            'agency_id' => $other->id,
            'is_active' => false,
            'password' => 'Sneaky-Passw0rd!99',
        ];
        foreach ($attempts as $field => $value) {
            $this->patchJson('/api/auth/profile', ['name' => 'Changed Name', $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $fresh = $user->fresh();
        $this->assertSame('Original Name', $fresh->name);
        $this->assertSame($user->email, $fresh->email);
        $this->assertSame($role, $fresh->role);
        $this->assertSame($user->agency_id, $fresh->agency_id);
        $this->assertTrue($fresh->is_active);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $fresh->password));
    }

    /**
     * @dataProvider roles
     */
    public function test_an_update_only_ever_touches_the_signed_in_user(Role $role): void
    {
        $user = $this->makeUser($role);
        $neighbour = $this->makeUser($role, ['name' => 'Neighbour']);

        $this->actingAs($user)->patchJson('/api/auth/profile', ['name' => 'Only Me'])->assertOk();

        $this->assertSame('Only Me', $user->fresh()->name);
        $this->assertSame('Neighbour', $neighbour->fresh()->name);
        // There is no way to address another user: the route takes no id, and body ids are ignored.
        $this->patchJson('/api/auth/profile/'.$neighbour->id, ['name' => 'Taken Over'])->assertStatus(404);
        $this->patchJson('/api/auth/profile', ['name' => 'Still Me', 'id' => $neighbour->id, 'user_id' => $neighbour->id])->assertOk();
        $this->assertSame('Neighbour', $neighbour->fresh()->name);
        $this->assertSame('Still Me', $user->fresh()->name);
    }

    public function test_the_old_agency_profile_route_is_gone(): void
    {
        $this->actingAs($this->makeUser(Role::AgencyClient))->patchJson('/api/agency/profile', ['name' => 'x'])->assertStatus(404);
    }

    public function test_unauthenticated_requests_get_401(): void
    {
        $this->patchJson('/api/auth/profile', ['name' => 'Anon'])->assertUnauthorized();
        $this->putJson('/api/auth/password', [
            'current_password' => self::OLD_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnauthorized();
    }

    /* ---- password --------------------------------------------------------------------------- */

    private function passwordPayload(array $overrides = []): array
    {
        return [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            ...$overrides,
        ];
    }

    /**
     * @dataProvider roles
     */
    public function test_every_role_can_change_their_password(Role $role): void
    {
        $user = $this->makeUser($role);
        $beforeToken = $user->remember_token;

        $this->actingAs($user)->putJson('/api/auth/password', $this->passwordPayload())
            ->assertOk()->assertJsonStructure(['message'])->assertJsonMissingPath('user');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $fresh->password));
        $this->assertNotSame($beforeToken, $fresh->remember_token);

        // The real login endpoint agrees: the old password no longer works, the new one does.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::OLD_PASSWORD])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])
            ->assertOk()->assertJsonPath('user.role', $role->value);
    }

    /**
     * @dataProvider roles
     */
    public function test_password_change_validation(Role $role): void
    {
        $user = $this->makeUser($role);
        $this->actingAs($user);
        // Each case is its own attempt; the 5-a-minute throttle has its own tests below.
        $put = function (array $overrides) {
            Cache::flush();

            return $this->putJson('/api/auth/password', $this->passwordPayload($overrides));
        };

        $put(['current_password' => 'Wrong-Current-1!'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $put(['current_password' => ''])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $put(['password' => 'short1A!', 'password_confirmation' => 'short1A!'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $put(['password' => 'alllowercase1234!', 'password_confirmation' => 'alllowercase1234!'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $put(['password' => 'NoNumbersHere!!!', 'password_confirmation' => 'NoNumbersHere!!!'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $put(['password' => 'NoSymbolsHere1234', 'password_confirmation' => 'NoSymbolsHere1234'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $put(['password' => str_repeat('Aa1!', 33), 'password_confirmation' => str_repeat('Aa1!', 33)])->assertUnprocessable()->assertJsonValidationErrors('password');
        $put(['password_confirmation' => 'Different-Passw0rd!1'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $put(['password' => self::OLD_PASSWORD, 'password_confirmation' => self::OLD_PASSWORD])->assertUnprocessable()->assertJsonValidationErrors('password');
        $put(['password' => ['array'], 'password_confirmation' => ['array']])->assertUnprocessable()->assertJsonValidationErrors('password');
        Cache::flush();
        $this->putJson('/api/auth/password', [])->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'password']);

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password), 'No failed attempt may change the password.');
    }

    public function test_other_sessions_are_deleted_and_the_current_one_survives(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->makeUser(Role::Employee);
        $bystander = $this->makeUser(Role::Employee, ['name' => 'Bystander']);

        $row = fn (string $id, int $userId) => ['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp];
        $currentId = Str::random(40);
        DB::table('sessions')->insert([
            $row($currentId, $user->id),
            $row('other-device-1', $user->id),
            $row('other-device-2', $user->id),
            $row('bystander-session', $bystander->id),
        ]);

        // Send the request with the "current" session cookie so the server knows which row is this device.
        $name = config('session.cookie');
        $cookie = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$currentId, false);
        $this->actingAs($user)->withCookie($name, $cookie)->putJson('/api/auth/password', $this->passwordPayload())->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-1']);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-2']);
        $this->assertDatabaseHas('sessions', ['id' => 'bystander-session', 'user_id' => $bystander->id]);
        // The request's own session was kept (and moved to a fresh id), so this device stays signed in.
        $this->assertGreaterThanOrEqual(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('sessions')->whereIn('id', ['other-device-1', 'other-device-2'])->count());
    }

    public function test_password_changes_are_throttled(): void
    {
        $user = $this->makeUser(Role::AgencyClient);
        $this->actingAs($user);

        foreach (range(1, 5) as $attempt) {
            $this->putJson('/api/auth/password', $this->passwordPayload(['current_password' => 'Wrong-Current-1!']))->assertUnprocessable();
        }
        $this->putJson('/api/auth/password', $this->passwordPayload())->assertStatus(429);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password), 'A throttled request must not change anything.');
    }

    public function test_the_throttle_is_per_user(): void
    {
        $first = $this->makeUser(Role::Employee);
        $second = $this->makeUser(Role::Employee);
        $this->actingAs($first);
        foreach (range(1, 5) as $attempt) {
            $this->putJson('/api/auth/password', $this->passwordPayload(['current_password' => 'Wrong-Current-1!']))->assertUnprocessable();
        }
        $this->putJson('/api/auth/password', $this->passwordPayload())->assertStatus(429);

        $this->actingAs($second)->putJson('/api/auth/password', $this->passwordPayload())->assertOk();
    }

    /* ---- deactivated and suspended ---------------------------------------------------------- */

    /**
     * @dataProvider roles
     */
    public function test_deactivated_users_cannot_use_either_endpoint(Role $role): void
    {
        $user = $this->makeUser($role, ['is_active' => false]);

        $this->actingAs($user)->patchJson('/api/auth/profile', ['name' => 'Ghost'])->assertForbidden();
        $this->putJson('/api/auth/password', $this->passwordPayload())->assertForbidden();

        $fresh = $user->fresh();
        $this->assertSame('Original Name', $fresh->name);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $fresh->password));
    }

    public function test_users_of_a_suspended_agency_cannot_use_either_endpoint(): void
    {
        $agency = Agency::create(['name' => 'Frozen', 'ticket_prefix' => 'FRZN', 'status' => 'suspended']);
        foreach ([Role::AgencyOwner, Role::AgencyMember, Role::AgencyClient] as $role) {
            $user = User::factory()->create(['role' => $role, 'agency_id' => $agency->id, 'name' => 'Original Name', 'password' => self::OLD_PASSWORD]);

            $this->actingAs($user)->patchJson('/api/auth/profile', ['name' => 'Ghost'])->assertForbidden();
            $this->putJson('/api/auth/password', $this->passwordPayload())->assertForbidden();

            $this->assertSame('Original Name', $user->fresh()->name);
            $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
        }
    }

    public function test_sign_in_is_refused_for_deactivated_users_and_suspended_agencies(): void
    {
        $inactive = $this->makeUser(Role::Employee, ['is_active' => false]);
        $this->postJson('/api/auth/login', ['email' => $inactive->email, 'password' => self::OLD_PASSWORD])->assertUnprocessable();

        $agency = Agency::create(['name' => 'Frozen', 'ticket_prefix' => 'FRZN', 'status' => 'suspended']);
        $frozen = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id, 'password' => self::OLD_PASSWORD]);
        $this->postJson('/api/auth/login', ['email' => $frozen->email, 'password' => self::OLD_PASSWORD])->assertUnprocessable();
    }
}
