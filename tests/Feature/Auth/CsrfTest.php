<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsrfTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_cookie_endpoint_sets_xsrf_cookie(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');
        $response->assertNoContent();
        $this->assertNotNull($response->getCookie('XSRF-TOKEN', false));
    }
}
