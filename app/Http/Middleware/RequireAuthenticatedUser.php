<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAuthenticatedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->account_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Please log in to continue.',
            ], 401);
        }

        return $next($request);
    }
}
