<?php

namespace App\Http\Middleware;

use App\Support\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        app()->instance('csp.nonce', $nonce);

        $response = $next($request);
        // The Turnstile bot check loads a script and an iframe from Cloudflare, only when enabled.
        $turnstile = Turnstile::enabled() ? ' '.Turnstile::SCRIPT_ORIGIN : '';
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; "
                ."script-src 'self' 'nonce-{$nonce}'{$turnstile}; "
                .($turnstile !== '' ? "frame-src{$turnstile}; " : '')
                ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
                ."font-src 'self' data: https://fonts.gstatic.com; "
                // Map tiles come from OpenStreetMap; Leaflet itself is served from this site.
                ."img-src 'self' data: blob: https://tile.openstreetmap.org; "
                // Videos play from this site; blob: lets the report form preview a chosen video.
                ."media-src 'self' blob:; "
                ."connect-src 'self'; "
                .'worker-src blob:;',
        );
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
