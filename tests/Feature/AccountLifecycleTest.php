<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    public function test_password_reset_request_has_a_generic_response_for_unknown_accounts(): void
    {
        $this->postJson('/api/password/forgot', ['email' => 'unknown@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account matches that email, a password reset link will be sent.');
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
        $user = User::factory()->create(['password_hash' => Hash::make('Current-password1!')]);

        $this->actingAs($user)
            ->postJson('/api/password/change', [
                'currentPassword' => 'Current-password1!',
                'password' => 'Changed-password2!',
                'password_confirmation' => 'Changed-password2!',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Changed-password2!', $user->fresh()->password_hash));
    }
}
