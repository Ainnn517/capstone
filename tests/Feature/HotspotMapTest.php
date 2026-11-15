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
        $pinned = $this->report('Submitted', 14.8136, 121.0450);
        $this->report('Validated');
        $this->report('Resolved', 14.8140, 121.0455);
        $archived = $this->report('Dispatched', 14.8150, 121.0460);
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
        $this->assertEqualsWithDelta(14.8136, $point['lat'], 0.00001);
        $this->assertEqualsWithDelta(121.045, $point['lng'], 0.00001);
        $this->assertArrayNotHasKey('description', $point);
        $this->assertArrayNotHasKey('residentId', $point);
    }

    public function test_all_scope_includes_closed_reports(): void
    {
        $this->report('Submitted', 14.8136, 121.0450);
        $this->report('Resolved', 14.8140, 121.0455);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/analytics/map?scope=all')
            ->assertOk()
            ->assertJsonPath('scope', 'all')
            ->assertJsonCount(2, 'points');
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
