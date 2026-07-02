<?php

namespace Tests\Feature\Steam;

use App\Models\User;
use App\Services\SteamOpenIdVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class OpenIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.frontend_url', 'http://localhost:5173');
    }

    public function test_guest_login_route_returns_401(): void
    {
        $this->get('/api/steam/login')->assertStatus(401);
    }

    public function test_authenticated_login_route_redirects_to_steam_openid(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/api/steam/login');

        $response->assertRedirect();

        $location = $response->headers->get('Location');

        $this->assertStringStartsWith(SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT, $location);
        $this->assertStringContainsString(urlencode(route('steam.callback', absolute: true)), $location);
        $this->assertStringContainsString('openid.realm='.urlencode(rtrim(config('services.steam.openid_realm'), '/')), $location);
    }

    public function test_authenticated_login_route_is_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->get('/api/steam/login')->assertRedirect();
        }

        $response = $this->actingAs($user)->get('/api/steam/login');

        $response->assertStatus(429);
        $this->assertIsNumeric($response->headers->get('Retry-After'));
    }

    public function test_successful_callback_persists_steam_id_and_redirects_to_dashboard(): void
    {
        $user = User::factory()->create();

        Http::fake([
            SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT => Http::response("is_valid:true\n"),
        ]);

        $response = $this->actingAs($user)->get('/steam/callback?'.http_build_query($this->validPayload()));

        $response->assertRedirect('http://localhost:5173/dashboard?steam_connected=1');
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'steam_id' => '76561198000000000',
        ]);
    }

    public function test_failed_callback_redirects_with_error_and_does_not_persist(): void
    {
        $user = User::factory()->create();

        $verifier = Mockery::mock(SteamOpenIdVerifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(null);
        $this->app->instance(SteamOpenIdVerifier::class, $verifier);

        $response = $this->actingAs($user)->get('/steam/callback');

        $response->assertRedirect('http://localhost:5173/dashboard?steam_error=steam_openid_verification_failed');
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'steam_id' => null,
        ]);
    }

    public function test_callback_rejects_steam_id_already_claimed_by_another_user(): void
    {
        $user = User::factory()->create();
        User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        Http::fake([
            SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT => Http::response("is_valid:true\n"),
        ]);

        $response = $this->actingAs($user)->get('/steam/callback?'.http_build_query($this->validPayload()));

        $response->assertRedirect('http://localhost:5173/dashboard?steam_error=steam_id_already_linked');
    }

    public function test_guest_callback_route_returns_redirect_to_login(): void
    {
        $this->get('/steam/callback')->assertRedirect('/api/login');
    }

    private function validPayload(): array
    {
        return [
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'id_res',
            'openid.op_endpoint' => SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT,
            'openid.return_to' => route('steam.callback', absolute: true),
            'openid.claimed_id' => 'https://steamcommunity.com/openid/id/76561198000000000',
            'openid.identity' => 'https://steamcommunity.com/openid/id/76561198000000000',
        ];
    }
}
