<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

class IncidentPriorityCountsTest extends TestCase
{
    public function test_summary_counts_open_incidents_by_priority(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $location = Location::create(['name' => 'Main Street', 'area_name' => 'Barangay One']);
        $critical = Category::create(['name' => 'Public Safety', 'severity_weight' => 5]);
        $high = Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        $normal = Category::create(['name' => 'Sanitation', 'severity_weight' => 1]);

        $report = fn (Category $category, string $status = 'Submitted', bool $archived = false) => IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $resident->user_id,
            'category_id' => $category->category_id,
            'description' => 'Test report.',
            'location_id' => $location->location_id,
            'status' => $status,
            'date_resolved' => $status === 'Resolved' ? now() : null,
            'archived_at' => $archived ? now() : null,
        ]);
        $report($critical);
        $report($critical, 'Validated');
        $report($high, 'Dispatched');
        $report($normal);
        // Not open: resolved, rejected, or archived reports are not counted.
        $report($critical, 'Resolved');
        $report($high, 'Rejected');
        $report($normal, 'Submitted', archived: true);

        $this->actingAs($administrator)
            ->getJson('/api/analytics/summary')
            ->assertOk()
            ->assertJsonPath('summary.openByPriority', ['critical' => 2, 'high' => 1, 'normal' => 1]);
    }
}
