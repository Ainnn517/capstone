<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class UnverifiedRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.require_email_verification' => true]);
    }

    public function test_admin_sees_whether_each_resident_verified_their_email(): void
    {
        $verified = User::factory()->pending()->create();
        $unverified = tap(User::factory()->pending()->create(), fn (User $u) => $u->forceFill(['email_verified_at' => null])->save());

        $residents = collect($this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/residents')->assertOk()->json('residents'))->keyBy('id');

        $this->assertTrue($residents[(string) $verified->user_id]['emailVerified']);
        $this->assertFalse($residents[(string) $unverified->user_id]['emailVerified']);
    }

    public function test_an_unverified_account_cannot_be_approved_until_the_email_is_verified(): void
    {
        $admin = User::factory()->admin()->create();
        $resident = tap(User::factory()->pending()->create(), fn (User $u) => $u->forceFill(['email_verified_at' => null])->save());

        $this->actingAs($admin)
            ->postJson("/api/residents/{$resident->user_id}/approval", ['status' => 'approved'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'has not verified their email'));
        $this->assertSame('pending', $resident->fresh()->account_status);

        // Rejecting does not need a verified email.
        $other = tap(User::factory()->pending()->create(), fn (User $u) => $u->forceFill(['email_verified_at' => null])->save());
        $this->actingAs($admin)
            ->postJson("/api/residents/{$other->user_id}/approval", ['status' => 'rejected', 'reason' => 'Fake email address.'])
            ->assertOk();

        $resident->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($admin)
            ->postJson("/api/residents/{$resident->user_id}/approval", ['status' => 'approved'])
            ->assertOk();
        $this->assertSame('approved', $resident->fresh()->account_status);
    }

    public function test_unverified_sign_ups_are_deleted_after_seven_days(): void
    {
        $old = tap(User::factory()->pending()->create(), fn (User $u) => $u->forceFill(['email_verified_at' => null, 'created_at' => now()->subDays(8)])->save());
        $recent = tap(User::factory()->pending()->create(), fn (User $u) => $u->forceFill(['email_verified_at' => null, 'created_at' => now()->subDays(2)])->save());
        $verified = tap(User::factory()->pending()->create(), fn (User $u) => $u->forceFill(['created_at' => now()->subDays(30)])->save());

        $this->artisan('data:purge')->assertSuccessful();

        $this->assertNull(User::find($old->user_id));
        $this->assertNotNull(User::find($recent->user_id));
        $this->assertNotNull(User::find($verified->user_id));
    }
}
