<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Emails to agency users carry only that agency's brand; staff keep the ClientHub brand. */
class BrandedMailTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://app.example.test', 'app.name' => 'Laravel', 'mail.from.address' => 'noreply@example.test', 'mail.from.name' => 'Laravel']);
        $this->agency = Agency::create(['name' => 'Northwind Legal Ltd', 'slug' => 'northwind']);
        $this->agency->branding()->create([
            'display_name' => 'Northwind', 'logo_url' => 'https://cdn.example.test/northwind.png', 'primary_color' => '#0F766E',
            'support_email' => 'help@northwind.test', 'email_from_name' => 'Northwind Support',
        ]);
    }

    private function user(Role $role, bool $agency = true): User
    {
        return User::factory()->create(['role' => $role, 'agency_id' => $agency ? $this->agency->id : null, 'is_active' => true]);
    }

    private function mail(User $user, bool $invite)
    {
        return (new SetPasswordNotification('the-token', $invite))->toMail($user);
    }

    private function assertAgencyBrandOnly(string $html, string $text): void
    {
        $this->assertStringContainsString('Northwind', $html);
        $this->assertStringContainsString('https://cdn.example.test/northwind.png', $html);
        $this->assertStringContainsString('#0F766E', $html);
        $this->assertStringContainsString("\u{A9} ".date('Y').' Northwind', $html);
        $this->assertStringContainsString('The Northwind team', $html);
        foreach ([$html, $text] as $body) {
            $this->assertStringNotContainsStringIgnoringCase('laravel', $body);
            $this->assertStringNotContainsStringIgnoringCase('clienthub', $body);
            $this->assertStringNotContainsString('Legal Ltd', $body);
        }
    }

    public function test_an_owner_invite_is_branded_and_points_at_the_portal(): void
    {
        $owner = $this->user(Role::AgencyOwner);
        $mail = $this->mail($owner, true);

        $this->assertSame('Set your password – Northwind', $mail->subject);
        $this->assertSame(['noreply@example.test', 'Northwind Support'], $mail->from);
        $this->assertSame([['help@northwind.test', 'Northwind']], $mail->replyTo);
        $this->assertStringStartsWith('https://app.example.test/a/northwind/reset-password?', $mail->actionUrl);
        $this->assertStringContainsString('invite=1', $mail->actionUrl);
        $this->assertStringContainsString('token=the-token', $mail->actionUrl);
        $this->assertStringContainsString('email='.rawurlencode($owner->email), $mail->actionUrl);
        $this->assertAgencyBrandOnly((string) $mail->render(), strip_tags((string) $mail->render()));
    }

    public function test_a_client_invite_and_a_forgot_password_mail_are_branded_too(): void
    {
        $client = $this->user(Role::AgencyClient);

        $invite = $this->mail($client, true);
        $this->assertStringStartsWith('https://app.example.test/a/northwind/reset-password?', $invite->actionUrl);
        $this->assertAgencyBrandOnly((string) $invite->render(), strip_tags((string) $invite->render()));

        $reset = $this->mail($client, false);                                       // forgot password: same template, no invite flag
        $this->assertStringNotContainsString('invite=1', $reset->actionUrl);
        $this->assertStringStartsWith('https://app.example.test/a/northwind/reset-password?', $reset->actionUrl);
        $this->assertAgencyBrandOnly((string) $reset->render(), strip_tags((string) $reset->render()));
    }

    public function test_forgot_password_goes_through_the_branded_notification(): void
    {
        Notification::fake();
        $owner = $this->user(Role::AgencyOwner);

        $this->postJson('/api/auth/forgot-password', ['email' => $owner->email])->assertStatus(202);

        Notification::assertSentTo($owner, SetPasswordNotification::class, function (SetPasswordNotification $n) use ($owner): bool {
            $mail = $n->toMail($owner);
            $this->assertSame('Set your password – Northwind', $mail->subject);
            $this->assertStringContainsString('/a/northwind/reset-password', $mail->actionUrl);

            return true;
        });
    }

    public function test_changing_a_clients_email_sends_a_branded_invitation(): void
    {
        Notification::fake();
        $owner = $this->user(Role::AgencyOwner);
        $client = $this->user(Role::AgencyClient);

        $this->actingAs($owner)->patchJson("/api/agency/clients/{$client->id}", ['email' => 'new-address@example.test'])->assertOk();

        Notification::assertSentTo($client->fresh(), SetPasswordNotification::class, function (SetPasswordNotification $n) use ($client): bool {
            $mail = $n->toMail($client->fresh());
            $this->assertStringContainsString('/a/northwind/reset-password', $mail->actionUrl);
            $this->assertStringContainsString('invite=1', $mail->actionUrl);
            $this->assertAgencyBrandOnly((string) $mail->render(), strip_tags((string) $mail->render()));

            return true;
        });
    }

    public function test_staff_keep_the_platform_brand_and_never_see_an_agency(): void
    {
        foreach ([Role::Admin, Role::Employee] as $role) {
            $staff = $this->user($role, agency: false);
            $mail = $this->mail($staff, true);
            $html = (string) $mail->render();

            $this->assertSame('Set your password', $mail->subject);
            $this->assertStringStartsWith('https://app.example.test/reset-password?', $mail->actionUrl);
            $this->assertStringContainsString('ClientHub', $html);
            $this->assertStringNotContainsString('Northwind', $html);
            $this->assertStringNotContainsStringIgnoringCase('laravel', $html);
        }
    }

    public function test_an_agency_without_a_logo_or_support_email_still_gets_a_clean_branded_mail(): void
    {
        $bare = Agency::create(['name' => 'Plain Co']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $bare->id, 'is_active' => true]);

        $mail = $this->mail($owner, true);
        $html = (string) $mail->render();

        $this->assertSame('Set your password – Plain Co', $mail->subject);
        $this->assertSame([], $mail->replyTo);
        $this->assertStringContainsString('Plain Co', $html);
        $this->assertStringNotContainsStringIgnoringCase('clienthub', $html);
        $this->assertStringNotContainsStringIgnoringCase('laravel', $html);
    }
}
