<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    public function test_login_is_limited_to_three_failed_attempts_per_account_and_ip_each_minute(): void
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

    public function test_guesses_spread_over_many_ip_addresses_are_limited_per_account(): void
    {
        $user = User::factory()->create([
            'username' => 'targeted-resident',
            'password_hash' => Hash::make('correct-password'),
        ]);

        foreach (range(1, 20) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$attempt}"])
                ->postJson('/api/login', ['username' => $user->username, 'password' => 'incorrect-password'])
                ->assertUnauthorized();
        }

        // A fresh address with the right password still waits: the account is cooling down.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/api/login', ['username' => $user->username, 'password' => 'correct-password'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->travel(61)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/api/login', ['username' => $user->username, 'password' => 'correct-password'])
            ->assertOk();
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
