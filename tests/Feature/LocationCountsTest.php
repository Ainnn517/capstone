<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

class LocationCountsTest extends TestCase
{
    public function test_summary_counts_reports_per_location_and_period(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));

        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $mango = Location::create(['name' => 'Mango Street', 'area_name' => 'Phase 1']);
        $pine = Location::create(['name' => 'Pine Street', 'area_name' => 'Phase 3']);
        $drainage = Category::create(['name' => 'Clogged Drainage', 'severity_weight' => 3]);

        $report = fn (Location $location, string $submitted, string $status = 'Submitted', bool $archived = false) => IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $resident->user_id,
            'category_id' => $drainage->category_id,
            'description' => 'Test report.',
            'location_id' => $location->location_id,
            'status' => $status,
            'date_submitted' => $submitted,
            'date_resolved' => $status === 'Resolved' ? now() : null,
            'archived_at' => $archived ? now() : null,
        ]);
        $report($mango, '2026-11-02 08:00:00');
        $report($mango, '2026-11-10 08:00:00', 'Resolved'); // resolved reports still count
        $report($mango, '2026-10-20 08:00:00');
        $report($mango, '2025-06-01 08:00:00');
        $report($pine, '2026-11-03 08:00:00');
        // Not counted: rejected or archived.
        $report($pine, '2026-11-04 08:00:00', 'Rejected');
        $report($pine, '2026-11-05 08:00:00', 'Submitted', archived: true);

        $rows = collect(
            $this->actingAs($administrator)
                ->getJson('/api/analytics/summary')
                ->assertOk()
                ->json('summary.locationCounts')
        )->keyBy('street');

        $this->assertSame(
            ['thisMonth' => 2, 'lastMonth' => 1, 'thisYear' => 3, 'all' => 4],
            $rows['Mango Street']['counts'],
        );
        $this->assertSame('Phase 1', $rows['Mango Street']['area']);
        $this->assertSame('Clogged Drainage', $rows['Mango Street']['category']);
        $this->assertSame(
            ['thisMonth' => 1, 'lastMonth' => 0, 'thisYear' => 1, 'all' => 1],
            $rows['Pine Street']['counts'],
        );
    }
}
