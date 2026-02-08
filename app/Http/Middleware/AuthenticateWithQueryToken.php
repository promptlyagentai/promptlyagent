<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate with query token parameter
 *
 * Allows API token authentication via query parameter (?token=xxx)
 * This is useful for direct downloads from notifications where
 * Authorization headers cannot be sent.
 *
 * Falls back to standard Sanctum Bearer token if query token not present.
 */
class AuthenticateWithQueryToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $guard = 'sanctum'): Response
    {
        // Check if token is provided in query parameter
        if ($token = $request->query('token')) {
            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken) {
                // Set the authenticated user
                auth($guard)->setUser($accessToken->tokenable);

                // Update last used timestamp
                $accessToken->forceFill(['last_used_at' => now()])->save();
            } else {
                // Token provided but invalid
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        // If not authenticated by query token, Sanctum middleware will handle Bearer token
        return $next($request);
    }
}
