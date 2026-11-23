<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\StatusLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
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

    public function test_withdrawing_before_review_deletes_the_report_and_its_files(): void
    {
        Storage::fake('local');
        foreach (['Submitted'] as $status) {
            $report = $this->report($status);
            $path = 'report-attachments/'.hash('sha256', $status).'.jpg';
            Storage::disk('local')->put($path, 'photo');
            Attachment::create(['report_id' => $report->report_id, 'mime_type' => 'image/jpeg', 'file_path' => $path]);
            StatusLog::create(['report_id' => $report->report_id, 'changed_by' => $this->resident->user_id, 'old_status' => null, 'new_status' => 'Submitted']);

            $this->actingAs($this->resident)
                ->postJson('/api/reports/'.$report->report_id.'/withdraw')
                ->assertOk();

            $this->assertDatabaseMissing('ISSUE_REPORT', ['report_id' => $report->report_id]);
            $this->assertDatabaseMissing('STATUS_LOG', ['report_id' => $report->report_id]);
            $this->assertDatabaseMissing('ATTACHMENT', ['report_id' => $report->report_id]);
            $this->localDisk()->assertMissing($path);
        }

        // Another resident's report cannot be withdrawn.
        $other = $this->report('Submitted');
        $this->actingAs(User::factory()->create())
            ->postJson('/api/reports/'.$other->report_id.'/withdraw')
            ->assertNotFound();
        $this->assertDatabaseHas('ISSUE_REPORT', ['report_id' => $other->report_id]);
    }

    public function test_reviewed_or_closed_reports_cannot_be_withdrawn(): void
    {
        $team = Team::create(['name' => 'Field Team A']);
        $message = 'The barangay has already reviewed this report, so it can no longer be withdrawn. Contact the barangay office if it is no longer needed.';
        foreach (['Validated' => null, 'Dispatched' => $team] as $status => $assigned) {
            $report = $this->report($status, $assigned);
            $this->actingAs($this->resident)
                ->postJson('/api/reports/'.$report->report_id.'/withdraw')
                ->assertStatus(409)
                ->assertJsonPath('message', $message);
            $this->assertSame($status, $report->fresh()->status);
        }

        $resolved = $this->report('Resolved', $team);
        $this->postJson('/api/reports/'.$resolved->report_id.'/withdraw')
            ->assertStatus(409)
            ->assertJsonPath('message', 'This report is already closed.');
        $this->assertDatabaseHas('ISSUE_REPORT', ['report_id' => $resolved->report_id]);
    }

    public function test_withdrawn_reports_leave_every_list_and_the_open_queue(): void
    {
        $open = $this->report('Submitted');
        $withdrawn = $this->report('Submitted');
        $this->actingAs($this->resident)->postJson('/api/reports/'.$withdrawn->report_id.'/withdraw')->assertOk();

        $this->getJson('/api/reports?view=current')->assertJsonCount(1, 'reports')->assertJsonPath('reports.0.id', (string) $open->report_id);
        $this->getJson('/api/reports?view=past')->assertJsonCount(0, 'reports');

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/reports?view=active')->assertJsonCount(1, 'reports');
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
