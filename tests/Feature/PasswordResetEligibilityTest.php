<?php

namespace Tests\Feature;

use App\Mail\AccountUpdateMail;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetEligibilityTest extends TestCase
{
    private const GENERIC = 'If that email address can receive mail, we have sent it instructions. You can request another email after 5 minutes.';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
    }

    public function test_approved_accounts_receive_a_reset_link(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/password/forgot', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', self::GENERIC);

        Notification::assertSentTo($user, ResetPassword::class);
        Mail::assertNothingSent();
    }

    public function test_unregistered_emails_are_told_no_account_exists(): void
    {
        $this->postJson('/api/password/forgot', ['email' => 'Nobody@Example.test'])
            ->assertOk()
            ->assertJsonPath('message', self::GENERIC);

        Mail::assertSent(AccountUpdateMail::class, fn (AccountUpdateMail $mail): bool => $mail->hasTo('nobody@example.test')
            && str_contains($mail->render(), 'no account is registered'));
        Notification::assertNothingSent();
    }

    public function test_pending_rejected_and_suspended_accounts_get_an_explanation_but_no_reset_link(): void
    {
        $expected = [
            'pending' => 'still waiting for administrator approval',
            'rejected' => 'was not approved',
            'suspended' => 'Your account is suspended',
        ];
        foreach ($expected as $status => $phrase) {
            $user = User::factory()->create();
            $user->forceFill(['account_status' => $status])->save();

            $this->postJson('/api/password/forgot', ['email' => $user->email])
                ->assertOk()
                ->assertJsonPath('message', self::GENERIC);

            Notification::assertNotSentTo($user, ResetPassword::class);
            Mail::assertSent(AccountUpdateMail::class, fn (AccountUpdateMail $mail): bool => $mail->hasTo($user->email)
                && str_contains($mail->render(), $phrase));
            $this->assertDatabaseMissing('PASSWORD_RESET_TOKENS', ['email' => $user->email]);
        }
    }

    public function test_only_one_email_per_address_every_five_minutes(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();
        $this->postJson('/api/password/forgot', ['email' => strtoupper($user->email)])
            ->assertOk()
            ->assertJsonPath('message', self::GENERIC);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();
        Notification::assertSentToTimes($user, ResetPassword::class, 2);

        // Unregistered addresses are limited the same way.
        $this->postJson('/api/password/forgot', ['email' => 'nobody@example.test']);
        $this->postJson('/api/password/forgot', ['email' => 'nobody@example.test']);
        Mail::assertSent(AccountUpdateMail::class, 1);
    }

    public function test_reset_links_expire_after_five_minutes(): void
    {
        $user = User::factory()->create(['password_hash' => Hash::make('Original-pass1!')]);
        $token = Password::createToken($user);

        $this->travel(6)->minutes();

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'Late-password2!',
            'password_confirmation' => 'Late-password2!',
        ])->assertUnprocessable();
        $this->assertTrue(Hash::check('Original-pass1!', $user->fresh()->password_hash));
    }

    public function test_a_link_sent_before_suspension_no_longer_works(): void
    {
        $user = User::factory()->create(['password_hash' => Hash::make('Original-pass1!')]);
        $token = Password::createToken($user);
        $user->forceFill(['account_status' => 'suspended'])->save();

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'Takeover-pass2!',
            'password_confirmation' => 'Takeover-pass2!',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('Original-pass1!', $user->fresh()->password_hash));
    }

    public function test_suspending_or_rejecting_an_account_deletes_its_reset_links(): void
    {
        $administrator = User::factory()->admin()->create();
        $approved = User::factory()->create();
        $pending = User::factory()->pending()->create();
        foreach ([$approved, $pending] as $user) {
            DB::table('PASSWORD_RESET_TOKENS')->insert(['email' => $user->email, 'token' => Hash::make('t'), 'created_at' => now()]);
        }

        $this->actingAs($administrator)
            ->postJson('/api/residents/'.$approved->user_id.'/approval', ['status' => 'suspended', 'reason' => 'Test suspension.'])
            ->assertOk();
        $this->postJson('/api/residents/'.$pending->user_id.'/approval', ['status' => 'rejected', 'reason' => 'Not a resident.'])
            ->assertOk();

        $this->assertDatabaseMissing('PASSWORD_RESET_TOKENS', ['email' => $approved->email]);
        $this->assertDatabaseMissing('PASSWORD_RESET_TOKENS', ['email' => $pending->email]);
    }
}
