<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminTwoFactorLoginTest extends TestCase
{
    private const PASSWORD = 'Admin-pass123!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.admin_two_factor' => true]);
        Mail::fake();
    }

    public function test_administrator_sign_in_needs_the_emailed_code(): void
    {
        $admin = User::factory()->admin()->create(['password_hash' => Hash::make(self::PASSWORD), 'email' => 'kapitan@example.test']);

        $response = $this->postJson('/api/login', ['username' => $admin->username, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('twoFactorRequired', true)
            ->assertJsonPath('message', 'We sent a 6-digit sign-in code to k***@example.test. Enter it below to finish signing in.');

        // Not signed in yet.
        $this->getJson('/api/session')->assertUnauthorized();
        $code = $this->sentCode($admin);

        $this->postJson('/api/login/verify', ['challenge' => $response->json('challenge'), 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');
        $this->getJson('/api/session')->assertOk();
        $this->getJson('/api/residents')->assertOk();

        // The code works once.
        $this->postJson('/api/login/verify', ['challenge' => $response->json('challenge'), 'code' => $code])->assertUnprocessable();
    }

    public function test_wrong_codes_are_limited_and_the_code_expires(): void
    {
        $admin = User::factory()->admin()->create(['password_hash' => Hash::make(self::PASSWORD)]);
        $challenge = $this->postJson('/api/login', ['username' => $admin->username, 'password' => self::PASSWORD])->json('challenge');
        $code = $this->sentCode($admin);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $wrong])
            ->assertUnprocessable()->assertJsonPath('message', 'That code is incorrect. 4 tries left.');
        foreach (range(1, 4) as $_) {
            $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $wrong]);
        }
        $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $code])->assertUnprocessable();
        $this->getJson('/api/session')->assertUnauthorized();

        // A fresh code expires after five minutes.
        $this->travel(6)->minutes();
        $challenge = $this->postJson('/api/login', ['username' => $admin->username, 'password' => self::PASSWORD])->json('challenge');
        $code = $this->sentCode($admin);
        $this->travel(6)->minutes();
        $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $code])
            ->assertUnprocessable()->assertJsonPath('message', 'This code has expired. Please sign in again.');
    }

    public function test_repeated_sign_in_within_five_minutes_reuses_the_code_without_a_second_email(): void
    {
        $admin = User::factory()->admin()->create(['password_hash' => Hash::make(self::PASSWORD)]);
        $first = $this->postJson('/api/login', ['username' => $admin->username, 'password' => self::PASSWORD]);
        $second = $this->postJson('/api/login', ['username' => $admin->username, 'password' => self::PASSWORD])->assertOk();

        $this->assertSame($first->json('challenge'), $second->json('challenge'));
        Mail::assertSent(LoginCodeMail::class, 1);
    }

    public function test_residents_sign_in_without_a_code_and_the_feature_can_be_switched_off(): void
    {
        $resident = User::factory()->create(['password_hash' => Hash::make(self::PASSWORD)]);
        $this->postJson('/api/login', ['username' => $resident->username, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonMissingPath('twoFactorRequired');
        Mail::assertNothingSent();

        config(['security.admin_two_factor' => false]);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $admin = User::factory()->admin()->create(['password_hash' => Hash::make(self::PASSWORD)]);
        $this->postJson('/api/login', ['username' => $admin->username, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('user.role', 'admin');
    }

    public function test_a_suspended_administrator_cannot_finish_signing_in(): void
    {
        $admin = User::factory()->admin()->create(['password_hash' => Hash::make(self::PASSWORD)]);
        $challenge = $this->postJson('/api/login', ['username' => $admin->username, 'password' => self::PASSWORD])->json('challenge');
        $code = $this->sentCode($admin);
        $admin->forceFill(['account_status' => 'suspended'])->save();

        $this->postJson('/api/login/verify', ['challenge' => $challenge, 'code' => $code])->assertForbidden();
        $this->getJson('/api/session')->assertUnauthorized();
    }

    private function sentCode(User $admin): string
    {
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code, $admin): bool {
            $code = $mail->code;

            return $mail->hasTo($admin->email);
        });
        $this->assertNotNull($code);

        return $code;
    }
}
