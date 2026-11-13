<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireAuthenticatedUser
{
    public const PASSWORD_FINGERPRINT_KEY = 'auth_password_fingerprint';

    // Require an approved user and, when configured, a verified email address.
    // Also end sessions that started before the account's password was changed or reset.
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User
            || $user->account_status !== 'approved'
            || (config('auth.require_email_verification') && ! $user->hasVerifiedEmail())) {
            return $this->unauthenticated();
        }

        if ($request->hasSession()) {
            $session = $request->session();
            $fingerprint = $user->sessionPasswordFingerprint();
            $stored = $session->get(self::PASSWORD_FINGERPRINT_KEY);

            if ($stored === null) {
                // Sessions created before this check existed adopt the current password.
                $session->put(self::PASSWORD_FINGERPRINT_KEY, $fingerprint);
            } elseif (! hash_equals($stored, $fingerprint)) {
                Auth::guard('web')->logout();
                $session->invalidate();
                $session->regenerateToken();

                return $this->unauthenticated();
            }
        }

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Please log in to continue.',
        ], 401);
    }
}
