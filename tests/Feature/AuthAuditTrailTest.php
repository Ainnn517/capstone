<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthAuditTrailTest extends TestCase
{
    public function test_sign_ins_failures_and_sign_outs_are_recorded_with_ip_and_browser(): void
    {
        $user = User::factory()->create(['username' => 'maria', 'password_hash' => Hash::make('Correct-pass123!')]);
        $client = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'TestBrowser/1.0'];

        $this->withServerVariables($client)
            ->postJson('/api/login', ['username' => 'maria', 'password' => 'wrong'])->assertUnauthorized();
        $this->withServerVariables($client)
            ->postJson('/api/login', ['username' => 'nobody', 'password' => 'wrong'])->assertUnauthorized();
        $this->withServerVariables($client)
            ->postJson('/api/login', ['username' => 'maria', 'password' => 'Correct-pass123!'])->assertOk();
        $this->withServerVariables($client)->postJson('/api/logout')->assertOk();

        $entries = AdminAuditLog::query()->orderBy('audit_id')->get();
        $this->assertSame(['login_failed', 'login_failed', 'login_success', 'logout'], $entries->pluck('action')->all());
        $this->assertSame([$user->user_id, null, $user->user_id, $user->user_id], $entries->pluck('actor_user_id')->all());
        $this->assertSame(['203.0.113.7'], $entries->pluck('ip_address')->unique()->values()->all());
        $this->assertSame(['TestBrowser/1.0'], $entries->pluck('user_agent')->unique()->values()->all());

        // What was typed is not stored, only a keyed fingerprint of the username.
        $failed = $entries->first();
        $this->assertSame(AdminAuditLog::usernameFingerprint('maria'), $failed->details['username_hash']);
        $this->assertStringNotContainsString('maria', json_encode($entries->pluck('details')));
        $this->assertStringNotContainsString('wrong', json_encode($entries->pluck('details')));
    }

    public function test_refused_sign_ins_record_the_account_status(): void
    {
        $user = User::factory()->create(['password_hash' => Hash::make('Correct-pass123!')]);
        $user->forceFill(['account_status' => 'suspended'])->save();

        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Correct-pass123!'])->assertForbidden();

        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', ['action' => 'login_refused', 'actor_user_id' => $user->user_id]);
    }
}
