<?php

namespace App\Http\Middleware;

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
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; "
                ."script-src 'self' 'nonce-{$nonce}' https://maps.googleapis.com https://maps.gstatic.com; "
                ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
                ."font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net; "
                ."img-src 'self' data: blob: https://maps.googleapis.com https://maps.gstatic.com; "
                ."connect-src 'self' https://maps.googleapis.com https://maps.gstatic.com; "
                ."frame-src https://www.google.com;",
        );
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
