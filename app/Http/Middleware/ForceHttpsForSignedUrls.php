<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttpsForSignedUrls
{
    /**
     * Handle an incoming request.
     *
     * Fix for K3s/Traefik ingress not setting X-Forwarded-Proto correctly.
     * Forces HTTPS scheme for signed URL validation when APP_URL uses HTTPS.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only apply to signed URL routes (those with expires and signature query params)
        if ($request->has('signature') && $request->has('expires')) {
            $appUrl = config('app.url');
            $appScheme = parse_url($appUrl, PHP_URL_SCHEME);

            // If APP_URL uses HTTPS but request scheme is HTTP, force HTTPS
            if ($appScheme === 'https' && $request->getScheme() === 'http') {
                // Override the scheme to HTTPS for signature validation
                $request->server->set('HTTPS', 'on');
                $request->server->set('SERVER_PORT', 443);

                // Also update X-Forwarded-Proto header
                $request->headers->set('X-Forwarded-Proto', 'https');
                $request->headers->set('X-Forwarded-Port', '443');
            }
        }

        return $next($request);
    }
}
