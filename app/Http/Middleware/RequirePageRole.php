<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Protects the portal pages themselves (not just their API calls): a visitor who is not
// signed in with an allowed role is redirected before any page HTML is sent.
class RequirePageRole
{
    private const LOGIN_PAGE = '/frontend/pages/index.html';

    private const PORTAL = [
        'admin' => '/frontend/pages/admin-dashboard.html',
        'resident' => '/frontend/pages/resident-portal.html',
    ];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $signedIn = $user instanceof User
            && $user->account_status === 'approved'
            && (! config('auth.require_email_verification') || $user->hasVerifiedEmail());

        if (! $signedIn) {
            return redirect(self::LOGIN_PAGE);
        }
        if (! in_array($user->role, $roles, true)) {
            // Signed in, but this page is for the other role: go to their own portal.
            return redirect(self::PORTAL[$user->role] ?? self::LOGIN_PAGE);
        }

        $response = $next($request);
        // Never let a browser or proxy cache a signed-in page for someone else.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
