<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RouteExposureTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('protectedRoutes')]
    public function test_protected_routes_reject_guests(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertStatus(401);
    }

    public static function protectedRoutes(): array
    {
        return [
            'me'      => ['GET',    '/api/me'],
            'logout'  => ['POST',   '/api/logout'],
            'account' => ['DELETE', '/api/account'],
            'verify-resend' => ['POST', '/api/email/verification-notification'],
        ];
    }
}
