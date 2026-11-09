<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

abstract class ApiController extends Controller
{
    // Get the authenticated user.
    protected function currentUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    // Format user details for API responses.
    protected function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->user_id,
            'username' => $user->username,
            'fullName' => $user->full_name,
            'role' => $user->role,
            'accountStatus' => $user->account_status,
        ];
    }

    // Turn a category severity into a priority label.
    protected function priorityForWeight(int $severityWeight): string
    {
        return $severityWeight >= 5 ? 'Critical' : ($severityWeight >= 3 ? 'High' : 'Normal');
    }

    // Return a consistent API error response.
    protected function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
