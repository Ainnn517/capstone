<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\StatusLog;
use App\Models\Team;
use App\Models\User;
use Tests\TestCase;

class ResidentConfirmationTest extends TestCase
{
    private User $resident;

    private Category $roads;

    private Team $team;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resident = User::factory()->create();
        $this->team = Team::create(['name' => 'Road Crew']);
        $this->roads = Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        $this->location = Location::create(['name' => 'Main Street', 'area_name' => 'Barangay One']);
    }

    public function test_resolved_report_waits_for_the_resident_in_current_reports(): void
    {
        $report = $this->resolvedReport();

        $current = $this->actingAs($this->resident)->getJson('/api/reports?view=current')->assertOk();
        $current->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.confirmation', 'awaiting')
            ->assertJsonPath('reports.0.canReopen', true);
        $this->actingAs($this->resident)->getJson('/api/reports?view=past')->assertOk()->assertJsonCount(0, 'reports');

        $this->actingAs($this->resident)->postJson("/api/reports/{$report->report_id}/confirm")->assertOk();

        $report->refresh();
        $this->assertNotNull($report->resident_confirmed_at);
        $this->assertSame('Resolved', $report->status);
        $this->assertSame('The resident confirmed that the issue is fixed.', StatusLog::latest('log_id')->first()->remarks);
        $this->actingAs($this->resident)->getJson('/api/reports?view=past')->assertOk()
            ->assertJsonPath('reports.0.confirmation', 'confirmed');
        $this->actingAs($this->resident)->getJson('/api/reports?view=current')->assertOk()->assertJsonCount(0, 'reports');

        // Answering twice is refused.
        $this->actingAs($this->resident)->postJson("/api/reports/{$report->report_id}/confirm")->assertStatus(409);
        $this->actingAs($this->resident)->postJson("/api/reports/{$report->report_id}/reopen", ['reason' => 'Still broken.'])->assertStatus(409);
    }

    public function test_reopening_sends_the_report_back_to_the_barangay(): void
    {
        $report = $this->resolvedReport();

        $this->actingAs($this->resident)
            ->postJson("/api/reports/{$report->report_id}/reopen", ['reason' => 'no'])
            ->assertStatus(422);
        $this->actingAs($this->resident)
            ->postJson("/api/reports/{$report->report_id}/reopen", ['reason' => 'The pothole was only partly filled.'])
            ->assertOk();

        $report->refresh();
        $this->assertSame('Validated', $report->status);
        $this->assertNull($report->date_resolved);
        $this->assertSame(1, (int) $report->reopen_count);
        $this->assertSame($this->team->team_id, $report->team_id);
        $log = StatusLog::latest('log_id')->first();
        $this->assertSame('Resolved', $log->old_status);
        $this->assertSame('Reopened by the resident: The pothole was only partly filled.', $log->remarks);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/reports?view=active')->assertOk()
            ->assertJsonPath('reports.0.reopenCount', 1)
            ->assertJsonPath('reports.0.confirmation', null);
        $this->actingAs($admin)->getJson('/api/analytics/summary')->assertOk()
            ->assertJsonPath('summary.confirmation', ['confirmed' => 0, 'awaiting' => 0, 'reopened' => 1]);
    }

    public function test_reopening_is_limited(): void
    {
        $report = $this->resolvedReport(reopenCount: 2);

        $this->actingAs($this->resident)->getJson('/api/reports?view=current')->assertOk()
            ->assertJsonPath('reports.0.canReopen', false);
        $this->actingAs($this->resident)
            ->postJson("/api/reports/{$report->report_id}/reopen", ['reason' => 'Still not fixed at all.'])
            ->assertStatus(409);
        // Confirming is still allowed.
        $this->actingAs($this->resident)->postJson("/api/reports/{$report->report_id}/confirm")->assertOk();
    }

    public function test_no_answer_within_the_window_closes_the_report_as_fixed(): void
    {
        $report = $this->resolvedReport(resolvedDaysAgo: 8);

        $this->actingAs($this->resident)->getJson('/api/reports?view=past')->assertOk()
            ->assertJsonPath('reports.0.confirmation', 'accepted');
        $this->actingAs($this->resident)->getJson('/api/reports?view=current')->assertOk()->assertJsonCount(0, 'reports');
        $this->actingAs($this->resident)
            ->postJson("/api/reports/{$report->report_id}/reopen", ['reason' => 'It is broken again.'])
            ->assertStatus(409);
    }

    public function test_only_the_owner_can_answer_and_only_for_resolved_reports(): void
    {
        $report = $this->resolvedReport();
        $other = User::factory()->create();
        $this->actingAs($other)->postJson("/api/reports/{$report->report_id}/confirm")->assertNotFound();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/reports/{$report->report_id}/confirm")->assertForbidden();

        $open = $this->resolvedReport();
        $open->update(['status' => 'Dispatched', 'date_resolved' => null]);
        $this->actingAs($this->resident)->postJson("/api/reports/{$open->report_id}/confirm")->assertStatus(409);
    }

    private function resolvedReport(int $resolvedDaysAgo = 1, int $reopenCount = 0): IssueReport
    {
        return IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $this->resident->user_id,
            'category_id' => $this->roads->category_id,
            'team_id' => $this->team->team_id,
            'description' => 'A large pothole near the corner.',
            'location_id' => $this->location->location_id,
            'status' => 'Resolved',
            'date_submitted' => now()->subDays($resolvedDaysAgo + 2),
            'date_resolved' => now()->subDays($resolvedDaysAgo),
            'reopen_count' => $reopenCount,
        ]);
    }
}
