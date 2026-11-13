<?php

namespace Tests\Feature;

use App\Mail\PasswordChangeCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    public function test_password_reset_request_has_a_generic_response_for_unknown_accounts(): void
    {
        $this->postJson('/api/password/forgot', ['email' => 'unknown@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If that email address can receive mail, we have sent it instructions. You can request another email after 5 minutes.');
    }

    public function test_password_can_be_reset_with_a_valid_token_and_strong_password(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $token = Password::createToken($user);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'A-reset-password1!',
            'password_confirmation' => 'A-reset-password1!',
        ])->assertOk();

        $this->assertTrue(Hash::check('A-reset-password1!', $user->fresh()->password_hash));
    }

    public function test_authenticated_user_can_change_their_password(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password_hash' => Hash::make('Current-password1!')]);

        $this->actingAs($user)
            ->postJson('/api/password/change', [
                'currentPassword' => 'Current-password1!',
                'password' => 'Changed-password2!',
                'password_confirmation' => 'Changed-password2!',
            ])
            ->assertOk()
            ->assertJsonPath('verificationRequired', true);

        // Not changed until the emailed code is confirmed.
        $this->assertTrue(Hash::check('Current-password1!', $user->fresh()->password_hash));
        $code = null;
        Mail::assertSent(PasswordChangeCodeMail::class, function (PasswordChangeCodeMail $mail) use (&$code, $user): bool {
            $code = $mail->code;

            return $mail->hasTo($user->email);
        });

        $this->postJson('/api/password/change/confirm', ['code' => $code])->assertOk();
        $this->assertTrue(Hash::check('Changed-password2!', $user->fresh()->password_hash));
    }
}
