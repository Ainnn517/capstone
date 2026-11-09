<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class AuthController extends ApiController
{
    private const MAX_REGISTERED_ACCOUNTS = 9000;

    public function __construct(private readonly AccountNotificationService $accountNotifications) {}

    // Create a resident account that requires administrator approval.
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fullName' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:120'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        if ($validator->fails()) {
            return $this->registrationError(
                'Registration could not be completed with these details. Review them and try again.',
                422,
                'not_sent',
            );
        }

        $data = $validator->validated();
        try {
            $user = DB::transaction(function () use ($data): User|JsonResponse {
                $capacityLock = DB::table('REGISTRATION_CAPACITY_LOCK')
                    ->where('id', 1)
                    ->lockForUpdate()
                    ->first();

                if (! $capacityLock) {
                    throw new RuntimeException('The registration capacity lock is missing. Run the database migrations.');
                }

                if (User::count() >= self::MAX_REGISTERED_ACCOUNTS) {
                    return $this->error('Registration is closed because the system has reached its 9,000-account limit.', 403);
                }

                return User::create([
                    'username' => trim($data['username']),
                    'full_name' => trim($data['fullName']),
                    'email' => trim($data['email']),
                    'password_hash' => Hash::make($data['password']),
                    'role' => 'resident',
                    'account_status' => 'pending',
                ]);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23000') {
                return $this->registrationError(
                    'Registration could not be completed with these details. Review them and try again.',
                    422,
                    'not_sent',
                );
            }

            throw $exception;
        }

        if ($user instanceof JsonResponse) {
            $message = $user->getData()->message;

            return $this->registrationError($message, $user->getStatusCode(), 'not_sent');
        }

        $emailStatus = $this->accountNotifications->send(
            $user->email,
            'Registration received',
            "Hello {$user->full_name}, your registration was received successfully. Your account is pending administrator approval. You will be notified when the review is complete.",
        );

        return response()->json([
            'success' => true,
            'pendingApproval' => true,
            'message' => 'Registration received. An administrator must approve your account before you can sign in.',
            'emailStatus' => $emailStatus,
        ], 202);
    }

    private function registrationError(string $message, int $status, string $emailStatus): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'emailStatus' => $emailStatus,
        ], $status);
    }

    // Check credentials and start a signed-in session.
    public function login(Request $request): JsonResponse
    {
        $usernameInput = $request->input('username');
        $password = $request->input('password');
        if (! is_string($usernameInput) || ! is_string($password)) {
            return $this->error('Incorrect username or password.', 401);
        }

        $username = trim($usernameInput);
        $rateLimitKey = 'login:'.hash('sha256', mb_strtolower($username));
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return $this->error("Too many login attempts. Please try again in {$retryAfter} seconds.", 429)
                ->header('Retry-After', (string) $retryAfter);
        }

        $user = User::where('username', $username)->first();

        if (! $user || ! Hash::check($password, $user->password_hash)) {
            RateLimiter::hit($rateLimitKey, 60);

            return $this->error('Incorrect username or password.', 401);
        }

        RateLimiter::clear($rateLimitKey);
        if ($user->account_status !== 'approved') {
            $message = $user->account_status === 'pending'
                ? 'Your account is awaiting administrator approval.'
                : 'Your account was not approved. Contact the barangay office for assistance.';

            return $this->error($message, 403);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['success' => true, 'user' => $this->userPayload($user)]);
    }

    // Return the current signed-in user's details.
    public function session(): JsonResponse
    {
        $user = $this->currentUser();

        return response()->json(['success' => true, 'user' => $this->userPayload($user)]);
    }

    // End the current session.
    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true]);
    }
}
