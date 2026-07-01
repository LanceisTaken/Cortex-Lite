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

    public function test_missing_csrf_token_is_rejected_on_web_login_route(): void
    {
        // A non-JSON request to a stateful api route without CSRF must 419.
        //
        // $this->withMiddleware(ValidateCsrfToken::class) does NOT achieve this on its
        // own: Sanctum's EnsureFrontendRequestsAreStateful middleware runs CSRF
        // verification through its own manually-built Pipeline (see
        // Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::frontendMiddleware()),
        // which bypasses the test harness's "middleware.disable" flag entirely — so
        // withMiddleware()/withoutMiddleware() have no effect on it either way.
        // The actual reason feature tests "bypass CSRF by default" is baked into
        // Illuminate\Foundation\Http\Middleware\PreventRequestForgery::handle(): it
        // short-circuits verification whenever runningUnitTests() is true, which is
        // the case for the whole phpunit run (APP_ENV=testing). Flip the app env for
        // this one request so real token verification actually executes.
        //
        // This mutation is safe to leave set for the rest of the run: Laravel
        // recreates $this->app from scratch per test method via
        // tearDownTheTestEnvironment(), so it can't leak into other tests.
        $this->app['env'] = 'production';

        $response = $this->post('/api/login', [
            'email' => 'x@example.com',
            'password' => 'x',
        ]);

        $response->assertStatus(419);
    }
}
