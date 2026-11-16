<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

class AdminRolesTest extends TestCase
{
    private User $super;

    private User $staff;

    private User $viewer;

    private IssueReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::factory()->admin('super')->create();
        $this->staff = User::factory()->admin('staff')->create();
        $this->viewer = User::factory()->admin('viewer')->create();
        $resident = User::factory()->create();
        $category = Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        $location = Location::create(['name' => 'Main Street', 'area_name' => 'Barangay One']);
        $this->report = IssueReport::create([
            'tracking_code' => 'BR-ROLE-1',
            'user_id' => $resident->user_id,
            'category_id' => $category->category_id,
            'description' => 'A large pothole near the corner.',
            'location_id' => $location->location_id,
            'status' => 'Submitted',
        ]);
    }

    public function test_viewer_can_look_but_not_change_anything(): void
    {
        $this->actingAs($this->viewer)->getJson('/api/session')
            ->assertJsonPath('user.adminLevel', 'viewer')
            ->assertJsonPath('user.adminLevelLabel', 'Viewer');
        foreach (['/api/reports', '/api/analytics/summary', '/api/analytics/map', '/api/analytics/dss', '/api/residents', '/api/reports/archived'] as $url) {
            $this->actingAs($this->viewer)->getJson($url)->assertOk();
        }
        $this->actingAs($this->viewer)->get('/admin')->assertOk();
        $this->actingAs($this->viewer)->get('/admin/summary')->assertOk();

        $this->actingAs($this->viewer)->postJson('/api/reports/update', $this->validation())->assertForbidden();
        $this->actingAs($this->viewer)->postJson("/api/reports/{$this->report->report_id}/archive")->assertForbidden();
        $this->actingAs($this->viewer)->postJson('/api/analytics/dss/snapshots')->assertForbidden();
        $this->actingAs($this->viewer)->postJson('/api/residents/1/approval', ['status' => 'approved'])->assertForbidden();
        $this->actingAs($this->viewer)->getJson('/api/admins')->assertForbidden();
        $this->assertSame('Submitted', $this->report->fresh()->status);
    }

    public function test_viewers_do_not_see_resident_contact_details(): void
    {
        $resident = User::factory()->create(['email' => 'juana@example.test', 'contact_number' => '09171234567']);

        $forViewer = collect($this->actingAs($this->viewer)->getJson('/api/residents')->assertOk()->json('residents'))
            ->firstWhere('id', (string) $resident->user_id);
        $this->assertSame('j***@example.test', $forViewer['email']);
        $this->assertNull($forViewer['mobile']);
        $this->assertSame($resident->full_name, $forViewer['fullName']);

        $staff = User::factory()->admin('staff')->create();
        $forStaff = collect($this->actingAs($staff)->getJson('/api/residents')->assertOk()->json('residents'))
            ->firstWhere('id', (string) $resident->user_id);
        $this->assertSame('juana@example.test', $forStaff['email']);
        $this->assertSame('09171234567', $forStaff['mobile']);
    }

    public function test_staff_handle_reports_but_not_settings_or_deletions(): void
    {
        $this->actingAs($this->staff)->postJson('/api/reports/update', $this->validation())->assertOk();
        $this->assertSame('Validated', $this->report->fresh()->status);

        $this->actingAs($this->staff)->postJson('/api/reference-data/categories', ['name' => 'New'])->assertForbidden();
        $this->actingAs($this->staff)->postJson('/api/reports/clear', ['confirmed' => true])->assertForbidden();
        $this->actingAs($this->staff)->deleteJson('/api/analytics/dss/snapshots', ['confirmed' => true])->assertForbidden();
        $this->actingAs($this->staff)->deleteJson('/api/residents/1')->assertForbidden();
        $this->actingAs($this->staff)->getJson('/api/admins')->assertForbidden();
    }

    public function test_super_admin_adds_and_manages_administrators(): void
    {
        $this->actingAs($this->super)->getJson('/api/admins')->assertOk()->assertJsonCount(3, 'admins');

        $this->actingAs($this->super)->postJson('/api/admins', $this->newAdmin(['password_confirmation' => 'Different-pass1!']))
            ->assertStatus(422)->assertJsonPath('message', 'The two passwords do not match.');
        $this->actingAs($this->super)->postJson('/api/admins', $this->newAdmin(['username' => $this->staff->username]))
            ->assertStatus(422)->assertJsonPath('message', 'That username is already taken.');

        $id = $this->actingAs($this->super)->postJson('/api/admins', $this->newAdmin())->assertCreated()->json('id');
        $captain = User::find($id);
        $this->assertSame('admin', $captain->role);
        $this->assertSame('viewer', $captain->admin_level);
        $this->assertSame('approved', $captain->account_status);

        $this->actingAs($this->super)->putJson("/api/admins/{$id}", ['level' => 'staff'])->assertOk();
        $this->assertSame('staff', $captain->fresh()->admin_level);
        $this->actingAs($this->super)->putJson("/api/admins/{$id}", ['active' => false])->assertOk();
        $this->assertSame('suspended', $captain->fresh()->account_status);
        // A disabled administrator's session stops working.
        $this->actingAs($captain->fresh())->getJson('/api/session')->assertUnauthorized();

        $this->assertSame(3, AdminAuditLog::whereIn('action', ['create_admin', 'update_admin'])->count());
    }

    public function test_nobody_changes_their_own_account_so_a_super_admin_always_remains(): void
    {
        // A super admin cannot demote or disable themselves, so the one making changes is
        // always an active super admin and the last one can never be removed.
        $this->actingAs($this->super)->putJson("/api/admins/{$this->super->user_id}", ['level' => 'staff'])
            ->assertStatus(409)->assertJsonPath('message', 'You cannot change your own administrator account. Ask another super administrator.');
        $this->actingAs($this->super)->putJson("/api/admins/{$this->super->user_id}", ['active' => false])->assertStatus(409);

        $second = User::factory()->admin('super')->create();
        $this->actingAs($this->super)->putJson("/api/admins/{$second->user_id}", ['level' => 'staff'])->assertOk();
        $this->assertSame('staff', $second->fresh()->admin_level);
        $this->assertSame('super', $this->super->fresh()->admin_level);
    }

    public function test_residents_cannot_use_admin_account_routes(): void
    {
        $resident = User::factory()->create();
        $this->actingAs($resident)->getJson('/api/admins')->assertForbidden();
        $this->actingAs($resident)->postJson('/api/admins', $this->newAdmin())->assertForbidden();
    }

    private function validation(): array
    {
        return ['id' => $this->report->report_id, 'status' => 'Validated', 'assignee' => 'Unassigned', 'dispatchNote' => 'Checked.'];
    }

    private function newAdmin(array $overrides = []): array
    {
        return $overrides + [
            'fullName' => 'Barangay Captain',
            'username' => 'captain',
            'email' => 'captain@example.test',
            'level' => 'viewer',
            'password' => 'Captain-pass123!',
            'password_confirmation' => 'Captain-pass123!',
        ];
    }
}
