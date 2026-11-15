<?php

namespace Tests\Feature;

use App\Mail\ReportStatusMail;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ReportEmailAlertTest extends TestCase
{
    private User $resident;

    private User $admin;

    private IssueReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->resident = User::factory()->create();
        $this->admin = User::factory()->admin()->create();
        Team::create(['name' => 'Road Crew']);
        $category = Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        $location = Location::create(['name' => 'Main Street', 'area_name' => 'Barangay One']);
        $this->report = IssueReport::create([
            'tracking_code' => 'BR-ALERT-1',
            'user_id' => $this->resident->user_id,
            'category_id' => $category->category_id,
            'description' => 'A large pothole near the corner.',
            'location_id' => $location->location_id,
            'status' => 'Submitted',
        ]);
    }

    public function test_resident_gets_an_email_for_each_status_change(): void
    {
        $this->move('Validated', 'Unassigned', 'Checked by the barangay.');
        $this->move('Dispatched', 'Road Crew', 'The road crew is on the way.');
        $this->move('Resolved', 'Road Crew', 'The pothole was filled.');

        Mail::assertSent(ReportStatusMail::class, 3);
        Mail::assertSent(ReportStatusMail::class, fn (ReportStatusMail $mail) => $mail->hasTo($this->resident->email)
            && $mail->status === 'Dispatched'
            && $mail->headline === 'The Road Crew has been sent to handle your report.'
            && $mail->respondBy === null);
        Mail::assertSent(ReportStatusMail::class, function (ReportStatusMail $mail) {
            $html = $mail->render();

            return $mail->status === 'Resolved'
                && $mail->respondBy !== null
                && $mail->adminMessage === 'The pothole was filled.'
                && str_contains($html, 'Is the problem fixed?')
                && str_contains($html, '/email-alerts/');
        });
    }

    public function test_saving_without_a_status_change_sends_nothing(): void
    {
        $this->move('Submitted', 'Unassigned', 'Just a note.');

        Mail::assertNothingSent();
    }

    public function test_no_email_when_the_resident_turned_alerts_off(): void
    {
        $this->actingAs($this->resident)->getJson('/api/session')->assertJsonPath('user.emailNotifications', true);
        $this->actingAs($this->resident)->postJson('/api/account/email-alerts', ['enabled' => 'no'])->assertStatus(422);
        $this->actingAs($this->resident)->postJson('/api/account/email-alerts', ['enabled' => false])
            ->assertOk()->assertJsonPath('emailNotifications', false);

        $this->move('Validated', 'Unassigned', 'Checked by the barangay.');

        Mail::assertNothingSent();
        $this->actingAs($this->admin)->postJson('/api/account/email-alerts', ['enabled' => true])->assertForbidden();
    }

    public function test_the_email_link_turns_alerts_off_only_after_pressing_the_button(): void
    {
        $url = URL::signedRoute('email-alerts.unsubscribe', ['user' => $this->resident->user_id]);

        $this->get($url)->assertOk()->assertSee('Stop emails about your reports?');
        $this->assertTrue($this->resident->fresh()->email_notifications);

        $this->post($url)->assertOk()->assertSee('Emails turned off');
        $this->assertFalse($this->resident->fresh()->email_notifications);

        // A tampered link is refused.
        $this->get(str_replace('/'.$this->resident->user_id.'/', '/'.$this->admin->user_id.'/', $url))->assertForbidden();
    }

    private function move(string $status, string $team, string $note): void
    {
        $this->actingAs($this->admin)->postJson('/api/reports/update', [
            'id' => $this->report->report_id,
            'status' => $status,
            'assignee' => $team,
            'dispatchNote' => $note,
        ])->assertOk();
    }
}
