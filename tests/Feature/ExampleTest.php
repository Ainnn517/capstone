<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/frontend/pages/index.html');
    }

    public function test_the_privacy_notice_is_available_without_signing_in(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('href="/"', false)
            ->assertSee('Archived reports, with their attachments and history, are deleted automatically one year after archiving');
    }

    public function test_login_page_uses_a_relative_privacy_notice_link(): void
    {
        $this->get('/frontend/pages/index.html')
            ->assertOk()
            ->assertSee('href="/privacy"', false);
    }

    public function test_admin_has_a_separate_pending_approvals_workspace(): void
    {
        // The admin page is only served to a signed-in administrator.
        $response = $this->actingAs(User::factory()->admin()->create())->get('/frontend/pages/admin-dashboard.html');

        $response
            ->assertOk()
            ->assertSee('Pending Approvals')
            ->assertSee('data-admin-view="pendingApprovals"', false)
            ->assertSee('id="pendingApprovalsView"', false)
            ->assertSee('id="pendingApprovalList"', false)
            ->assertDontSee('Pending Resident Approvals');
    }
}
