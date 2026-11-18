<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// The login page: someone who is already signed in goes straight to their own portal
// instead of seeing the login form again.
class RedirectSignedInUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (RequirePageRole::isSignedIn($user)) {
            return redirect(RequirePageRole::PORTAL[$user->role] ?? '/');
        }

        $response = $next($request);
        // Do not let the browser reuse a cached copy of the login page after signing in.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
