<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class VerifyAccount extends Command
{
    protected $signature = 'accounts:verify {username}';

    protected $description = 'Mark an account email address as verified';

    // Mark the specified account email as verified.
    public function handle(): int
    {
        $user = User::query()->where('username', $this->argument('username'))->first();

        if (! $user) {
            $this->error('No account was found with that username.');

            return self::FAILURE;
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->info('The account email address has been verified.');

        return self::SUCCESS;
    }
}
