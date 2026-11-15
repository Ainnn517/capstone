<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

class HotspotMapTest extends TestCase
{
    private User $resident;

    private Category $roads;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resident = User::factory()->create();
        $this->roads = Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        $this->location = Location::create(['name' => 'Main Street', 'area_name' => 'Barangay One']);
    }

    public function test_map_lists_open_reports_that_have_a_gps_pin(): void
    {
        $pinned = $this->report('Submitted', 14.3286, 121.0155);
        $this->report('Validated');
        $this->report('Resolved', 14.3290, 121.0160);
        $archived = $this->report('Dispatched', 14.3300, 121.0165);
        $archived->update(['archived_at' => now()]);

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->getJson('/api/analytics/map')->assertOk();

        $response->assertJsonPath('scope', 'open')
            ->assertJsonPath('reportsInScope', 2)
            ->assertJsonPath('withoutLocation', 1)
            ->assertJsonPath('hotspotThreshold', 3)
            ->assertJsonCount(1, 'points');
        $point = $response->json('points.0');
        $this->assertSame((string) $pinned->report_id, $point['id']);
        $this->assertSame('Road Damage', $point['category']);
        $this->assertSame('High', $point['priority']);
        $this->assertSame('Main Street, Barangay One', $point['area']);
        $this->assertEqualsWithDelta(14.3286, $point['lat'], 0.00001);
        $this->assertEqualsWithDelta(121.0155, $point['lng'], 0.00001);
        $this->assertArrayNotHasKey('description', $point);
        $this->assertArrayNotHasKey('residentId', $point);
    }

    public function test_all_scope_includes_closed_reports(): void
    {
        $this->report('Submitted', 14.3286, 121.0155);
        $this->report('Resolved', 14.3290, 121.0160);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/analytics/map?scope=all')
            ->assertOk()
            ->assertJsonPath('scope', 'all')
            ->assertJsonCount(2, 'points');
    }

    public function test_a_pin_outside_the_barangay_is_refused(): void
    {
        $report = fn (float $lat, float $lng) => $this->actingAs($this->resident)->postJson('/api/reports', [
            'category' => 'Road Damage',
            'location_id' => $this->location->location_id,
            'description' => 'A large pothole near the corner.',
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        $refused = 'The map pin is outside Barangay Langgam. Move the pin to where the problem is, or remove it.';
        // San Jose del Monte, Bulacan (a different city) and Southville 3A (next door).
        $report(14.8136, 121.0450)->assertStatus(422)->assertJsonPath('message', $refused);
        $report(14.3323, 121.0086)->assertStatus(422)->assertJsonPath('message', $refused);
        // St. Joseph Village 10, St. Joseph Ridgeview and Southern Heights 2 are all served.
        $report(14.3286, 121.0155)->assertCreated();
        $report(14.3193, 121.0078)->assertCreated();
        $report(14.3368, 121.0224)->assertCreated();
    }

    public function test_only_admins_can_see_the_map(): void
    {
        $this->getJson('/api/analytics/map')->assertUnauthorized();
        $this->actingAs($this->resident)->getJson('/api/analytics/map')->assertForbidden();
    }

    private function report(string $status, ?float $latitude = null, ?float $longitude = null): IssueReport
    {
        return IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $this->resident->user_id,
            'category_id' => $this->roads->category_id,
            'description' => 'A large pothole near the corner.',
            'location_id' => $this->location->location_id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'status' => $status,
            'date_submitted' => now()->subDay(),
            'date_resolved' => $status === 'Resolved' ? now() : null,
        ]);
    }
}
