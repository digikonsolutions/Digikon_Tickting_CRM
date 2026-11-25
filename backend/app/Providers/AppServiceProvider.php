<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap this binding for a real scanner (ClamAV, a cloud API, ...) without touching the controllers.
        $this->app->bind(\App\Services\Attachments\AttachmentScanner::class, \App\Services\Attachments\NullScanner::class);
        // Support hours come from config/support.php; AI help is an interface with a null implementation (nothing calls out).
        $this->app->bind(\App\Services\Support\SlaCalculator::class, fn () => \App\Services\Support\SlaCalculator::fromConfig());
        $this->app->bind(\App\Services\Support\TicketAssistant::class, \App\Services\Support\NullTicketAssistant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Services\Support\SupportSettings::apply();
        RateLimiter::for('auth-login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower($email) : '';

            $slug = $request->input('agency_slug');
            $slug = is_string($slug) ? Str::lower($slug) : '';

            return Limit::perMinute(5)->by(Str::lower(trim($email)).'|'.$slug.'|'.$request->ip());
        });

        // The anonymous portal brand lookup: 30 a minute per IP.
        RateLimiter::for('public-branding', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('auth-admin-signup', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('project-create', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        // Queue reorders replan an employee's whole schedule: 20 a minute per signed-in user.
        // Rush requests ask the platform for paid work: 5 a minute per signed-in user.
        // Asking for more hours on a ticket: 10 a minute per signed-in user.
        RateLimiter::for('work-order', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('hours-request', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('rush-create', fn (Request $request) => Limit::perMinute(5)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('queue-reorder',fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('attachments',fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        // Changing a password: 5 attempts a minute per signed-in user (wrong guesses count).
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('heartbeat', fn (Request $request) => Limit::perMinute(6)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        // The extension sends batches of up to 200 events; allow a batch every few seconds, not a flood.
        // Snapshots are a few a minute at most; the controller also spaces them out.
        RateLimiter::for('work-snapshots', fn (Request $request) => Limit::perMinute(6)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('browser-history-sync', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('notifications', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        // "Request more hours / upgrade / renewal": a few a minute per signed-in user, so admins are never flooded.
        RateLimiter::for('agency-requests', fn (Request $request) => Limit::perMinute(5)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        // Revealing a full CNIC: 10 a minute per signed-in admin (each call is also audited).
        RateLimiter::for('sensitive-reveal', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('auth-password-reset', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));

        RateLimiter::for('insights', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            $query = http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], '', '&', PHP_QUERY_RFC3986);

            // Agency users set and reset their password on their own portal; staff use the platform address.
            if ($user instanceof \App\Models\User && $user->role->isAgencyRole() && $user->agency?->slug) {
                return \App\Support\MailBrand::portalUrl($user->agency->slug, '/reset-password?'.$query);
            }

            return rtrim(config('app.frontend_url'), '/').'/reset-password?'.$query;
        });
    }
}
