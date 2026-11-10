<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AuthController extends ApiController
{
    // Create a resident account that requires administrator approval.
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fullName' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:120'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'privacyConsent' => ['required', 'accepted'],
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

                if (User::count() >= config('security.max_registered_accounts')) {
                    return $this->error('Registration is closed because the system has reached its configured account limit.', 403);
                }

                $resident = User::create([
                    'username' => trim($data['username']),
                    'full_name' => trim($data['fullName']),
                    'email' => trim($data['email']),
                    'password_hash' => Hash::make($data['password']),
                    'privacy_consent_at' => now(),
                    'privacy_notice_version' => config('security.registration_consent_version'),
                ]);
                $resident->forceFill([
                    'role' => 'resident',
                    'account_status' => 'pending',
                    'email_verified_at' => null,
                ]);
                $resident->save();

                return $resident;
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

        $user->sendEmailVerificationNotification();
        $emailStatus = config('mail.default') === 'log' ? 'not_configured' : 'sent';

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
        $rateLimitKey = 'login:'.hash('sha256', mb_strtolower($username).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return $this->error("Too many login attempts. Please try again in {$retryAfter} seconds.", 429)
                ->header('Retry-After', (string) $retryAfter);
        }

        $user = User::where('username', $username)->first();

        $passwordMatches = $user
            ? Hash::check($password, $user->password_hash)
            : Hash::check($password, config('auth.dummy_password_hash'));
        if (! $user || ! $passwordMatches) {
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
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();

            return $this->error('Verify your email address before signing in. A verification link has been sent if email delivery is configured.', 403);
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

    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:120'],
        ]);
        if (! $validator->fails()) {
            PasswordBroker::sendResetLink(['email' => $validator->validated()['email']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account matches that email, a password reset link will be sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            return $this->error('The reset link or new password is invalid.', 422);
        }

        $data = $validator->validated();
        $status = PasswordBroker::reset(
            [
                'email' => $data['email'],
                'token' => $data['token'],
                'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
            ],
            function (User $user) use ($data): void {
                $user->forceFill(['password_hash' => Hash::make($data['password'])])->save();
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return $this->error('The reset link or new password is invalid.', 422);
        }

        return response()->json(['success' => true, 'message' => 'Your password has been reset.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $validator = Validator::make($request->all(), [
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            return $this->error('Check the new password and try again.', 422);
        }

        $data = $validator->validated();
        if (! Hash::check($data['currentPassword'], $user->password_hash)) {
            return $this->error('The current password is incorrect.', 422);
        }

        $user->forceFill(['password_hash' => Hash::make($data['password'])])->save();

        return response()->json(['success' => true, 'message' => 'Your password has been changed.']);
    }

    public function verifyEmail(int $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403);
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect('/frontend/pages/index.html?verified=1');
    }
}
