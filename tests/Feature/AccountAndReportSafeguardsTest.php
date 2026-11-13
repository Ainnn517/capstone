<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Mail\PasswordChangeCodeMail;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountAndReportSafeguardsTest extends TestCase
{
    private const FINGERPRINT = RequireAuthenticatedUser::PASSWORD_FINGERPRINT_KEY;

    // ---------------------------------------------------------------- sessions

    public function test_changing_password_keeps_this_session_and_ends_other_sessions(): void
    {
        $user = User::factory()->create(['password_hash' => Hash::make('Current-password1!')]);
        $oldFingerprint = $user->sessionPasswordFingerprint();

        Mail::fake();
        $this->actingAs($user)
            ->withSession([self::FINGERPRINT => $oldFingerprint])
            ->postJson('/api/password/change', [
                'currentPassword' => 'Current-password1!',
                'password' => 'Changed-password2!',
                'password_confirmation' => 'Changed-password2!',
            ])
            ->assertOk();
        $code = null;
        Mail::assertSent(PasswordChangeCodeMail::class, function (PasswordChangeCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });
        $this->postJson('/api/password/change/confirm', ['code' => $code])->assertOk();

        // The session that made the change stays signed in.
        $this->getJson('/api/session')->assertOk();

        // Another device still holding the old password's fingerprint is signed out.
        $this->flushSession();
        $this->actingAs($user->fresh())
            ->withSession([self::FINGERPRINT => $oldFingerprint])
            ->getJson('/api/session')
            ->assertUnauthorized();
    }

    public function test_resetting_password_ends_existing_sessions(): void
    {
        $user = User::factory()->create(['email' => 'reset-session@example.test']);
        $oldFingerprint = $user->sessionPasswordFingerprint();

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => Password::createToken($user),
            'password' => 'A-reset-password1!',
            'password_confirmation' => 'A-reset-password1!',
        ])->assertOk();

        $this->actingAs($user->fresh())
            ->withSession([self::FINGERPRINT => $oldFingerprint])
            ->getJson('/api/session')
            ->assertUnauthorized();
    }

    public function test_login_stores_the_password_fingerprint_in_the_session(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'password'])
            ->assertOk()
            ->assertSessionHas(self::FINGERPRINT, $user->sessionPasswordFingerprint());
    }

    // ----------------------------------------------------- permanent deletion

    public function test_permanently_deleting_a_report_removes_its_photo_files(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $deletedReport = $this->createReport($resident);
        $keptReport = $this->createReport($resident);
        $deletedPath = 'report-attachments/'.str_repeat('a', 64).'.jpg';
        $keptPath = 'report-attachments/'.str_repeat('b', 64).'.jpg';
        Storage::disk('local')->put($deletedPath, 'photo-to-delete');
        Storage::disk('local')->put($keptPath, 'photo-to-keep');
        Attachment::create(['report_id' => $deletedReport->report_id, 'mime_type' => 'image/jpeg', 'file_path' => $deletedPath]);
        Attachment::create(['report_id' => $keptReport->report_id, 'mime_type' => 'image/jpeg', 'file_path' => $keptPath]);
        $deletedReport->update(['archived_at' => now(), 'archived_by' => $administrator->user_id]);

        $this->actingAs($administrator)
            ->deleteJson('/api/reports/'.$deletedReport->report_id.'/archived')
            ->assertOk();

        Storage::disk('local')->assertMissing($deletedPath);
        Storage::disk('local')->assertExists($keptPath);
        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', ['action' => 'permanently_delete_archived_report']);
    }

    // ------------------------------------------------------------- suspension

    public function test_administrator_can_suspend_an_approved_resident_and_it_takes_effect_immediately(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();

        $this->actingAs($administrator)
            ->postJson('/api/residents/'.$resident->user_id.'/approval', [
                'status' => 'suspended',
                'reason' => 'Repeated false reports.',
            ])
            ->assertOk()
            ->assertJsonPath('accountStatus', 'suspended');

        $this->assertSame('suspended', $resident->fresh()->account_status);
        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', [
            'actor_user_id' => $administrator->user_id,
            'action' => 'suspend_resident',
        ]);

        // An existing session is rejected on its next request.
        $this->actingAs($resident->fresh())->getJson('/api/reports')->assertUnauthorized();

        // A new login is refused with a clear message.
        $this->flushSession();
        $this->postJson('/api/login', ['username' => $resident->username, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account has been suspended. Contact the barangay office for assistance.');
    }

    public function test_suspension_requires_a_reason_and_an_approved_account(): void
    {
        $administrator = User::factory()->admin()->create();
        $approved = User::factory()->create();
        $pending = User::factory()->pending()->create();

        $this->actingAs($administrator)
            ->postJson('/api/residents/'.$approved->user_id.'/approval', ['status' => 'suspended'])
            ->assertUnprocessable();
        $this->postJson('/api/residents/'.$pending->user_id.'/approval', [
            'status' => 'suspended',
            'reason' => 'Not yet approved.',
        ])->assertConflict();

        $this->assertSame('approved', $approved->fresh()->account_status);
        $this->assertSame('pending', $pending->fresh()->account_status);
    }

    public function test_suspended_resident_can_be_reactivated(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $resident->forceFill(['account_status' => 'suspended'])->save();

        $this->actingAs($administrator)
            ->postJson('/api/residents/'.$resident->user_id.'/approval', ['status' => 'approved'])
            ->assertOk();

        $this->assertSame('approved', $resident->fresh()->account_status);
        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', ['action' => 'reactivate_resident']);
        $this->actingAs($resident->fresh())->getJson('/api/reports')->assertOk();
    }

    public function test_resident_cannot_suspend_accounts(): void
    {
        $resident = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($resident)
            ->postJson('/api/residents/'.$other->user_id.'/approval', [
                'status' => 'suspended',
                'reason' => 'Attempted by a resident.',
            ])
            ->assertForbidden();

        $this->assertSame('approved', $other->fresh()->account_status);
    }

    // ------------------------------------------------------ deleting accounts

    public function test_administrator_can_delete_a_rejected_resident(): void
    {
        $administrator = User::factory()->admin()->create();
        $rejected = User::factory()->create();
        $rejected->forceFill(['account_status' => 'rejected'])->save();
        DB::table('PASSWORD_RESET_TOKENS')->insert([
            'email' => $rejected->email,
            'token' => Hash::make('token'),
            'created_at' => now(),
        ]);

        $this->actingAs($administrator)
            ->deleteJson('/api/residents/'.$rejected->user_id)
            ->assertOk();

        $this->assertDatabaseMissing('USERS', ['user_id' => $rejected->user_id]);
        $this->assertDatabaseMissing('PASSWORD_RESET_TOKENS', ['email' => $rejected->email]);
        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', [
            'actor_user_id' => $administrator->user_id,
            'action' => 'delete_rejected_resident',
        ]);
    }

    public function test_only_rejected_residents_can_be_deleted(): void
    {
        $administrator = User::factory()->admin()->create();
        $approved = User::factory()->create();
        $pending = User::factory()->pending()->create();
        $suspended = User::factory()->create();
        $suspended->forceFill(['account_status' => 'suspended'])->save();

        foreach ([$approved, $pending, $suspended] as $resident) {
            $this->actingAs($administrator)
                ->deleteJson('/api/residents/'.$resident->user_id)
                ->assertConflict();
            $this->assertDatabaseHas('USERS', ['user_id' => $resident->user_id]);
        }

        // Administrator accounts are never reachable through the residents endpoint.
        $this->deleteJson('/api/residents/'.$administrator->user_id)->assertNotFound();
    }

    public function test_rejected_resident_with_reports_is_not_deleted(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $this->createReport($resident);
        $resident->forceFill(['account_status' => 'rejected'])->save();

        $this->actingAs($administrator)
            ->deleteJson('/api/residents/'.$resident->user_id)
            ->assertConflict();

        $this->assertDatabaseHas('USERS', ['user_id' => $resident->user_id]);
    }

    public function test_residents_cannot_delete_accounts(): void
    {
        $resident = User::factory()->create();
        $rejected = User::factory()->create();
        $rejected->forceFill(['account_status' => 'rejected'])->save();

        $this->actingAs($resident)
            ->deleteJson('/api/residents/'.$rejected->user_id)
            ->assertForbidden();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/residents/'.$rejected->user_id)->assertUnauthorized();

        $this->assertDatabaseHas('USERS', ['user_id' => $rejected->user_id]);
    }

    // -------------------------------------------------------- report workflow

    public function test_rejected_reports_are_read_only(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $team = Team::create(['name' => 'Field Team A']);
        $report = $this->createReport($resident);
        $report->update(['status' => 'Rejected']);

        $this->actingAs($administrator)
            ->postJson('/api/reports/update', [
                'id' => $report->report_id,
                'status' => 'Rejected',
                'assignee' => $team->name,
                'dispatchNote' => 'Trying to reassign a rejected report.',
            ])
            ->assertStatus(409);

        $report->refresh();
        $this->assertSame('Rejected', $report->status);
        $this->assertNull($report->team_id);
        $this->assertDatabaseMissing('STATUS_LOG', ['report_id' => $report->report_id]);
        $this->assertDatabaseMissing('NOTIFICATION', ['report_id' => $report->report_id]);
    }

    public function test_residents_cannot_view_history_of_archived_reports(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $report = $this->createReport($resident);
        $report->update(['archived_at' => now(), 'archived_by' => $administrator->user_id]);

        $this->actingAs($resident)
            ->getJson('/api/reports/'.$report->report_id.'/history')
            ->assertNotFound();
        $this->actingAs($administrator)
            ->getJson('/api/reports/'.$report->report_id.'/history')
            ->assertOk();
    }

    private function createReport(User $resident): IssueReport
    {
        $category = Category::firstOrCreate(
            ['name' => 'Road Damage'],
            ['description' => 'Damage to a public road.', 'severity_weight' => 3],
        );
        $location = Location::firstOrCreate(['name' => 'Main Street', 'area_name' => 'Barangay One']);

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
