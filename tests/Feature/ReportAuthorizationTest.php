<?php

namespace Tests\Feature;

use App\Mail\AccountUpdateMail;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReportAuthorizationTest extends TestCase
{
    public function test_unauthenticated_requests_are_rejected_from_protected_endpoints(): void
    {
        $this->getJson('/api/session')->assertUnauthorized();
        $this->getJson('/api/reports')->assertUnauthorized();
        $this->getJson('/api/reports/archived')->assertUnauthorized();
        $this->postJson('/api/reports/1/archive')->assertUnauthorized();
        $this->postJson('/api/reports/1/restore')->assertUnauthorized();
        $this->postJson('/api/reports')->assertUnauthorized();
        $this->postJson('/api/reports/update')->assertUnauthorized();
        $this->postJson('/api/reports/clear')->assertUnauthorized();
        $this->deleteJson('/api/reports/1/archived')->assertUnauthorized();
        $this->getJson('/api/residents')->assertUnauthorized();
        $this->getJson('/api/attachments/1')->assertUnauthorized();
    }

    public function test_resident_can_submit_a_report_without_assigning_owner_or_admin_fields(): void
    {
        $resident = User::factory()->create();
        $administrator = User::factory()->create(['role' => 'admin']);
        $category = Category::create([
            'name' => 'Road Damage',
            'description' => 'Damage to a public road.',
            'severity_weight' => 3,
        ]);

        $response = $this->actingAs($resident)->postJson('/api/reports', [
            'category' => $category->name,
            'purok' => 'Main Street, Barangay One',
            'description' => 'A large pothole is creating a hazard for residents.',
            'user_id' => $administrator->user_id,
            'role' => 'admin',
            'status' => 'Resolved',
            'team_id' => 42,
            'date_resolved' => now()->toDateTimeString(),
        ]);

        $response->assertCreated();
        $this->assertMatchesRegularExpression('/^BR-\d{6}-\d{6}-[A-F0-9]{6}$/', $response->json('tracking'));
        $report = IssueReport::findOrFail($response->json('id'));
        $this->assertSame($resident->user_id, $report->user_id);
        $this->assertSame('Submitted', $report->status);
        $this->assertNull($report->team_id);
        $this->assertNull($report->date_resolved);
        $this->assertSame('resident', $resident->fresh()->role);
    }

    public function test_report_description_must_be_between_one_and_fifty_characters(): void
    {
        $resident = User::factory()->create();
        $category = Category::create([
            'name' => 'Road Damage',
            'description' => 'Damage to a public road.',
            'severity_weight' => 3,
        ]);
        $payload = [
            'category' => $category->name,
            'purok' => 'Main Street, Barangay One',
        ];

        foreach (['x', str_repeat('x', 50)] as $description) {
            $this->actingAs($resident)
                ->postJson('/api/reports', [...$payload, 'description' => $description])
                ->assertCreated();
        }

        foreach (['', str_repeat('x', 51)] as $description) {
            $this->actingAs($resident)
                ->postJson('/api/reports', [...$payload, 'description' => $description])
                ->assertUnprocessable();
        }
    }

    public function test_resident_can_list_only_their_own_reports(): void
    {
        $resident = User::factory()->create();
        $otherResident = User::factory()->create();
        $ownReport = $this->createReport($resident);
        $this->createReport($otherResident);

        $response = $this->actingAs($resident)->getJson('/api/reports');

        $response->assertOk()
            ->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.id', (string) $ownReport->report_id)
            ->assertJsonPath('reports.0.residentId', null);
    }

    public function test_administrator_can_list_reports_from_all_residents(): void
    {
        $administrator = User::factory()->create(['role' => 'admin']);
        $firstResident = User::factory()->create();
        $secondResident = User::factory()->create();
        $firstReport = $this->createReport($firstResident);
        $secondReport = $this->createReport($secondResident);

        $response = $this->actingAs($administrator)->getJson('/api/reports');

        $response->assertOk()
            ->assertJsonCount(2, 'reports')
            ->assertJsonFragment(['id' => (string) $firstReport->report_id])
            ->assertJsonFragment(['id' => (string) $secondReport->report_id]);
    }

    public function test_resident_cannot_access_another_residents_attachment_by_id(): void
    {
        $resident = User::factory()->create();
        $otherResident = User::factory()->create();
        $report = $this->createReport($otherResident);
        $attachment = Attachment::create([
            'report_id' => $report->report_id,
            'mime_type' => 'image/png',
            'file_data' => 'test-image',
        ]);

        $this->actingAs($resident)
            ->getJson('/api/attachments/'.$attachment->attachment_id)
            ->assertNotFound();
    }

    public function test_resident_cannot_update_reports_or_administrative_fields(): void
    {
        $resident = User::factory()->create();
        $otherResident = User::factory()->create();
        $report = $this->createReport($otherResident);

        $this->actingAs($resident)
            ->postJson('/api/reports/update', [
                'id' => $report->report_id,
                'status' => 'Resolved',
                'assignee' => 'Unassigned',
                'dispatchNote' => 'Unauthorized update.',
                'user_id' => $resident->user_id,
                'role' => 'admin',
                'team_id' => 42,
            ])
            ->assertForbidden();

        $this->assertSame('Submitted', $report->fresh()->status);
        $this->assertNull($report->fresh()->team_id);
        $this->assertDatabaseMissing('STATUS_LOG', ['report_id' => $report->report_id]);
    }

    public function test_resident_cannot_access_administrative_management_endpoints(): void
    {
        $resident = User::factory()->create();
        $this->actingAs($resident)->getJson('/api/residents')->assertForbidden();
        $this->actingAs($resident)->postJson('/api/reports/clear')->assertForbidden();
    }

    public function test_administrator_can_update_a_report_and_create_a_status_log(): void
    {
        $administrator = User::factory()->create(['role' => 'admin']);
        $resident = User::factory()->create();
        $report = $this->createReport($resident);

        $this->actingAs($administrator)
            ->postJson('/api/reports/update', [
                'id' => $report->report_id,
                'status' => 'Dispatched',
                'assignee' => 'Unassigned',
                'dispatchNote' => 'A response team has been notified.',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('Dispatched', $report->fresh()->status);
        $this->assertDatabaseHas('STATUS_LOG', [
            'report_id' => $report->report_id,
            'changed_by' => $administrator->user_id,
            'new_status' => 'Dispatched',
        ]);

        $this->actingAs($administrator)
            ->postJson('/api/reports/update', [
                'id' => $report->report_id,
                'status' => 'Resolved',
                'assignee' => 'Unassigned',
                'dispatchNote' => 'The issue has been resolved.',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('Resolved', $report->fresh()->status);
        $this->assertTrue(
            DB::table('ISSUE_REPORT')
                ->where('report_id', $report->report_id)
                ->whereRaw('date_resolved >= date_submitted')
                ->exists(),
        );
    }

    public function test_clearing_reports_archives_unresolved_reports_and_hides_them_from_residents(): void
    {
        $administrator = User::factory()->create(['role' => 'admin']);
        $resident = User::factory()->create();
        $activeReport = $this->createReport($resident);
        $rejectedReport = $this->createReport($resident);
        $resolvedReport = $this->createReport($resident);
        $attachment = Attachment::create([
            'report_id' => $activeReport->report_id,
            'mime_type' => 'image/png',
            'file_data' => 'test-image',
        ]);
        $rejectedReport->update(['status' => 'Rejected']);
        $resolvedReport->update(['status' => 'Resolved', 'date_resolved' => now()]);

        $this->actingAs($administrator)
            ->postJson('/api/reports/clear')
            ->assertOk()
            ->assertJsonPath('archived', 2);

        $this->assertNotNull($activeReport->fresh()->archived_at);
        $this->assertSame($administrator->user_id, $activeReport->fresh()->archived_by);
        $this->assertNotNull($rejectedReport->fresh()->archived_at);
        $this->assertNull($resolvedReport->fresh()->archived_at);

        $this->actingAs($resident)
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.id', (string) $resolvedReport->report_id);

        $this->getJson('/api/reports/archived')->assertForbidden();
        $this->getJson('/api/attachments/'.$attachment->attachment_id)->assertNotFound();
    }

    public function test_administrator_can_archive_and_restore_one_selected_report(): void
    {
        $administrator = User::factory()->create(['role' => 'admin']);
        $resident = User::factory()->create();
        $selectedReport = $this->createReport($resident);
        $otherReport = $this->createReport($resident);
        $resolvedReport = $this->createReport($resident);
        $resolvedReport->update(['status' => 'Resolved', 'date_resolved' => now()]);

        $this->actingAs($administrator)
            ->postJson('/api/reports/'.$selectedReport->report_id.'/archive')
            ->assertOk();

        $this->assertNotNull($selectedReport->fresh()->archived_at);
        $this->assertSame($administrator->user_id, $selectedReport->fresh()->archived_by);
        $this->assertNull($otherReport->fresh()->archived_at);

        $this->postJson('/api/reports/'.$resolvedReport->report_id.'/archive')
            ->assertStatus(409);

        $this->getJson('/api/reports/archived')
            ->assertOk()
            ->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.id', (string) $selectedReport->report_id);

        $this->postJson('/api/reports/'.$selectedReport->report_id.'/restore')
            ->assertOk();

        $this->assertNull($selectedReport->fresh()->archived_at);
        $this->assertNull($selectedReport->fresh()->archived_by);
        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(3, 'reports');
    }

    public function test_resident_cannot_archive_or_restore_reports(): void
    {
        $resident = User::factory()->create();
        $report = $this->createReport($resident);
        $report->update(['archived_at' => now()]);

        $this->actingAs($resident)
            ->postJson('/api/reports/'.$report->report_id.'/archive')
            ->assertForbidden();

        $this->postJson('/api/reports/'.$report->report_id.'/restore')
            ->assertForbidden();

        $this->assertNotNull($report->fresh()->archived_at);
    }

    public function test_administrator_can_review_and_permanently_delete_only_archived_reports(): void
    {
        $administrator = User::factory()->create(['role' => 'admin']);
        $resident = User::factory()->create();
        $archivedReport = $this->createReport($resident);
        $activeReport = $this->createReport($resident);
        $attachment = Attachment::create([
            'report_id' => $archivedReport->report_id,
            'mime_type' => 'image/png',
            'file_data' => 'test-image',
        ]);
        $archivedReport->update(['archived_at' => now(), 'archived_by' => $administrator->user_id]);

        $this->actingAs($administrator)
            ->getJson('/api/reports/archived')
            ->assertOk()
            ->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.id', (string) $archivedReport->report_id);

        $this->deleteJson('/api/reports/'.$activeReport->report_id.'/archived')->assertNotFound();

        $this->actingAs($resident)
            ->deleteJson('/api/reports/'.$archivedReport->report_id.'/archived')
            ->assertForbidden();

        $this->actingAs($administrator)
            ->deleteJson('/api/reports/'.$archivedReport->report_id.'/archived')
            ->assertOk();

        $this->assertDatabaseMissing('ISSUE_REPORT', ['report_id' => $archivedReport->report_id]);
        $this->assertDatabaseMissing('ATTACHMENT', ['attachment_id' => $attachment->attachment_id]);
    }

    public function test_registration_ignores_client_supplied_role_and_hashes_password(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.resend.com',
            'mail.mailers.smtp.username' => 'resend',
            'mail.mailers.smtp.password' => 'test-api-key',
            'mail.from.address' => 'notices@barangay.example.test',
        ]);

        $response = $this->postJson('/api/register', [
            'fullName' => 'New Resident',
            'username' => 'new-resident',
            'email' => 'new-resident@example.test',
            'password' => 'a-secure-test-password',
            'role' => 'admin',
            'user_id' => 1,
        ]);

        $response->assertAccepted()
            ->assertJsonPath('pendingApproval', true)
            ->assertJsonPath('emailStatus', 'sent');
        Mail::assertSent(AccountUpdateMail::class, fn (AccountUpdateMail $mail): bool => $mail->hasTo('new-resident@example.test'));

        $user = User::where('username', 'new-resident')->firstOrFail();
        $this->assertSame('resident', $user->role);
        $this->assertSame('pending', $user->account_status);
        $this->assertTrue(Hash::check('a-secure-test-password', $user->password_hash));
        $this->getJson('/api/session')->assertUnauthorized();
        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'a-secure-test-password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Your account is awaiting administrator approval.');
    }

    public function test_duplicate_registration_sends_the_reason_to_a_valid_submitted_email(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.resend.com',
            'mail.mailers.smtp.username' => 'resend',
            'mail.mailers.smtp.password' => 'test-api-key',
            'mail.from.address' => 'notices@barangay.example.test',
        ]);
        User::factory()->create([
            'username' => 'existing-resident',
            'email' => 'existing-resident@example.test',
        ]);

        $this->postJson('/api/register', [
            'fullName' => 'New Resident',
            'username' => 'new-resident',
            'email' => 'existing-resident@example.test',
            'password' => 'a-secure-test-password',
        ])->assertConflict()
            ->assertJsonPath('emailStatus', 'sent');

        Mail::assertSent(
            AccountUpdateMail::class,
            fn (AccountUpdateMail $mail): bool => $mail->hasTo('existing-resident@example.test')
                && str_contains($mail->render(), 'already registered'),
        );
    }

    public function test_administrator_can_approve_a_resident_before_they_can_sign_in(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.resend.com',
            'mail.mailers.smtp.username' => 'resend',
            'mail.mailers.smtp.password' => 'test-api-key',
            'mail.from.address' => 'notices@barangay.example.test',
        ]);
        $administrator = User::factory()->create(['role' => 'admin']);
        $resident = User::factory()->create(['account_status' => 'pending']);

        $this->actingAs($administrator)
            ->postJson('/api/residents/'.$resident->user_id.'/approval', ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('accountStatus', 'approved')
            ->assertJsonPath('emailStatus', 'sent');
        Mail::assertSent(AccountUpdateMail::class, fn (AccountUpdateMail $mail): bool => $mail->hasTo($resident->email));

        $this->assertSame('approved', $resident->fresh()->account_status);
        $this->postJson('/api/login', [
            'username' => $resident->username,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.accountStatus', 'approved');
    }

    public function test_administrator_can_reject_pending_resident_and_rejection_blocks_login(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.resend.com',
            'mail.mailers.smtp.username' => 'resend',
            'mail.mailers.smtp.password' => 'test-api-key',
            'mail.from.address' => 'notices@barangay.example.test',
        ]);
        $administrator = User::factory()->create(['role' => 'admin']);
        $resident = User::factory()->create(['account_status' => 'pending']);

        $this->actingAs($administrator)
            ->postJson('/api/residents/'.$resident->user_id.'/approval', [
                'status' => 'rejected',
                'reason' => 'The applicant is not a resident of this barangay.',
            ])
            ->assertOk()
            ->assertJsonPath('accountStatus', 'rejected')
            ->assertJsonPath('emailStatus', 'sent');
        Mail::assertSent(
            AccountUpdateMail::class,
            fn (AccountUpdateMail $mail): bool => $mail->hasTo($resident->email)
                && str_contains($mail->render(), 'not a resident of this barangay'),
        );

        $this->assertSame('rejected', $resident->fresh()->account_status);
        $this->postJson('/api/login', [
            'username' => $resident->username,
            'password' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Your account was not approved. Contact the barangay office for assistance.');
    }

    public function test_resident_cannot_approve_accounts_and_only_pending_accounts_can_be_rejected(): void
    {
        $resident = User::factory()->create();
        $pendingResident = User::factory()->create(['account_status' => 'pending']);

        $this->actingAs($resident)
            ->postJson('/api/residents/'.$pendingResident->user_id.'/approval', ['status' => 'approved'])
            ->assertForbidden();

        $administrator = User::factory()->create(['role' => 'admin']);
        $this->actingAs($administrator)
            ->postJson('/api/residents/'.$resident->user_id.'/approval', [
                'status' => 'rejected',
                'reason' => 'This account is already approved.',
            ])
            ->assertConflict();

        $this->assertSame('approved', $resident->fresh()->account_status);
    }

    public function test_registration_is_rejected_when_the_system_has_9000_accounts(): void
    {
        $now = now()->toDateTimeString();
        $passwordHash = Hash::make('test-password');
        foreach (array_chunk(range(1, 9000), 500) as $batch) {
            DB::table('USERS')->insert(array_map(
                fn (int $number): array => [
                    'username' => 'resident-'.$number,
                    'full_name' => 'Test Resident '.$number,
                    'email' => 'resident-'.$number.'@example.test',
                    'password_hash' => $passwordHash,
                    'role' => 'resident',
                    'created_at' => $now,
                ],
                $batch,
            ));
        }

        $this->postJson('/api/register', [
            'fullName' => 'Another Resident',
            'username' => 'resident-9001',
            'email' => 'resident-9001@example.test',
            'password' => 'a-secure-test-password',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Registration is closed because the system has reached its 9,000-account limit.');

        $this->assertDatabaseCount('USERS', 9000);
    }

    public function test_invalid_credentials_and_logged_out_sessions_cannot_access_protected_routes(): void
    {
        $resident = User::factory()->create([
            'username' => 'session-resident',
            'password_hash' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/login', [
            'username' => $resident->username,
            'password' => 'incorrect-password',
        ])->assertUnauthorized();
        $this->getJson('/api/reports')->assertUnauthorized();

        $this->postJson('/api/login', [
            'username' => $resident->username,
            'password' => 'correct-password',
        ])->assertOk();
        $this->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('user.username', $resident->username);

        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/session')->assertUnauthorized();
        $this->getJson('/api/reports')->assertUnauthorized();
    }

    public function test_alternate_http_methods_and_unregistered_resource_routes_do_not_bypass_authorization(): void
    {
        $resident = User::factory()->create();
        $report = $this->createReport($resident);

        $this->actingAs($resident)
            ->putJson('/api/reports/update', [
                'id' => $report->report_id,
                'status' => 'Resolved',
            ])
            ->assertMethodNotAllowed();

        $this->getJson('/api/reports/'.$report->report_id)->assertNotFound();
        $this->assertSame('Submitted', $report->fresh()->status);
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
