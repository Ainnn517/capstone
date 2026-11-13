<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataPurgeTest extends TestCase
{
    public function test_purge_removes_only_data_past_its_retention_period(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $resident = User::factory()->create();

        $oldRejected = User::factory()->create();
        $oldRejected->forceFill(['account_status' => 'rejected', 'created_at' => now()->subDays(31)])->save();
        $recentRejected = User::factory()->create();
        $recentRejected->forceFill(['account_status' => 'rejected', 'created_at' => now()->subDays(5)])->save();
        $oldRejectedWithReport = User::factory()->create();
        $oldRejectedWithReport->forceFill(['account_status' => 'rejected', 'created_at' => now()->subDays(40)])->save();
        $this->report($oldRejectedWithReport);

        $oldArchived = $this->report($resident, archivedDaysAgo: 400);
        $path = 'report-attachments/'.str_repeat('c', 64).'.jpg';
        Storage::disk('local')->put($path, 'photo');
        Attachment::create(['report_id' => $oldArchived->report_id, 'mime_type' => 'image/jpeg', 'file_path' => $path]);
        $recentArchived = $this->report($resident, archivedDaysAgo: 10);
        $active = $this->report($resident);

        AdminAuditLog::create(['actor_user_id' => $admin->user_id, 'action' => 'old', 'details' => []])
            ->forceFill(['created_at' => now()->subDays(800)])->save();
        AdminAuditLog::create(['actor_user_id' => $admin->user_id, 'action' => 'recent', 'details' => []]);
        DB::table('PASSWORD_RESET_TOKENS')->insert(['email' => $resident->email, 'token' => Hash::make('t'), 'created_at' => now()->subHour()]);

        $this->artisan('data:purge', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('USERS', ['user_id' => $oldRejected->user_id]);

        $this->artisan('data:purge')->assertSuccessful();

        $this->assertDatabaseMissing('USERS', ['user_id' => $oldRejected->user_id]);
        $this->assertDatabaseHas('USERS', ['user_id' => $recentRejected->user_id]);
        $this->assertDatabaseHas('USERS', ['user_id' => $oldRejectedWithReport->user_id]);
        $this->assertDatabaseMissing('ISSUE_REPORT', ['report_id' => $oldArchived->report_id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('ISSUE_REPORT', ['report_id' => $recentArchived->report_id]);
        $this->assertDatabaseHas('ISSUE_REPORT', ['report_id' => $active->report_id]);
        $this->assertDatabaseMissing('ADMIN_AUDIT_LOG', ['action' => 'old']);
        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', ['action' => 'recent']);
        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', ['action' => 'scheduled_data_purge']);
        $this->assertDatabaseMissing('PASSWORD_RESET_TOKENS', ['email' => $resident->email]);
    }

    public function test_purge_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('data:purge')->assertSuccessful();
    }

    private function report(User $resident, ?int $archivedDaysAgo = null): IssueReport
    {
        $category = Category::firstOrCreate(['name' => 'Road Damage'], ['severity_weight' => 3]);
        $location = Location::firstOrCreate(['name' => 'Main Street', 'area_name' => 'Barangay One']);

        return IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $resident->user_id,
            'category_id' => $category->category_id,
            'description' => 'Test report.',
            'location_id' => $location->location_id,
            'status' => 'Submitted',
            'archived_at' => $archivedDaysAgo === null ? null : now()->subDays($archivedDaysAgo),
        ]);
    }
}
