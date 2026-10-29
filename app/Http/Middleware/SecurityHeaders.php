<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers that keep the pages from being framed by other sites
 * (clickjacking), content types from being guessed, and HTTPS from being
 * downgraded.
 */
class SecurityHeaders
{
    /**
     * HTTPS is remembered by the browser for a year.
     */
    private const int HSTS_SECONDS = 31_536_000;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'", false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()', false);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.self::HSTS_SECONDS, false);
        }

        return $response;
    }
}
