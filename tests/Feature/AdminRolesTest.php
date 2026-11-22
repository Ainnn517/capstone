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

    private IssueReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::factory()->admin('super')->create();
        $this->staff = User::factory()->admin('staff')->create();
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

    public function test_both_levels_see_the_whole_dashboard(): void
    {
        $this->actingAs($this->staff)->getJson('/api/session')
            ->assertJsonPath('user.adminLevel', 'staff')
            ->assertJsonPath('user.adminLevelLabel', 'Admin');
        $this->actingAs($this->super)->getJson('/api/session')
            ->assertJsonPath('user.adminLevelLabel', 'Super admin');
        foreach ([$this->staff, $this->super] as $admin) {
            foreach (['/api/reports', '/api/analytics/summary', '/api/analytics/map', '/api/analytics/dss', '/api/residents', '/api/reports/archived'] as $url) {
                $this->actingAs($admin)->getJson($url)->assertOk();
            }
            $this->actingAs($admin)->get('/admin')->assertOk();
            $this->actingAs($admin)->get('/admin/summary')->assertOk();
        }
    }

    public function test_both_levels_see_resident_contact_details(): void
    {
        $resident = User::factory()->create(['email' => 'juana@example.test', 'contact_number' => '09171234567']);

        foreach ([$this->staff, $this->super] as $admin) {
            $row = collect($this->actingAs($admin)->getJson('/api/residents')->assertOk()->json('residents'))
                ->firstWhere('id', (string) $resident->user_id);
            $this->assertSame('juana@example.test', $row['email']);
            $this->assertSame('09171234567', $row['mobile']);
        }
    }

    public function test_the_viewer_level_no_longer_exists(): void
    {
        $this->actingAs($this->super)->postJson('/api/admins', $this->newAdmin(['level' => 'viewer']))->assertStatus(422);
        $this->actingAs($this->super)->putJson("/api/admins/{$this->staff->user_id}", ['level' => 'viewer'])->assertStatus(422);
        $this->assertSame('staff', $this->staff->fresh()->admin_level);
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
        $this->actingAs($this->super)->getJson('/api/admins')->assertOk()->assertJsonCount(2, 'admins');

        $this->actingAs($this->super)->postJson('/api/admins', $this->newAdmin(['password_confirmation' => 'Different-pass1!']))
            ->assertStatus(422)->assertJsonPath('message', 'The two passwords do not match.');
        $this->actingAs($this->super)->postJson('/api/admins', $this->newAdmin(['username' => $this->staff->username]))
            ->assertStatus(422)->assertJsonPath('message', 'That username is already taken.');

        $id = $this->actingAs($this->super)->postJson('/api/admins', $this->newAdmin())->assertCreated()->json('id');
        $captain = User::find($id);
        $this->assertSame('admin', $captain->role);
        $this->assertSame('staff', $captain->admin_level);
        $this->assertSame('approved', $captain->account_status);

        $this->actingAs($this->super)->putJson("/api/admins/{$id}", ['level' => 'super'])->assertOk();
        $this->assertSame('super', $captain->fresh()->admin_level);
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
            'level' => 'staff',
            'password' => 'Captain-pass123!',
            'password_confirmation' => 'Captain-pass123!',
        ];
    }
}
