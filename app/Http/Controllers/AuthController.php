<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Mail\AccountUpdateMail;
use App\Mail\PasswordChangeCodeMail;
use App\Models\User;
use App\Services\AccountNotificationService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AuthController extends ApiController
{
    // Create a resident account that requires administrator approval.
    //
    // Validation problems are reported specifically (so a resident knows what to fix).
    // An email that already has an account gets the SAME response as a new registration,
    // and the existing account owner is emailed instead, so this form cannot be used to
    // find out which email addresses are registered.
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fullName' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'email' => ['required', 'string', 'email', 'max:120'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'privacyConsent' => ['required', 'accepted'],
        ], [
            'fullName.required' => 'Enter your full name.',
            'username.required' => 'Choose a username.',
            'username.min' => 'The username must be at least 3 characters.',
            'username.regex' => 'The username may only contain letters, numbers, dots, dashes, and underscores.',
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address.',
            'password.required' => 'Choose a password.',
            'privacyConsent.required' => 'Please agree to the privacy notice to continue.',
            'privacyConsent.accepted' => 'Please agree to the privacy notice to continue.',
        ], [
            'fullName' => 'full name',
            'username' => 'username',
            'email' => 'email address',
            'password' => 'password',
        ]);
        if ($validator->fails()) {
            return $this->registrationError($validator->errors()->first(), 422, 'not_sent');
        }

        $data = $validator->validated();
        $email = trim($data['email']);
        $emailStatus = config('mail.default') === 'log' ? 'not_configured' : 'sent';
        $received = [
            'success' => true,
            'pendingApproval' => true,
            'message' => 'Registration received. An administrator must approve your account before you can sign in.',
            'emailStatus' => $emailStatus,
        ];

        try {
            $result = DB::transaction(function () use ($data, $email): User|string {
                $capacityLock = DB::table('REGISTRATION_CAPACITY_LOCK')
                    ->where('id', 1)
                    ->lockForUpdate()
                    ->first();
                if (! $capacityLock) {
                    throw new RuntimeException('The registration capacity lock is missing. Run the database migrations.');
                }

                if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
                    return 'duplicate_email';
                }
                if (User::query()->whereRaw('LOWER(username) = ?', [mb_strtolower(trim($data['username']))])->exists()) {
                    return 'duplicate_username';
                }
                if (User::count() >= config('security.max_registered_accounts')) {
                    return 'closed';
                }

                $resident = User::create([
                    'username' => trim($data['username']),
                    'full_name' => trim($data['fullName']),
                    'email' => $email,
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
                // Two sign-ups raced for the same email or username: treat like a duplicate.
                return response()->json($received, 202);
            }

            throw $exception;
        }

        if ($result === 'duplicate_email') {
            // Notify the address exactly as stored on the existing account.
            $existing = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
            app(AccountNotificationService::class)->send(
                $existing?->email ?? $email,
                'You already have an account',
                'Someone tried to create a new Barangay Reporting System account with this email address, but an account already exists for it. If this was you, sign in with your existing username, or use "Forgot password" on the sign-in page. If this was not you, you can ignore this email.',
            );

            return response()->json($received, 202);
        }
        if ($result === 'duplicate_username') {
            return $this->registrationError('That username is already taken. Please choose another.', 422, 'not_sent');
        }
        if ($result === 'closed') {
            return $this->registrationError('Registration is closed because the system has reached its configured account limit.', 403, 'not_sent');
        }

        $result->sendEmailVerificationNotification();

        return response()->json($received, 202);
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
            $message = match ($user->account_status) {
                'pending' => 'Your account is awaiting administrator approval.',
                'suspended' => 'Your account has been suspended. Contact the barangay office for assistance.',
                default => 'Your account was not approved. Contact the barangay office for assistance.',
            };

            return $this->error($message, 403);
        }
        if (config('auth.require_email_verification') && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();

            return $this->error('Verify your email address before signing in. A verification link has been sent if email delivery is configured.', 403);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(RequireAuthenticatedUser::PASSWORD_FINGERPRINT_KEY, $user->sessionPasswordFingerprint());

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

    private const FORGOT_PASSWORD_MESSAGE = 'If that email address can receive mail, we have sent it instructions. You can request another email after 5 minutes.';

    private const EMAIL_COOLDOWN_SECONDS = 300;

    // Every request gets an email that fits the situation (reset link, "no account", or why a
    // reset is not available), at most one per address every 5 minutes. The on-screen response
    // is identical in every case and each email goes only to the address owner, so this form
    // cannot be used to find out whether an email is registered or what state its account is in.
    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:120'],
        ]);
        if ($validator->fails()) {
            return $this->error('Enter a valid email address.', 422);
        }

        $email = mb_strtolower(trim($validator->validated()['email']));
        $cooldownKey = 'password-forgot-mail:'.hash('sha256', $email);
        if (! RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            RateLimiter::hit($cooldownKey, self::EMAIL_COOLDOWN_SECONDS);
            $this->sendForgotPasswordEmail($email);
        }

        return response()->json(['success' => true, 'message' => self::FORGOT_PASSWORD_MESSAGE]);
    }

    private function sendForgotPasswordEmail(string $email): void
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        try {
            if ($user?->account_status === 'approved') {
                // Only approved accounts get a reset link (it expires after 5 minutes).
                PasswordBroker::sendResetLink(['email' => $user->email, 'account_status' => 'approved']);

                return;
            }

            [$subject, $body] = match ($user?->account_status) {
                null => [
                    'Password reset request',
                    'Someone asked to reset the password for this email address on the Barangay Reporting System, but no account is registered with it. If you meant to sign up, you can create an account on the sign-in page. If you did not make this request, you can ignore this email.',
                ],
                'pending' => [
                    'Password reset not available yet',
                    'Someone asked to reset the password for your Barangay Reporting System account. Your account is still waiting for administrator approval, so a password reset is not available yet. You will receive an email once your account has been reviewed.',
                ],
                'suspended' => [
                    'Password reset not available',
                    'Someone asked to reset the password for your Barangay Reporting System account. Your account is suspended, so a password reset is not available. Please contact the barangay office for assistance.',
                ],
                default => [
                    'Password reset not available',
                    'Someone asked to reset the password for this email address on the Barangay Reporting System. The account registered with it was not approved, so a password reset is not available. Please contact the barangay office for assistance.',
                ],
            };
            Mail::to($user?->email ?? $email)->send(new AccountUpdateMail($subject, $body));
        } catch (TransportExceptionInterface $exception) {
            Log::error('Forgot-password email could not be sent.', ['exception' => $exception::class]);
        }
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
                // A link sent before the account was suspended stops working.
                'account_status' => 'approved',
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

    private const PASSWORD_CODE_MINUTES = 5;

    private const PASSWORD_CODE_MAX_ATTEMPTS = 5;

    // Step 1 of a password change: check the current password and the new one, then email a
    // one-time code. The password is NOT changed until the code is confirmed (step 2).
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
        if (Hash::check($data['password'], $user->password_hash)) {
            return $this->error('Choose a new password that is different from your current one.', 422);
        }

        // At most one code email per account every 5 minutes.
        $cooldownKey = 'password-change-mail:'.$user->user_id;
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $wait = (int) ceil(RateLimiter::availableIn($cooldownKey) / 60);

            return $this->error("A code was sent recently. You can request a new one in {$wait} minute".($wait === 1 ? '' : 's').'.', 429);
        }
        RateLimiter::hit($cooldownKey, self::EMAIL_COOLDOWN_SECONDS);

        // Store only hashes while waiting: the code is HMAC'd and the new password is already hashed.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::PASSWORD_CODE_MINUTES);
        Cache::put($this->passwordChangeKey($user), [
            'code' => $this->hashPasswordCode($code),
            'password_hash' => Hash::make($data['password']),
            // If the password changes another way (for example a reset) the pending change is void.
            'fingerprint' => $user->sessionPasswordFingerprint(),
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        try {
            Mail::to($user->email)->send(new PasswordChangeCodeMail($code, $user->full_name, self::PASSWORD_CODE_MINUTES));
        } catch (TransportExceptionInterface $exception) {
            Cache::forget($this->passwordChangeKey($user));
            RateLimiter::clear($cooldownKey); // Nothing was delivered, so allow a retry right away.
            Log::error('Password change code email could not be sent.', ['exception' => $exception::class]);

            return $this->error('We could not send the verification email. Please try again later.', 503);
        }

        return response()->json([
            'success' => true,
            'verificationRequired' => true,
            'expiresInMinutes' => self::PASSWORD_CODE_MINUTES,
            'message' => 'We sent a 6-digit code to '.$this->maskEmail($user->email).'. Enter it below to finish changing your password.',
        ]);
    }

    // Step 2 of a password change: apply the new password once the emailed code is confirmed.
    public function confirmPasswordChange(Request $request): JsonResponse
    {
        // Re-read the account so the check below always compares against the stored password,
        // even if it changed (for example through a reset) after this request's user was loaded.
        $user = $this->currentUser()->refresh();
        $code = trim((string) $request->input('code'));
        if (! preg_match('/\A\d{6}\z/', $code)) {
            return $this->error('Enter the 6-digit code from the email.', 422);
        }

        $key = $this->passwordChangeKey($user);
        $pending = Cache::get($key);
        if (! is_array($pending) || ! hash_equals($pending['fingerprint'], $user->sessionPasswordFingerprint())) {
            Cache::forget($key);

            return $this->error('This code has expired. Start the password change again.', 422);
        }

        if (! hash_equals($pending['code'], $this->hashPasswordCode($code))) {
            $pending['attempts']++;
            if ($pending['attempts'] >= self::PASSWORD_CODE_MAX_ATTEMPTS) {
                Cache::forget($key);

                return $this->error('Too many incorrect codes. Start the password change again.', 422);
            }
            Cache::put($key, $pending, Carbon::createFromTimestamp($pending['expires_at']));
            $remaining = self::PASSWORD_CODE_MAX_ATTEMPTS - $pending['attempts'];

            return $this->error("That code is incorrect. {$remaining} ".($remaining === 1 ? 'try' : 'tries').' left.', 422);
        }

        Cache::forget($key);
        $user->forceFill(['password_hash' => $pending['password_hash']])->save();

        // Keep this session signed in; every other session fails the fingerprint check.
        $request->session()->regenerate();
        $request->session()->put(RequireAuthenticatedUser::PASSWORD_FINGERPRINT_KEY, $user->sessionPasswordFingerprint());

        return response()->json([
            'success' => true,
            'message' => 'Your password has been changed. Other devices have been signed out.',
        ]);
    }

    private function passwordChangeKey(User $user): string
    {
        return 'password-change:'.$user->user_id;
    }

    private function hashPasswordCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    // j***@example.com: enough to recognise the address without exposing it in full.
    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'***@'.$domain;
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
