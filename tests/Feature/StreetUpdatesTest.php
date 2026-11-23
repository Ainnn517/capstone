<?php

namespace Tests\Feature;

use App\Mail\ReportStatusMail;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\Notification;
use App\Models\Team;
use App\Models\User;
use App\Services\StreetUpdateService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StreetUpdatesTest extends TestCase
{
    private User $admin;

    private User $reporter;

    private User $neighbour;

    private User $otherStreet;

    private Location $lawaan;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Team::create(['name' => 'Maintenance Crew']);
        // Fixed emails: demo accounts are recognised by @example.com, which the factory sometimes picks.
        $this->admin = User::factory()->admin()->create(['email' => 'admin@barangay.test']);
        $this->lawaan = Location::create(['name' => 'Lawaan Street', 'area_name' => 'Phase 2']);
        $cedar = Location::create(['name' => 'Cedar Street', 'area_name' => 'Phase 3']);
        $this->reporter = User::factory()->create(['email' => 'reporter@barangay.test', 'address_location_id' => $this->lawaan->location_id]);
        $this->neighbour = User::factory()->create(['email' => 'neighbour@barangay.test', 'address_location_id' => $this->lawaan->location_id]);
        $this->otherStreet = User::factory()->create(['email' => 'cedar@barangay.test', 'address_location_id' => $cedar->location_id]);
    }

    private function report(string $category, int $severity = 3, ?User $by = null): IssueReport
    {
        return IssueReport::create([
            'tracking_code' => 'BR-STREET-'.strtoupper(bin2hex(random_bytes(3))),
            'user_id' => ($by ?? $this->reporter)->user_id,
            'category_id' => Category::firstOrCreate(['name' => $category], ['severity_weight' => $severity])->category_id,
            'description' => 'Private description written by the reporter.',
            'location_id' => $this->lawaan->location_id,
            'status' => 'Submitted',
        ]);
    }

    private function move(IssueReport $report, string $status, string $team = 'Maintenance Crew'): void
    {
        $this->actingAs($this->admin)->postJson('/api/reports/update', [
            'id' => $report->report_id, 'status' => $status, 'assignee' => $team, 'dispatchNote' => "Now {$status}.",
        ])->assertOk();
    }

    public function test_neighbours_on_the_same_street_hear_when_a_problem_is_dispatched_and_fixed(): void
    {
        $report = $this->report('Clogged Drainage');
        $this->move($report, 'Validated', 'Unassigned');
        $this->move($report, 'Dispatched');
        $this->move($report, 'Resolved');

        $street = Notification::query()->where('user_id', $this->neighbour->user_id)->where('kind', 'street')->orderBy('notif_id')->pluck('message')->all();
        $this->assertSame([
            'A team is working on a Clogged Drainage issue on Lawaan Street.',
            'A Clogged Drainage issue on Lawaan Street has been fixed.',
        ], $street, 'Only dispatched and resolved, not validated.');

        // The reporter gets their own updates, not street copies; other streets get nothing.
        $this->assertSame(0, Notification::query()->where('user_id', $this->reporter->user_id)->where('kind', 'street')->count());
        $this->assertSame(3, Notification::query()->where('user_id', $this->reporter->user_id)->where('kind', 'own')->count());
        $this->assertSame(0, Notification::query()->where('user_id', $this->otherStreet->user_id)->count());

        // Street updates are never emailed.
        Mail::assertNotSent(ReportStatusMail::class, fn (ReportStatusMail $mail) => $mail->hasTo($this->neighbour->email));
    }

    public function test_messages_read_naturally_for_every_category(): void
    {
        $this->assertSame('a Clogged Drainage issue', StreetUpdateService::issuePhrase('Clogged Drainage'));
        $this->assertSame('an Electrical issue', StreetUpdateService::issuePhrase('Electrical'));
        $this->assertSame('a community concern', StreetUpdateService::issuePhrase('Others'));

        $report = $this->report('Others', 1);
        $this->move($report, 'Validated', 'Unassigned');
        $this->move($report, 'Dispatched');
        $this->move($report, 'Resolved');

        $this->assertSame([
            'A team is working on a community concern on Lawaan Street.',
            'A community concern on Lawaan Street has been fixed.',
        ], Notification::query()->where('user_id', $this->neighbour->user_id)->where('kind', 'street')->orderBy('notif_id')->pluck('message')->all());
    }

    public function test_sensitive_categories_and_demo_reports_are_not_shared(): void
    {
        $safety = $this->report('Public Safety', 5);
        $this->move($safety, 'Validated', 'Unassigned');
        $this->move($safety, 'Dispatched');

        $demoResident = User::factory()->create(['email' => 'demo.person@example.com', 'address_location_id' => $this->lawaan->location_id]);
        $demo = $this->report('Clogged Drainage', 3, $demoResident);
        $this->move($demo, 'Validated', 'Unassigned');
        $this->move($demo, 'Dispatched');

        $this->assertSame(0, Notification::query()->where('kind', 'street')->count());
    }

    public function test_updates_page_tabs_keep_reports_and_street_separate_without_revealing_the_report(): void
    {
        $report = $this->report('Clogged Drainage');
        $this->move($report, 'Validated', 'Unassigned');
        $this->move($report, 'Dispatched');

        $street = $this->actingAs($this->neighbour)->getJson('/api/notifications?tab=street')->assertOk();
        $street->assertJsonPath('unreadByTab.street', 1)
            ->assertJsonPath('unreadByTab.mine', 0)
            ->assertJsonPath('street', 'Lawaan Street, Phase 2')
            ->assertJsonPath('notifications.0.kind', 'street')
            ->assertJsonPath('notifications.0.tracking', 'Lawaan Street, Phase 2');
        $this->assertStringNotContainsString($report->tracking_code, $street->getContent());
        $this->assertStringNotContainsString('Private description', $street->getContent());

        $this->actingAs($this->neighbour)->getJson('/api/notifications?tab=mine')->assertOk()->assertJsonCount(0, 'notifications');
        $this->actingAs($this->reporter)->getJson('/api/notifications?tab=mine')->assertOk()
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('notifications.0.tracking', $report->tracking_code);
        $this->actingAs($this->neighbour)->getJson('/api/notifications?tab=elsewhere')->assertStatus(422);
    }
}
