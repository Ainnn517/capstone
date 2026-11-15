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
