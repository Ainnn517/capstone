<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ProxyTrust;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProxyHeaderTrustTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Same trust as production behind the Cloudflare tunnel.
        ProxyTrust::apply(['127.0.0.1']);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    public function test_a_forwarded_host_header_never_reaches_the_reset_link(): void
    {
        $user = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Host' => 'attacker.example', 'X-Forwarded-Proto' => 'https'])
            ->postJson('/api/password/forgot', ['email' => $user->email])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $link = $notification->toMail($user)->actionUrl;

            return ! str_contains($link, 'attacker.example') && str_contains($link, '/reset-password/');
        });
    }

    public function test_private_files_have_no_public_storage_route(): void
    {
        $this->assertFalse($this->app['router']->has('storage.local'));
        $this->assertFalse($this->app['router']->has('storage.local.upload'));
    }

    public function test_the_client_ip_comes_from_the_tunnel_and_cannot_be_spoofed(): void
    {
        $seen = null;
        $this->app['router']->get('/__ip-probe', function () use (&$seen) {
            $seen = request()->ip();

            return 'ok';
        });

        // Cloudflare appends the real client address last; an earlier, client-supplied
        // value must be ignored.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => '6.6.6.6, 203.0.113.9'])
            ->get('/__ip-probe')
            ->assertOk();

        $this->assertSame('203.0.113.9', $seen);
    }
}
