<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

// Creates an approved administrator account. This is how the first admin is made on a
// production server, where the seeder deliberately creates no pilot accounts.
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {--username= : Username (3-50 characters: letters, numbers, dots, dashes, underscores)}
        {--name= : Full name}
        {--email= : Email address}';

    protected $description = 'Create an approved administrator account (the password is asked for interactively)';

    public function handle(): int
    {
        $username = $this->option('username') ?: $this->ask('Username');
        $name = $this->option('name') ?: $this->ask('Full name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->secret('Password (at least 12 characters, upper and lower case, a number, and a symbol)');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make([
            'username' => $username,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (User::query()->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->exists()) {
            $this->error('That username is already taken.');

            return self::FAILURE;
        }
        if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
            $this->error('An account with that email address already exists.');

            return self::FAILURE;
        }

        $admin = new User([
            'username' => trim($username),
            'full_name' => trim($name),
            'email' => trim($email),
            'password_hash' => Hash::make($password),
        ]);
        $admin->forceFill([
            'role' => 'admin',
            'account_status' => 'approved',
            'email_verified_at' => now(),
        ])->save();

        $this->info("Administrator '{$admin->username}' created. They can sign in now.");

        return self::SUCCESS;
    }
}
