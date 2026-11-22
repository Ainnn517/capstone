<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DssAnalyticsRecord;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use App\Services\DssRecommendationService;
use Tests\TestCase;

class DssRecommendationTest extends TestCase
{
    public function test_dss_uses_severity_breakdown_team_mapping_and_safe_location_grouping(): void
    {
        config([
            'dss.weights.report_count' => 3,
            'dss.weights.average_age_days' => 1,
            'dss.weights.severity_weight' => 1,
            'dss.high_urgency_threshold' => 8,
            'dss.team_by_category.Public Safety' => 'Barangay Safety Team',
        ]);
        $resident = User::factory()->create();
        $team = Team::create(['name' => 'Barangay Safety Team']);
        $category = Category::create([
            'name' => 'Public Safety',
            'description' => null,
            'severity_weight' => 5,
            'recommended_team_id' => $team->team_id,
        ]);
        $location = Location::create(['name' => 'Purok|One', 'area_name' => 'Village']);
        IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $resident->user_id,
            'category_id' => $category->category_id,
            'description' => 'A report requiring a safety response.',
            'location_id' => $location->location_id,
            'status' => 'Submitted',
            'date_submitted' => now()->subDays(2),
        ]);

        $result = app(DssRecommendationService::class)->recommendations();
        $recommendation = $result['recommendations'][0];

        $this->assertSame(1, $result['sourceReportCount']);
        $this->assertSame('Purok|One, Village', $recommendation['location']);
        $this->assertSame('Public Safety', $recommendation['category']);
        $this->assertSame($team->name, $recommendation['recommendedTeam']);
        $this->assertSame(3, $recommendation['scoreBreakdown']['reportCount']);
        $this->assertSame(5, $recommendation['scoreBreakdown']['severity']);
        $this->assertSame(10.0, $recommendation['score']);
        $this->assertSame('High', $recommendation['urgency']);
    }

    public function test_a_new_critical_report_outranks_an_old_minor_one_because_age_points_are_capped(): void
    {
        config([
            'dss.weights' => ['report_count' => 3, 'average_age_days' => 1, 'severity_weight' => 4],
            'dss.max_age_days' => 7,
            'dss.high_urgency_threshold' => 20,
        ]);
        $resident = User::factory()->create();
        $critical = Category::create(['name' => 'Public Safety', 'severity_weight' => 5]);
        $high = Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        $minor = Category::create(['name' => 'Others', 'severity_weight' => 1]);
        $report = function (Category $category, string $street, int $daysOld) use ($resident): void {
            IssueReport::create([
                'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
                'user_id' => $resident->user_id,
                'category_id' => $category->category_id,
                'description' => 'Test report.',
                'location_id' => Location::create(['name' => $street, 'area_name' => 'Phase 1'])->location_id,
                'status' => 'Submitted',
                'date_submitted' => now()->subDays($daysOld),
            ]);
        };
        $report($minor, 'Old Request Street', 60);   // 3 + 7 (capped, not 60) + 4 = 14
        $report($high, 'Old Pothole Street', 30);    // 3 + 7 + 12 = 22
        $report($critical, 'Manhole Street', 0);     // 3 + 0 + 20 = 23

        $ranking = app(DssRecommendationService::class)->recommendations()['recommendations'];

        $this->assertSame(['Manhole Street, Phase 1', 'Old Pothole Street, Phase 1', 'Old Request Street, Phase 1'], array_column($ranking, 'location'));
        $this->assertEquals(7, $ranking[2]['scoreBreakdown']['averageAgeDays'], 'Age points stop at max_age_days.');
        $this->assertGreaterThan(50, $ranking[2]['averageAgeDays'], 'The real age is still reported.');
        $this->assertSame(['High', 'High', 'Moderate'], array_column($ranking, 'urgency'));
    }

    public function test_dss_excludes_closed_and_archived_reports(): void
    {
        $resident = User::factory()->create();
        $category = Category::create(['name' => 'Sanitation', 'severity_weight' => 1]);
        $location = Location::create(['name' => 'Main Street', 'area_name' => 'Village']);
        foreach ([
            ['status' => 'Submitted', 'archived_at' => null],
            ['status' => 'Resolved', 'archived_at' => null],
            ['status' => 'Submitted', 'archived_at' => now()],
        ] as $index => $attributes) {
            IssueReport::create([
                'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
                'user_id' => $resident->user_id,
                'category_id' => $category->category_id,
                'description' => 'A sanitation report.',
                'location_id' => $location->location_id,
                ...$attributes,
                'date_resolved' => $attributes['status'] === 'Resolved' ? now() : null,
            ]);
        }

        $result = app(DssRecommendationService::class)->recommendations();

        $this->assertSame(1, $result['sourceReportCount']);
        $this->assertCount(1, $result['recommendations']);
        $this->assertSame(1, $result['recommendations'][0]['reportCount']);
    }

    public function test_administrator_can_save_and_read_dss_snapshot_history(): void
    {
        $administrator = User::factory()->admin()->create();

        $this->actingAs($administrator)
            ->postJson('/api/analytics/dss/snapshots')
            ->assertCreated()
            ->assertJsonPath('snapshot.sourceReportCount', 0)
            ->assertJsonPath('snapshot.recommendations', []);

        $this->getJson('/api/analytics/dss')
            ->assertOk()
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.sourceReportCount', 0);

        $this->assertSame(1, DssAnalyticsRecord::count());
    }
}
