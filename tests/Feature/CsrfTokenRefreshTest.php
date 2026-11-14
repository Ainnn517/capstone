<?php

namespace Tests\Feature;

use Tests\TestCase;

class CsrfTokenRefreshTest extends TestCase
{
    public function test_it_returns_the_current_session_token_without_caching(): void
    {
        $response = $this->getJson('/api/csrf-token')->assertOk();

        $this->assertSame(session()->token(), $response->json('token'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
