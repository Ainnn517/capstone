<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use App\Services\InsightService;
use Tests\TestCase;

class InsightTest extends TestCase
{
    private User $resident;

    private Category $roads;

    private Team $slow;

    private Team $fast;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resident = User::factory()->create();
        $this->slow = Team::create(['name' => 'Engineering Team']);
        $this->fast = Team::create(['name' => 'Road Crew']);
        $this->roads = Category::create(['name' => 'Road Damage', 'severity_weight' => 3, 'recommended_team_id' => $this->slow->team_id]);
    }

    public function test_team_suggestion_learns_from_the_fastest_resolver(): void
    {
        $insights = app(InsightService::class);

        // Not enough history yet: falls back to the team configured in Reference Data.
        $this->resolved($this->fast, 1);
        $suggestion = $insights->recommendTeam('Road Damage', 'Engineering Team');
        $this->assertSame('Engineering Team', $suggestion['team']);
        $this->assertSame('configured', $suggestion['source']);

        $this->resolved($this->fast, 2);
        $this->resolved($this->slow, 6);
        $this->resolved($this->slow, 8);

        $suggestion = app(InsightService::class)->recommendTeam('Road Damage', 'Engineering Team');
        $this->assertSame('Road Crew', $suggestion['team']);
        $this->assertSame('history', $suggestion['source']);
        $this->assertStringContainsString('Road Crew resolved 2 Road Damage reports in 1.5 days on average', $suggestion['reason']);
    }

    public function test_old_resolutions_outside_the_window_are_ignored(): void
    {
        $this->resolved($this->fast, 1, resolvedDaysAgo: 400);
        $this->resolved($this->fast, 1, resolvedDaysAgo: 300);

        $suggestion = app(InsightService::class)->recommendTeam('Road Damage', 'Engineering Team');
        $this->assertSame('Engineering Team', $suggestion['team']);
    }

    public function test_open_reports_past_their_target_are_flagged_overdue(): void
    {
        // Road Damage has weight 3, so priority High with a 5-day target.
        $late = $this->report('Validated', submittedDaysAgo: 6);
        $onTime = $this->report('Submitted', submittedDaysAgo: 2);
        $this->report('Withdrawn', submittedDaysAgo: 30);

        $admin = User::factory()->admin()->create();
        $reports = collect($this->actingAs($admin)->getJson('/api/reports')->assertOk()->json('reports'))->keyBy('id');
        $this->assertTrue($reports[(string) $late->report_id]['overdue']);
        $this->assertFalse($reports[(string) $onTime->report_id]['overdue']);

        $summary = $this->actingAs($admin)->getJson('/api/analytics/summary')->assertOk()->json('summary.resolution');
        $this->assertSame(1, $summary['overdueTotal']);
        $this->assertSame(['category' => 'Road Damage', 'resolved' => 0, 'averageDays' => null, 'targetDays' => 5, 'overdue' => 1], $summary['categories'][0]);

        $dss = $this->actingAs($admin)->getJson('/api/analytics/dss')->assertOk()->json();
        $this->assertSame(1, $dss['overdueTotal']);
    }

    public function test_dss_and_triage_explain_the_learned_team(): void
    {
        $this->resolved($this->fast, 1);
        $this->resolved($this->fast, 1);
        $open = $this->report('Submitted', submittedDaysAgo: 1);
        $admin = User::factory()->admin()->create();

        $dss = $this->actingAs($admin)->getJson('/api/analytics/dss')->assertOk()->json();
        $recommendation = $dss['recommendations'][0];
        $this->assertSame('Road Crew', $recommendation['recommendedTeam']);
        $this->assertSame('history', $recommendation['teamSource']);
        $this->assertStringContainsString('Road Crew resolved 2', $recommendation['teamReason']);

        $triage = $this->actingAs($admin)->getJson('/api/reports/'.$open->report_id.'/triage')->assertOk()->json();
        $team = $triage['team'];
        $this->assertSame('Road Crew', $team['team']);
    }

    private function resolved(Team $team, float $daysToResolve, int $resolvedDaysAgo = 3): IssueReport
    {
        $resolvedAt = now()->subDays($resolvedDaysAgo);

        return $this->report('Resolved', team: $team, submittedAt: $resolvedAt->copy()->subMinutes((int) round($daysToResolve * 1440)), resolvedAt: $resolvedAt);
    }

    private function report(string $status, ?Team $team = null, ?int $submittedDaysAgo = null, $submittedAt = null, $resolvedAt = null): IssueReport
    {
        $location = Location::firstOrCreate(['name' => 'Main Street', 'area_name' => 'Barangay One']);

        return IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $this->resident->user_id,
            'category_id' => $this->roads->category_id,
            'team_id' => $team?->team_id,
            'description' => 'A large pothole near the corner.',
            'location_id' => $location->location_id,
            'status' => $status,
            'date_submitted' => $submittedAt ?? now()->subDays($submittedDaysAgo ?? 0),
            'date_resolved' => $resolvedAt,
        ]);
    }
}
