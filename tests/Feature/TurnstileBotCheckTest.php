<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TurnstileBotCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
    }

    private function enable(bool $cloudflareSays): void
    {
        config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret' => 'secret-key']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => $cloudflareSays])]);
    }

    private function registration(array $extra = []): array
    {
        $location = Location::query()->firstOrCreate(['name' => 'Sampaguita St', 'area_name' => 'Phase 1'], ['is_active' => true]);

        return [
            'fullName' => 'Juan Dela Cruz',
            'username' => 'juan-'.bin2hex(random_bytes(3)),
            'email' => 'juan'.bin2hex(random_bytes(3)).'@example.test',
            'password' => 'Str0ng-Password!',
            'locationId' => $location->location_id,
            'addressDetails' => 'Blk 1 Lot 2',
            'privacyConsent' => true,
        ] + $extra;
    }

    public function test_the_check_is_off_without_keys(): void
    {
        Http::fake();

        $this->postJson('/api/register', $this->registration())->assertStatus(202);
        $this->get('/')->assertOk()->assertDontSee('cf-turnstile', false);
        $this->assertStringNotContainsString('challenges.cloudflare.com', $this->get('/')->headers->get('Content-Security-Policy'));
        Http::assertNothingSent();
    }

    public function test_sign_up_and_forgot_password_need_a_passing_check_when_enabled(): void
    {
        $this->enable(true);

        $this->postJson('/api/register', $this->registration())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Please complete the security check and try again.');
        $this->postJson('/api/password/forgot', ['email' => 'someone@example.test'])->assertUnprocessable();

        $this->postJson('/api/register', $this->registration(['cf-turnstile-response' => 'good-token']))->assertStatus(202);
        $user = User::factory()->create();
        $this->postJson('/api/password/forgot', ['email' => $user->email, 'cf-turnstile-response' => 'good-token'])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['secret'] === 'secret-key' && $request['response'] === 'good-token');

        $page = $this->get('/');
        $page->assertSee('data-sitekey="site-key"', false);
        $this->assertStringContainsString('frame-src https://challenges.cloudflare.com', $page->headers->get('Content-Security-Policy'));
    }

    public function test_a_token_cloudflare_rejects_is_refused(): void
    {
        $this->enable(false);

        $this->postJson('/api/register', $this->registration(['cf-turnstile-response' => 'forged']))->assertUnprocessable();
        $this->assertSame(0, User::query()->where('role', 'resident')->count());
    }
}
