<?php

namespace Tests\Feature;

use App\Mail\PasswordChangeCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordChangeVerificationTest extends TestCase
{
    private const CURRENT = 'Current-password1!';

    private const NEW = 'Changed-password2!';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_requesting_a_change_emails_a_code_and_does_not_change_the_password_yet(): void
    {
        $user = $this->user('juan.delacruz@example.test');

        $this->actingAs($user)
            ->postJson('/api/password/change', $this->changeRequest())
            ->assertOk()
            ->assertJsonPath('verificationRequired', true)
            ->assertJsonPath('message', 'We sent a 6-digit code to j***@example.test. Enter it below to finish changing your password.');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password_hash));
        Mail::assertSent(PasswordChangeCodeMail::class, fn (PasswordChangeCodeMail $mail): bool => $mail->hasTo($user->email)
            && preg_match('/\A\d{6}\z/', $mail->code) === 1);
    }

    public function test_the_correct_code_changes_the_password(): void
    {
        $user = $this->user();
        $code = $this->requestCode($user);

        $this->postJson('/api/password/change/confirm', ['code' => $code])->assertOk();

        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password_hash));
        // A code works only once.
        $this->postJson('/api/password/change/confirm', ['code' => $code])->assertUnprocessable();
    }

    public function test_a_wrong_code_is_rejected_and_five_wrong_codes_cancel_the_change(): void
    {
        $user = $this->user();
        $code = $this->requestCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/password/change/confirm', ['code' => $wrong])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That code is incorrect. 4 tries left.');
        foreach (range(1, 3) as $_) {
            $this->postJson('/api/password/change/confirm', ['code' => $wrong])->assertUnprocessable();
        }
        $this->postJson('/api/password/change/confirm', ['code' => $wrong])
            ->assertJsonPath('message', 'Too many incorrect codes. Start the password change again.');

        // Even the right code no longer works, and the password is unchanged.
        $this->postJson('/api/password/change/confirm', ['code' => $code])->assertUnprocessable();
        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password_hash));
    }

    public function test_the_code_expires_after_five_minutes(): void
    {
        $user = $this->user();
        $code = $this->requestCode($user);

        $this->travel(6)->minutes();

        $this->postJson('/api/password/change/confirm', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This code has expired. Start the password change again.');
        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password_hash));
    }

    public function test_a_password_reset_in_the_meantime_voids_the_pending_change(): void
    {
        $user = $this->user();
        $code = $this->requestCode($user);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => Password::createToken($user),
            'password' => 'Reset-password3!',
            'password_confirmation' => 'Reset-password3!',
        ])->assertOk();

        // The old session is signed out by the reset itself...
        $this->actingAs($user->fresh())
            ->postJson('/api/password/change/confirm', ['code' => $code])
            ->assertUnauthorized();

        // ...and even from a new session the old code cannot overwrite the reset password.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh())
            ->postJson('/api/password/change/confirm', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This code has expired. Start the password change again.');
        $this->assertTrue(Hash::check('Reset-password3!', $user->fresh()->password_hash));
    }

    public function test_only_one_code_email_per_account_every_five_minutes(): void
    {
        $user = $this->user();
        $this->requestCode($user);

        $this->postJson('/api/password/change', $this->changeRequest())
            ->assertStatus(429)
            ->assertJsonPath('message', 'A code was sent recently. You can request a new one in 5 minutes.');
        Mail::assertSent(PasswordChangeCodeMail::class, 1);

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();
        $this->postJson('/api/password/change', $this->changeRequest())->assertOk();
        Mail::assertSent(PasswordChangeCodeMail::class, 2);
    }

    public function test_no_code_is_sent_for_a_wrong_current_password_or_an_unchanged_password(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/password/change', $this->changeRequest(current: 'Wrong-password9!'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The current password is incorrect.');
        $this->postJson('/api/password/change', $this->changeRequest(new: self::CURRENT))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Choose a new password that is different from your current one.');

        Mail::assertNothingSent();
    }

    public function test_admins_verify_by_email_too(): void
    {
        $admin = User::factory()->admin()->create(['password_hash' => Hash::make(self::CURRENT)]);
        $code = $this->requestCode($admin);

        $this->postJson('/api/password/change/confirm', ['code' => $code])->assertOk();
        $this->assertTrue(Hash::check(self::NEW, $admin->fresh()->password_hash));
    }

    public function test_confirming_requires_a_signed_in_user_and_a_six_digit_code(): void
    {
        $this->postJson('/api/password/change/confirm', ['code' => '123456'])->assertUnauthorized();
        $this->actingAs($this->user())
            ->postJson('/api/password/change/confirm', ['code' => '12ab'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Enter the 6-digit code from the email.');
    }

    private function user(?string $email = null): User
    {
        return User::factory()->create(array_filter([
            'password_hash' => Hash::make(self::CURRENT),
            'email' => $email,
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function changeRequest(string $current = self::CURRENT, string $new = self::NEW): array
    {
        return ['currentPassword' => $current, 'password' => $new, 'password_confirmation' => $new];
    }

    private function requestCode(User $user): string
    {
        $this->actingAs($user)->postJson('/api/password/change', $this->changeRequest())->assertOk();
        $code = null;
        Mail::assertSent(PasswordChangeCodeMail::class, function (PasswordChangeCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });
        $this->assertNotNull($code);

        return $code;
    }
}
