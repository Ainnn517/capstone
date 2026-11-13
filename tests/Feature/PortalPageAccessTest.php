<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class PortalPageAccessTest extends TestCase
{
    private const ADMIN_PAGE = '/frontend/pages/admin-dashboard.html';

    private const RESIDENT_PAGE = '/frontend/pages/resident-portal.html';

    private const LOGIN_PAGE = '/frontend/pages/index.html';

    public function test_signed_out_visitors_are_sent_to_the_login_page_without_seeing_the_portal(): void
    {
        foreach ([self::ADMIN_PAGE, self::RESIDENT_PAGE] as $page) {
            $response = $this->get($page)->assertRedirect(self::LOGIN_PAGE);
            $this->assertStringNotContainsString('admin-sidebar', $response->getContent());
        }
    }

    public function test_each_role_only_opens_its_own_portal(): void
    {
        $admin = User::factory()->admin()->create();
        $resident = User::factory()->create();

        $this->actingAs($admin)->get(self::ADMIN_PAGE)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($admin)->get(self::RESIDENT_PAGE)->assertRedirect(self::ADMIN_PAGE);

        $this->actingAs($resident)->get(self::RESIDENT_PAGE)->assertOk();
        $this->actingAs($resident)->get(self::ADMIN_PAGE)->assertRedirect(self::RESIDENT_PAGE);
    }

    public function test_pending_and_suspended_accounts_cannot_open_a_portal(): void
    {
        foreach (['pending', 'suspended', 'rejected'] as $status) {
            $user = User::factory()->create();
            $user->forceFill(['account_status' => $status])->save();

            $this->actingAs($user)->get(self::RESIDENT_PAGE)->assertRedirect(self::LOGIN_PAGE);
        }
    }
}
