<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    private User $admin;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->resident = User::factory()->create(['full_name' => 'Maria Santos']);
        $team = Team::create(['name' => 'Road Crew']);
        $roads = Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        $location = Location::create(['name' => 'Main Street', 'area_name' => 'Southern Heights']);
        $base = ['user_id' => $this->resident->user_id, 'category_id' => $roads->category_id, 'location_id' => $location->location_id];

        IssueReport::create($base + [
            'tracking_code' => 'BR-NOV-1', 'description' => '=HYPERLINK("http://evil")', 'status' => 'Resolved',
            'team_id' => $team->team_id, 'date_submitted' => '2026-11-02 08:00:00', 'date_resolved' => '2026-11-04 08:00:00',
            'resident_confirmed_at' => '2026-11-05 08:00:00',
        ]);
        IssueReport::create($base + [
            'tracking_code' => 'BR-NOV-2', 'description' => 'Pothole near the gate', 'status' => 'Submitted',
            'date_submitted' => '2026-11-10 09:00:00', 'archived_at' => '2026-11-12 09:00:00',
        ]);
        IssueReport::create($base + [
            'tracking_code' => 'BR-OCT-1', 'description' => 'Older report', 'status' => 'Submitted',
            'date_submitted' => '2026-10-20 09:00:00',
        ]);
    }

    public function test_csv_lists_the_months_reports_without_resident_details(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/reports/export?from=2026-11-01&to=2026-11-30');

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('barangay-reports-2026-11-01-to-2026-11-30.csv', $response->headers->get('content-disposition'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
        $this->assertSame('Tracking code', $rows[0][0]);
        $this->assertCount(3, $rows); // header + 2 November reports (the archived one included)
        $byCode = collect(array_slice($rows, 1))->keyBy(0);
        $this->assertSame("'=HYPERLINK(\"http://evil\")", $byCode['BR-NOV-1'][3]); // formula neutralised
        $this->assertSame('High', $byCode['BR-NOV-1'][4]);
        $this->assertSame('2', $byCode['BR-NOV-1'][11]);
        $this->assertSame('Confirmed fixed', $byCode['BR-NOV-1'][12]);
        $this->assertSame('Yes', $byCode['BR-NOV-2'][14]);
        $this->assertStringNotContainsString('Maria Santos', $csv);
        $this->assertStringNotContainsString($this->resident->email, $csv);
        $this->assertSame(1, AdminAuditLog::where('action', 'export_reports')->count());
    }

    public function test_csv_rejects_bad_ranges_and_non_admins(): void
    {
        $this->actingAs($this->admin)->getJson('/api/reports/export?from=2026-11-30&to=2026-11-01')->assertStatus(422);
        $this->actingAs($this->admin)->getJson('/api/reports/export?from=2024-01-01&to=2026-11-01')->assertStatus(422);
        $this->actingAs($this->resident)->getJson('/api/reports/export?from=2026-11-01&to=2026-11-30')->assertForbidden();
    }

    public function test_monthly_summary_page(): void
    {
        $page = $this->actingAs($this->admin)->get('/admin/summary?month=2026-11');

        $page->assertOk()
            ->assertSee('Monthly Report Summary: November 2026')
            ->assertSee('Road Damage')
            ->assertSee('Southern Heights')
            ->assertSee('Road Crew')
            ->assertSee('2 days')
            ->assertSee('1 confirmed fixed by the resident', false)
            ->assertDontSee('Maria Santos');
        $this->assertSame(2, $page->viewData('totals')['submitted']);
        $this->assertSame(1, $page->viewData('totals')['resolved']);

        $this->actingAs($this->resident)->get('/admin/summary')->assertRedirect('/resident');
        auth()->logout();
        $this->get('/admin/summary')->assertRedirect('/');
    }
}
