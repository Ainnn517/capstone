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
