<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\Notification;
use App\Models\User;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    public function test_resident_can_list_and_mark_only_their_own_notifications_as_read(): void
    {
        $resident = User::factory()->create();
        $otherResident = User::factory()->create();
        $ownReport = $this->createReport($resident);
        $otherReport = $this->createReport($otherResident);
        $ownNotification = Notification::create([
            'report_id' => $ownReport->report_id,
            'user_id' => $resident->user_id,
            'message' => 'Your report was validated.',
            'type' => 'status_update',
        ]);
        $otherNotification = Notification::create([
            'report_id' => $otherReport->report_id,
            'user_id' => $otherResident->user_id,
            'message' => 'Your report was dispatched.',
            'type' => 'assigned',
        ]);

        $this->actingAs($resident)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unreadCount', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', (string) $ownNotification->notif_id)
            ->assertJsonPath('notifications.0.tracking', $ownReport->tracking_code)
            ->assertJsonPath('notifications.0.isRead', false);

        $this->postJson('/api/notifications/'.$otherNotification->notif_id.'/read')
            ->assertNotFound();
        $this->postJson('/api/notifications/'.$ownNotification->notif_id.'/read')
            ->assertOk();

        $this->assertTrue((bool) $ownNotification->fresh()->is_read);
        $this->assertFalse((bool) $otherNotification->fresh()->is_read);
        $this->getJson('/api/notifications')->assertJsonPath('unreadCount', 0);
    }

    public function test_notifications_can_be_filtered_like_my_reports(): void
    {
        $resident = User::factory()->create();
        $open = $this->createReport($resident);
        $resolved = $this->createReport($resident);
        $resolved->forceFill(['status' => 'Resolved', 'date_resolved' => now()])->save();
        $archived = $this->createReport($resident);
        $archived->forceFill(['archived_at' => now()])->save();
        foreach ([[$open, 'Open update'], [$resolved, 'Resolved update'], [$archived, 'Archived update']] as [$report, $message]) {
            Notification::create(['report_id' => $report->report_id, 'user_id' => $resident->user_id, 'message' => $message, 'type' => 'status_update']);
        }
        $messages = fn (string $view): array => collect($this->actingAs($resident)
            ->getJson('/api/notifications?view='.$view)->assertOk()->json('notifications'))
            ->pluck('message')->sort()->values()->all();

        $this->assertSame(['Open update'], $messages('current'));
        $this->assertSame(['Archived update', 'Resolved update'], $messages('past'));
        $this->assertSame(['Archived update', 'Open update', 'Resolved update'], $messages('all'));
        $this->getJson('/api/notifications?view=unknown')->assertUnprocessable();
    }

    private function createReport(User $resident): IssueReport
    {
        $category = Category::firstOrCreate(
            ['name' => 'Road Damage'],
            ['description' => 'Damage to a public road.', 'severity_weight' => 3],
        );
        $location = Location::firstOrCreate([
            'name' => 'Main Street',
            'area_name' => 'Barangay One',
        ]);

        return IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $resident->user_id,
            'category_id' => $category->category_id,
            'description' => 'A large pothole is creating a hazard for residents.',
            'location_id' => $location->location_id,
            'status' => 'Submitted',
        ]);
    }
}
