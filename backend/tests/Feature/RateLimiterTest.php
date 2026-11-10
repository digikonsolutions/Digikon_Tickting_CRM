<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RateLimiterTest extends TestCase
{
    public function test_project_creation_uses_its_own_user_keyed_limiter(): void
    {
        $limiter = RateLimiter::limiter('project-create');
        $request = Request::create('/api/agency/projects', 'POST', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.10',
        ]);
        $user = User::factory()->make(['id' => 42]);
        $request->setUserResolver(fn () => $user);

        /** @var Limit $limit */
        $limit = $limiter($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame(42, $limit->key);

        $request->setUserResolver(fn () => null);
        $this->assertSame('192.0.2.10', $limiter($request)->key);

        $signupLimit = RateLimiter::limiter('auth-admin-signup')($request);
        $this->assertSame(10, $signupLimit->maxAttempts);
        $this->assertSame('192.0.2.10', $signupLimit->key);

        $projectRoute = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route): bool => in_array('POST', $route->methods(), true)
                && str_ends_with($route->uri(), 'agency/projects')
        );

        $this->assertNotNull($projectRoute);
        $this->assertContains('throttle:project-create', $projectRoute->middleware());
        $this->assertNotContains('throttle:auth-admin-signup', $projectRoute->middleware());
    }
}
