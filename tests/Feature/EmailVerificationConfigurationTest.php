<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmailVerificationConfigurationTest extends TestCase
{
    // Block login and protected requests when verification is required.
    public function test_email_verification_is_required_by_default(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'password_hash' => Hash::make('correct-password'),
        ]);

        $this->assertTrue(config('auth.require_email_verification'));

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'correct-password',
        ])->assertForbidden();

        $this->getJson('/api/session')->assertUnauthorized();
    }

    // Allow approved users to sign in and use protected requests when verification is disabled.
    public function test_email_verification_can_be_disabled(): void
    {
        config(['auth.require_email_verification' => false]);
        $user = User::factory()->create([
            'email_verified_at' => null,
            'password_hash' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'correct-password',
        ])->assertOk();

        $this->getJson('/api/session')->assertOk();
    }

    // Verify an account through the accounts:verify Artisan command.
    public function test_account_can_be_verified_with_the_artisan_command(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->artisan('accounts:verify', ['username' => $user->username])
            ->assertExitCode(0);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
