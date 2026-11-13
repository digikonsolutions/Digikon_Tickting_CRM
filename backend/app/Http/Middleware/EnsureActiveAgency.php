<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agency-facing routes require the caller to belong to an agency that is active.
 * Apply after `auth` and `role:` so role failures keep their own response.
 */
class EnsureActiveAgency
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        abort_unless(
            $user->agency_id !== null && $user->agency?->status === 'active',
            403,
            'An active agency account is required to access this resource.'
        );

        return $next($request);
    }
}
