<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    public function test_login_is_limited_to_three_failed_attempts_per_account_each_minute(): void
    {
        $user = User::factory()->create([
            'username' => 'limited-resident',
            'password_hash' => Hash::make('correct-password'),
        ]);
        $otherUser = User::factory()->create([
            'username' => 'other-resident',
            'password_hash' => Hash::make('correct-password'),
        ]);

        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/login', [
                'username' => $user->username,
                'password' => 'incorrect-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'correct-password',
        ])->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->postJson('/api/login', [
            'username' => $otherUser->username,
            'password' => 'correct-password',
        ])->assertOk();

        $this->travel(61)->seconds();

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'correct-password',
        ])->assertOk();
    }

    public function test_successful_login_clears_failed_attempts_for_the_account(): void
    {
        $user = User::factory()->create([
            'username' => 'reset-resident',
            'password_hash' => Hash::make('correct-password'),
        ]);

        foreach (range(1, 2) as $attempt) {
            $this->postJson('/api/login', [
                'username' => $user->username,
                'password' => 'incorrect-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'correct-password',
        ])->assertOk();

        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/login', [
                'username' => $user->username,
                'password' => 'incorrect-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'correct-password',
        ])->assertStatus(429);
    }
}
