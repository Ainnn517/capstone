<?php

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

// Which proxy headers the app believes. Only the client IP and the scheme (https) are
// taken from the proxy (the Cloudflare tunnel). The host is never taken from
// X-Forwarded-Host: a client could set it, and links in password-reset and verification
// emails would then point to the attacker's site.
class ProxyTrust
{
    public const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * @param  list<string>  $proxies
     */
    public static function apply(array $proxies): void
    {
        if ($proxies === []) {
            return;
        }

        TrustProxies::at(count($proxies) === 1 ? $proxies[0] : array_values($proxies));
        TrustProxies::withHeaders(self::HEADERS);
    }
}
