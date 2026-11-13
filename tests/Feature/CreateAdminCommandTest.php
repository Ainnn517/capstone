<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    public function test_admin_create_makes_an_approved_verified_administrator(): void
    {
        $this->artisan('admin:create', ['--username' => 'kapitan', '--name' => 'Barangay Captain', '--email' => 'captain@example.test'])
            ->expectsQuestion('Password (at least 12 characters, upper and lower case, a number, and a symbol)', 'Captain-pass123!')
            ->expectsQuestion('Confirm password', 'Captain-pass123!')
            ->assertSuccessful();

        $admin = User::where('username', 'kapitan')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('approved', $admin->account_status);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('Captain-pass123!', $admin->password_hash));

        $this->postJson('/api/login', ['username' => 'kapitan', 'password' => 'Captain-pass123!'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');
    }

    public function test_admin_create_rejects_weak_passwords_and_duplicates(): void
    {
        User::factory()->admin()->create(['username' => 'kapitan']);

        $this->artisan('admin:create', ['--username' => 'kapitan', '--name' => 'Someone', '--email' => 'other@example.test'])
            ->expectsQuestion('Password (at least 12 characters, upper and lower case, a number, and a symbol)', 'Captain-pass123!')
            ->expectsQuestion('Confirm password', 'Captain-pass123!')
            ->expectsOutputToContain('That username is already taken.')
            ->assertFailed();

        $this->artisan('admin:create', ['--username' => 'newadmin', '--name' => 'Someone', '--email' => 'new@example.test'])
            ->expectsQuestion('Password (at least 12 characters, upper and lower case, a number, and a symbol)', 'weak')
            ->expectsQuestion('Confirm password', 'weak')
            ->assertFailed();
        $this->assertDatabaseMissing('USERS', ['username' => 'newadmin']);
    }
}
