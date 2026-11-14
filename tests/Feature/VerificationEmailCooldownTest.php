<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VerificationEmailCooldownTest extends TestCase
{
    public function test_repeated_sign_in_attempts_send_at_most_one_email_every_five_minutes(): void
    {
        Notification::fake();
        config(['auth.require_email_verification' => true]);
        $user = User::factory()->create([
            'email' => 'resident@example.test',
            'email_verified_at' => null,
            'password_hash' => Hash::make('A-secure-test-password1!'),
        ]);
        $login = fn () => $this->postJson('/api/login', ['username' => $user->username, 'password' => 'A-secure-test-password1!']);

        $login()->assertForbidden()->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'We sent a new verification link to r***@example.test'));
        $login()->assertForbidden()->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'try again in 5 minute(s)'));
        $login()->assertForbidden();
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);

        $this->travel(301)->seconds();
        $login()->assertForbidden();
        Notification::assertSentToTimes($user, VerifyEmail::class, 2);
    }
}
