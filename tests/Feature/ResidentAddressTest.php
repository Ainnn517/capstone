<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ResidentAddressTest extends TestCase
{
    private Location $street;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->street = Location::create(['name' => 'Rizal Street', 'area_name' => 'Phase 1', 'is_active' => true]);
    }

    private function register(array $changes = []): TestResponse
    {
        return $this->postJson('/api/register', array_merge([
            'fullName' => 'Maria Santos',
            'username' => 'mariasantos',
            'email' => 'maria@example.test',
            'password' => 'A-secure-test-password1!',
            'locationId' => $this->street->location_id,
            'addressDetails' => 'Blk 4 Lot 12',
            'mobile' => '+63 917-123-4567',
            'privacyConsent' => true,
        ], $changes));
    }

    public function test_sign_up_stores_the_home_address_and_a_normalised_mobile_number(): void
    {
        $this->register()->assertAccepted();

        $user = User::where('username', 'mariasantos')->firstOrFail();
        $this->assertSame($this->street->location_id, (int) $user->address_location_id);
        $this->assertSame('Blk 4 Lot 12', $user->address_details);
        $this->assertSame('09171234567', $user->contact_number);
        $this->assertSame('Blk 4 Lot 12, Rizal Street, Phase 1', $user->addressLine());
    }

    public function test_address_is_required_and_the_mobile_number_is_optional_but_checked(): void
    {
        $this->register(['locationId' => null])->assertUnprocessable()
            ->assertJsonPath('message', 'Choose your village / phase and street.');
        $this->register(['addressDetails' => ''])->assertUnprocessable()
            ->assertJsonPath('message', 'Enter your house number, building, or a nearby landmark.');
        $inactive = Location::create(['name' => 'Old Road', 'area_name' => 'Phase 1', 'is_active' => false]);
        $this->register(['locationId' => $inactive->location_id])->assertUnprocessable();
        $this->register(['mobile' => '12345'])->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'valid mobile number'));

        $this->register(['mobile' => ''])->assertAccepted();
        $this->assertNull(User::where('username', 'mariasantos')->value('contact_number'));
    }

    public function test_sign_up_reports_every_problem_at_once(): void
    {
        $response = $this->register([
            'password' => 'short',
            'addressDetails' => '',
            'mobile' => '12345',
            'privacyConsent' => false,
        ])->assertUnprocessable();

        $errors = $response->json('errors');
        $this->assertGreaterThanOrEqual(4, count($errors));
        $this->assertSame($errors[0], $response->json('message'));
        $this->assertContains('Enter your house number, building, or a nearby landmark.', $errors);
        $this->assertContains('Please agree to the privacy notice to continue.', $errors);
        $this->assertTrue(collect($errors)->contains(fn (string $error): bool => str_contains($error, 'valid mobile number')));
        $this->assertTrue(collect($errors)->contains(fn (string $error): bool => str_contains($error, 'password')));
        $this->assertDatabaseMissing('USERS', ['username' => 'mariasantos']);
    }

    public function test_a_rejected_application_frees_its_username_and_email(): void
    {
        $this->register()->assertAccepted();
        $old = User::where('username', 'mariasantos')->firstOrFail();
        $old->forceFill(['account_status' => 'rejected'])->save();

        $this->register(['fullName' => 'Maria S. Santos'])->assertAccepted();

        $this->assertDatabaseMissing('USERS', ['user_id' => $old->user_id]);
        $new = User::where('username', 'mariasantos')->firstOrFail();
        $this->assertSame('pending', $new->account_status);
        $this->assertSame('Maria S. Santos', $new->full_name);
        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', ['action' => 'replace_rejected_application']);
    }

    public function test_a_suspended_or_pending_account_still_holds_its_username(): void
    {
        $this->register()->assertAccepted();
        User::where('username', 'mariasantos')->firstOrFail()->forceFill(['account_status' => 'suspended'])->save();

        $this->register(['email' => 'other@example.test'])->assertUnprocessable()
            ->assertJsonPath('message', 'That username is already taken. Please choose another.');
    }

    public function test_admin_sees_the_address_and_mobile_number(): void
    {
        $this->register()->assertAccepted();

        $resident = collect($this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/residents')->assertOk()->json('residents'))->firstWhere('username', 'mariasantos');

        $this->assertSame('Blk 4 Lot 12, Rizal Street, Phase 1', $resident['address']);
        $this->assertSame('09171234567', $resident['mobile']);
    }

    public function test_the_sign_up_form_can_list_active_locations_without_signing_in(): void
    {
        Location::create(['name' => 'Closed Lane', 'area_name' => 'Phase 2', 'is_active' => false]);

        $names = collect($this->getJson('/api/locations')->assertOk()->json('locations'))->pluck('name');

        $this->assertContains('Rizal Street', $names);
        $this->assertNotContains('Closed Lane', $names);
    }
}
