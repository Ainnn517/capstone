<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use Tests\TestCase;

class ResidentReportChangesTest extends TestCase
{
    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resident = User::factory()->create();
        Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        Category::create(['name' => 'Clogged Drainage', 'severity_weight' => 3]);
    }

    public function test_resident_can_edit_a_report_before_it_is_reviewed(): void
    {
        $report = $this->report('Submitted');

        $this->actingAs($this->resident)
            ->putJson('/api/reports/'.$report->report_id, [
                'category' => 'Clogged Drainage',
                'description' => 'Actually the canal is blocked, not the road.',
                'location' => 'Beside the chapel',
            ])
            ->assertOk();

        $report->refresh();
        $this->assertSame('Clogged Drainage', $report->category->name);
        $this->assertSame('Actually the canal is blocked, not the road.', $report->description);
        $this->assertSame('Beside the chapel', $report->landmark);
        $this->assertSame('Submitted', $report->status);
        $this->assertDatabaseHas('STATUS_LOG', ['report_id' => $report->report_id, 'remarks' => 'Report edited by the resident.']);
    }

    public function test_reports_cannot_be_edited_once_reviewed_or_by_someone_else(): void
    {
        $validated = $this->report('Validated');
        $this->actingAs($this->resident)
            ->putJson('/api/reports/'.$validated->report_id, ['category' => 'Road Damage', 'description' => 'Changed.'])
            ->assertStatus(409);

        $mine = $this->report('Submitted');
        $this->actingAs(User::factory()->create())
            ->putJson('/api/reports/'.$mine->report_id, ['category' => 'Road Damage', 'description' => 'Hijacked.'])
            ->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/reports/'.$mine->report_id, ['category' => 'Road Damage', 'description' => 'Admin edit.'])
            ->assertForbidden();

        $this->assertSame('A large pothole near the corner.', $mine->fresh()->description);
    }

    public function test_resident_can_withdraw_before_dispatch_and_it_becomes_read_only(): void
    {
        foreach (['Submitted', 'Validated'] as $status) {
            $report = $this->report($status);

            $this->actingAs($this->resident)
                ->postJson('/api/reports/'.$report->report_id.'/withdraw', ['reason' => 'The problem was fixed.'])
                ->assertOk();

            $this->assertSame('Withdrawn', $report->fresh()->status);
            $this->assertDatabaseHas('STATUS_LOG', [
                'report_id' => $report->report_id,
                'old_status' => $status,
                'new_status' => 'Withdrawn',
                'remarks' => 'Withdrawn by the resident: The problem was fixed.',
            ]);
        }

        // Administrators can no longer change a withdrawn report.
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/reports/update', [
                'id' => $report->report_id,
                'status' => 'Withdrawn',
                'assignee' => 'Unassigned',
                'dispatchNote' => 'Trying to reopen.',
            ])
            ->assertStatus(409);
    }

    public function test_dispatched_or_closed_reports_cannot_be_withdrawn(): void
    {
        $team = Team::create(['name' => 'Field Team A']);
        $dispatched = $this->report('Dispatched', $team);
        $this->actingAs($this->resident)
            ->postJson('/api/reports/'.$dispatched->report_id.'/withdraw')
            ->assertStatus(409)
            ->assertJsonPath('message', 'A team has already been sent, so this report can no longer be withdrawn. Contact the barangay office if it is no longer needed.');

        $resolved = $this->report('Resolved', $team);
        $this->postJson('/api/reports/'.$resolved->report_id.'/withdraw')->assertStatus(409);
        $this->assertSame('Dispatched', $dispatched->fresh()->status);
    }

    public function test_withdrawn_reports_count_as_past_and_leave_the_open_queue(): void
    {
        $open = $this->report('Submitted');
        $withdrawn = $this->report('Submitted');
        $this->actingAs($this->resident)->postJson('/api/reports/'.$withdrawn->report_id.'/withdraw')->assertOk();

        $this->getJson('/api/reports?view=current')->assertJsonCount(1, 'reports')->assertJsonPath('reports.0.id', (string) $open->report_id);
        $this->getJson('/api/reports?view=past')->assertJsonCount(1, 'reports')->assertJsonPath('reports.0.stage', 'Withdrawn');

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/analytics/summary')
            ->assertJsonPath('summary.openByPriority.high', 1);
    }

    private function report(string $status, ?Team $team = null): IssueReport
    {
        $location = Location::firstOrCreate(['name' => 'Main Street', 'area_name' => 'Barangay One']);

        return IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $this->resident->user_id,
            'category_id' => Category::where('name', 'Road Damage')->value('category_id'),
            'team_id' => $team?->team_id,
            'description' => 'A large pothole near the corner.',
            'location_id' => $location->location_id,
            'status' => $status,
            'date_resolved' => $status === 'Resolved' ? now() : null,
        ]);
    }
}
