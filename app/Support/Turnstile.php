<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Cloudflare Turnstile bot check on the public sign-up and forgot-password forms.
// Off until both keys are set (TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY).
class Turnstile
{
    public const SCRIPT_ORIGIN = 'https://challenges.cloudflare.com';

    public const RESPONSE_FIELD = 'cf-turnstile-response';

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret'));
    }

    public static function siteKey(): string
    {
        return (string) config('services.turnstile.site_key');
    }

    // True when the check is off, or Cloudflare confirms the token. If Cloudflare cannot
    // be reached the form is refused (and the problem logged) rather than let bots through.
    public static function passes(mixed $token, ?string $ip): bool
    {
        if (! self::enabled()) {
            return true;
        }
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::SCRIPT_ORIGIN.'/turnstile/v0/siteverify', [
                'secret' => config('services.turnstile.secret'),
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Turnstile verification could not be reached.', ['exception' => $exception::class]);

            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
