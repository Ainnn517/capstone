<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    // Reuse the hashed test password for generated users.
    protected static ?string $password;

    // Build an administrator account state.
    public function admin(): static
    {
        return $this->afterMaking(static fn (User $user) => $user->forceFill([
            'role' => 'admin',
            'account_status' => 'approved',
        ]));
    }

    // Build a pending resident account state.
    public function pending(): static
    {
        return $this->afterMaking(static fn (User $user) => $user->forceFill([
            'account_status' => 'pending',
        ]));
    }

    // Apply guarded account defaults explicitly for generated users.
    public function configure(): static
    {
        return $this->afterMaking(static fn (User $user) => $user->forceFill([
            'role' => 'resident',
            'account_status' => 'approved',
        ]));
    }

    // Build sample resident account data for tests.
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => static::$password ??= Hash::make('password'),
            'email_verified_at' => now(),
        ];
    }
}
