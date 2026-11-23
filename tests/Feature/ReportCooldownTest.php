<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// A resident may send one report a minute, so a double tap or a slow connection cannot
// create the same report several times with different tracking codes.
class ReportCooldownTest extends TestCase
{
    private User $resident;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.report_cooldown_seconds' => 60]);
        $this->resident = User::factory()->create();
        Category::create(['name' => 'Sanitation', 'severity_weight' => 2]);
        $this->location = Location::create(['name' => 'Main Street', 'area_name' => 'Barangay One']);
    }

    private function submit(?User $resident = null)
    {
        return $this->actingAs($resident ?? $this->resident)->post('/api/reports', [
            'category' => 'Sanitation',
            'location_id' => $this->location->location_id,
            'description' => 'Trash in the streets',
        ], ['Accept' => 'application/json']);
    }

    public function test_a_second_report_within_a_minute_is_refused_with_the_time_left(): void
    {
        $this->submit()->assertCreated()->assertJsonPath('nextReportIn', 60);

        $refused = $this->submit()->assertStatus(429)->assertHeader('Retry-After');
        $this->assertGreaterThanOrEqual(58, $refused->json('retryAfter'));
        $this->assertLessThanOrEqual(60, $refused->json('retryAfter'));
        $this->assertStringStartsWith('You just sent a report. Please wait', $refused->json('message'));
        $this->assertSame(1, IssueReport::count());
    }

    public function test_the_resident_can_report_again_after_a_minute(): void
    {
        $this->submit()->assertCreated();
        // Move the first report back in time instead of waiting.
        DB::table('ISSUE_REPORT')->update(['date_submitted' => DB::raw('NOW() - INTERVAL 61 SECOND')]);

        $this->submit()->assertCreated();
        $this->assertSame(2, IssueReport::count());
    }

    public function test_the_wait_applies_to_each_resident_separately(): void
    {
        $this->submit()->assertCreated();
        $this->submit(User::factory()->create())->assertCreated();
        $this->assertSame(2, IssueReport::count());
    }

    public function test_the_wait_can_be_turned_off(): void
    {
        config(['security.report_cooldown_seconds' => 0]);
        $this->submit()->assertCreated();
        $this->submit()->assertCreated();
        $this->assertSame(2, IssueReport::count());
    }
}
