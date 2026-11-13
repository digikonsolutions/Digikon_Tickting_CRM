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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower($email) : '';

            return Limit::perMinute(5)->by(Str::lower(trim($email)).'|'.$request->ip());
        });

        RateLimiter::for('auth-admin-signup', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('project-create', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('attachments', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('notifications', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('auth-password-reset', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));

        ResetPassword::createUrlUsing(function ($user, string $token): string {
            $query = http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], '', '&', PHP_QUERY_RFC3986);

            return rtrim(config('app.frontend_url'), '/').'/reset-password?'.$query;
        });
    }
}
