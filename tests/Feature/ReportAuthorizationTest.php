<?php

namespace Tests\Feature;

use App\Http\Middleware\AddSecurityHeaders;
use App\Mail\AccountUpdateMail;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
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
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->postJson('/api/notifications/1/read')->assertUnauthorized();
        $this->getJson('/api/analytics/dss')->assertUnauthorized();
    }

    public function test_resident_can_submit_a_report_without_assigning_owner_or_admin_fields(): void
    {
        $resident = User::factory()->create();
        $administrator = User::factory()->admin()->create();
        $category = Category::create([
            'name' => 'Road Damage',
            'description' => 'Damage to a public road.',
            'severity_weight' => 3,
        ]);
        $location = Location::create([
            'name' => 'Laurel Street',
            'area_name' => 'St. Joseph Village 10 - Phase 1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($resident)->postJson('/api/reports', [
            'category' => $category->name,
            'location_id' => $location->location_id,
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

    public function test_report_description_must_be_between_one_and_five_hundred_characters(): void
    {
        $resident = User::factory()->create();
        $category = Category::create([
            'name' => 'Road Damage',
            'description' => 'Damage to a public road.',
            'severity_weight' => 3,
        ]);
        $location = Location::create([
            'name' => 'Laurel Street',
            'area_name' => 'St. Joseph Village 10 - Phase 1',
            'is_active' => true,
        ]);
        $payload = [
            'category' => $category->name,
            'location_id' => $location->location_id,
        ];

        foreach (['x', str_repeat('x', 500)] as $description) {
            $this->actingAs($resident)
                ->postJson('/api/reports', [...$payload, 'description' => $description])
                ->assertCreated();
        }

        foreach (['', str_repeat('x', 501)] as $description) {
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
        $administrator = User::factory()->admin()->create();
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

    public function test_report_list_is_paginated_and_has_no_attachment_blob_in_the_payload(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $firstReport = $this->createReport($resident);
        Attachment::create([
            'report_id' => $firstReport->report_id,
            'mime_type' => 'image/png',
            'file_data' => 'test-image',
        ]);
        for ($i = 0; $i < 50; $i++) {
            $this->createReport($resident);
        }

        $attachmentQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$attachmentQueries): void {
            if (str_contains(strtoupper($query->sql), 'ATTACHMENT')) {
                $attachmentQueries[] = $query->sql;
            }
        });
        $response = $this->actingAs($administrator)->getJson('/api/reports?view=active&page=2');

        $response->assertOk()
            ->assertJsonCount(1, 'reports')
            ->assertJsonPath('pagination.currentPage', 2)
            ->assertJsonPath('pagination.lastPage', 2)
            ->assertJsonPath('pagination.total', 51);
        $this->assertArrayNotHasKey('file_data', $response->json('reports.0'));
        $this->assertNotEmpty($attachmentQueries);
        $this->assertSame([], array_filter(
            $attachmentQueries,
            fn (string $query): bool => str_contains(strtolower($query), 'file_data'),
        ));
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
        $this->actingAs($resident)->postJson('/api/reports/clear', ['confirmed' => true])->assertForbidden();
        $this->actingAs($resident)->getJson('/api/analytics/dss')->assertForbidden();
        $this->actingAs($resident)->getJson('/api/analytics/summary')->assertForbidden();
    }

    public function test_administrator_can_update_a_report_and_create_a_status_log(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $report = $this->createReport($resident);

        $this->actingAs($administrator)
            ->postJson('/api/reports/update', [
                'id' => $report->report_id,
                'status' => 'Validated',
                'assignee' => 'Unassigned',
                'dispatchNote' => 'The report has been validated.',
            ])
            ->assertOk();

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
        $this->assertDatabaseHas('NOTIFICATION', [
            'report_id' => $report->report_id,
            'user_id' => $resident->user_id,
            'type' => 'status_update',
            'message' => 'A response team has been notified.',
        ]);
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

    public function test_report_update_rejects_invalid_status_and_empty_dispatch_note(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $report = $this->createReport($resident);

        foreach ([
            [
                'id' => $report->report_id,
                'status' => 'Unknown',
                'assignee' => 'Unassigned',
                'dispatchNote' => 'A valid note.',
            ],
            [
                'id' => $report->report_id,
                'status' => 'Validated',
                'assignee' => 'Unassigned',
                'dispatchNote' => '   ',
            ],
            [
                'id' => $report->report_id,
                'status' => 'Validated',
                'assignee' => str_repeat('x', 101),
                'dispatchNote' => 'A valid note.',
            ],
            [
                'id' => $report->report_id,
                'status' => 'Validated',
                'assignee' => 'Unknown Team',
                'dispatchNote' => 'A valid note.',
            ],
        ] as $payload) {
            $this->actingAs($administrator)
                ->postJson('/api/reports/update', $payload)
                ->assertUnprocessable();
        }
    }

    public function test_report_status_transitions_are_enforced(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $report = $this->createReport($resident);

        $this->actingAs($administrator)
            ->postJson('/api/reports/update', [
                'id' => $report->report_id,
                'status' => 'Resolved',
                'assignee' => 'Unassigned',
                'dispatchNote' => 'This skips required workflow stages.',
            ])
            ->assertUnprocessable();

        $this->assertSame('Submitted', $report->fresh()->status);
        $this->assertDatabaseMissing('STATUS_LOG', ['report_id' => $report->report_id]);
    }

    public function test_report_location_must_be_in_the_server_side_allowlist(): void
    {
        $resident = User::factory()->create();
        $category = Category::create([
            'name' => 'Road Damage',
            'description' => 'Damage to a public road.',
            'severity_weight' => 3,
        ]);

        $this->actingAs($resident)
            ->postJson('/api/reports', [
                'category' => $category->name,
                'location_id' => 99999,
                'description' => 'A report at an unlisted location.',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('LOCATION', [
            'name' => 'Attacker Created Street',
            'area_name' => 'Unknown Village',
        ]);
    }

    public function test_status_history_is_visible_to_the_report_owner_and_administrators_only(): void
    {
        $resident = User::factory()->create();
        $otherResident = User::factory()->create();
        $administrator = User::factory()->admin()->create();
        $report = $this->createReport($resident);
        DB::table('STATUS_LOG')->insert([
            'report_id' => $report->report_id,
            'changed_by' => $administrator->user_id,
            'old_status' => 'Submitted',
            'new_status' => 'Validated',
            'remarks' => 'The report was reviewed.',
            'changed_at' => now(),
        ]);

        $this->actingAs($resident)
            ->getJson('/api/reports/'.$report->report_id.'/history')
            ->assertOk()
            ->assertJsonPath('history.0.newStatus', 'Validated')
            ->assertJsonPath('history.0.remarks', 'The report was reviewed.');
        $this->actingAs($otherResident)
            ->getJson('/api/reports/'.$report->report_id.'/history')
            ->assertNotFound();
        $this->actingAs($administrator)
            ->getJson('/api/reports/'.$report->report_id.'/history')
            ->assertOk()
            ->assertJsonCount(1, 'history');
    }

    public function test_security_headers_are_added_to_responses(): void
    {
        $response = $this->get('/frontend/pages/index.html');

        $response
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy');

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('img-src \'self\' data: blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.googleusercontent.com;', $policy);
        $this->assertStringContainsString('connect-src \'self\' https://*.googleapis.com https://*.google.com https://*.gstatic.com data: blob:', $policy);
        $this->assertStringContainsString('worker-src blob:', $policy);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $policy);
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    // Send Strict-Transport-Security only for secure production requests.
    public function test_hsts_is_limited_to_secure_production_requests(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $middleware = new AddSecurityHeaders;

        $secureResponse = $middleware->handle(
            Request::create('https://localhost/frontend/pages/index.html'),
            static fn (Request $request) => response('OK'),
        );
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $secureResponse->headers->get('Strict-Transport-Security'),
        );

        $insecureResponse = $middleware->handle(
            Request::create('http://localhost/frontend/pages/index.html'),
            static fn (Request $request) => response('OK'),
        );
        $this->assertFalse($insecureResponse->headers->has('Strict-Transport-Security'));
    }

    public function test_clearing_reports_archives_unresolved_reports_and_hides_them_from_residents(): void
    {
        $administrator = User::factory()->admin()->create();
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
            ->assertUnprocessable();
        $this->assertNull($activeReport->fresh()->archived_at);

        $this->postJson('/api/reports/clear', ['confirmed' => true])
            ->assertOk()
            ->assertJsonPath('archived', 2);

        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', [
            'actor_user_id' => $administrator->user_id,
            'action' => 'bulk_archive_unresolved_reports',
        ]);
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
        $administrator = User::factory()->admin()->create();
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
        $administrator = User::factory()->admin()->create();
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
        Notification::fake();
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
            'password' => 'A-secure-test-password1!',
            'privacyConsent' => true,
            'role' => 'admin',
            'user_id' => 1,
        ]);

        $response->assertAccepted()
            ->assertJsonPath('pendingApproval', true)
            ->assertJsonPath('emailStatus', 'sent');

        $user = User::where('username', 'new-resident')->firstOrFail();
        $this->assertSame('resident', $user->role);
        $this->assertSame('pending', $user->account_status);
        $this->assertNotNull($user->privacy_consent_at);
        $this->assertSame(config('security.registration_consent_version'), $user->privacy_notice_version);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertTrue(Hash::check('A-secure-test-password1!', $user->password_hash));
        $this->getJson('/api/session')->assertUnauthorized();
        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'A-secure-test-password1!',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Your account is awaiting administrator approval.');
    }

    public function test_registration_requires_explicit_privacy_consent(): void
    {
        $this->postJson('/api/register', [
            'fullName' => 'Consent Resident',
            'username' => 'consent-resident',
            'email' => 'consent-resident@example.test',
            'password' => 'A-secure-test-password1!',
            'privacyConsent' => false,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('USERS', ['username' => 'consent-resident']);
    }

    public function test_user_can_verify_email_from_a_signed_link(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            ['id' => $user->user_id, 'hash' => sha1($user->email)],
        );

        $this->get($url)->assertRedirect('/frontend/pages/index.html?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_duplicate_registration_does_not_email_the_submitted_address_or_reveal_account_existence(): void
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

        $response = $this->postJson('/api/register', [
            'fullName' => 'New Resident',
            'username' => 'new-resident',
            'email' => 'existing-resident@example.test',
            'password' => 'A-secure-test-password1!',
            'privacyConsent' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Registration could not be completed with these details. Review them and try again.')
            ->assertJsonPath('emailStatus', 'not_sent');

        $this->postJson('/api/register', [
            'fullName' => '',
            'username' => 'invalid-resident',
            'email' => 'invalid-resident@example.test',
            'password' => 'A-secure-test-password1!',
            'privacyConsent' => true,
        ])->assertUnprocessable()
            ->assertExactJson($response->json());

        Mail::assertNothingOutgoing();
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
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->pending()->create();

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
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->pending()->create();

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
        $pendingResident = User::factory()->pending()->create();

        $this->actingAs($resident)
            ->postJson('/api/residents/'.$pendingResident->user_id.'/approval', ['status' => 'approved'])
            ->assertForbidden();

        $administrator = User::factory()->admin()->create();
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
            'password' => 'A-secure-test-password1!',
            'privacyConsent' => true,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Registration is closed because the system has reached its configured account limit.');

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
