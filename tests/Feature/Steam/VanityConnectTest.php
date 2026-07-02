<?php

namespace Tests\Feature\Steam;

use App\Exceptions\SteamApiException;
use App\Models\User;
use App\Services\SteamClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VanityConnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_vanity_persists_resolved_steam_id(): void
    {
        $user = User::factory()->create();

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('resolveVanityUrl')
            ->once()
            ->with('test-handle')
            ->andReturn('76561198000000000');
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/connect-vanity', ['vanity' => 'test-handle'])
            ->assertOk()
            ->assertJsonPath('steam_id', '76561198000000000');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'steam_id' => '76561198000000000',
        ]);
    }

    public function test_connect_vanity_returns_422_when_unresolvable(): void
    {
        $user = User::factory()->create();

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('resolveVanityUrl')->once()->andReturn(null);
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/connect-vanity', ['vanity' => 'missing'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'steam_vanity_unresolved');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'steam_id' => null,
        ]);
    }

    public function test_connect_vanity_returns_409_when_steam_id_is_already_claimed(): void
    {
        $user = User::factory()->create();
        User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('resolveVanityUrl')->once()->andReturn('76561198000000000');
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/connect-vanity', ['vanity' => 'duplicate'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'steam_id_already_linked');
    }

    public function test_connect_vanity_validates_the_input_shape_before_hitting_steam(): void
    {
        $user = User::factory()->create();

        $client = Mockery::mock(SteamClient::class);
        $client->shouldNotReceive('resolveVanityUrl');
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/connect-vanity', ['vanity' => 'bad vanity !!!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vanity');
    }

    public function test_connect_vanity_returns_503_when_steam_is_unavailable(): void
    {
        $user = User::factory()->create();

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('resolveVanityUrl')
            ->once()
            ->andThrow(SteamApiException::serviceUnavailable());
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/connect-vanity', ['vanity' => 'test-handle'])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'steam_api_unavailable');
    }

    public function test_connect_vanity_route_is_throttled(): void
    {
        $user = User::factory()->create();

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('resolveVanityUrl')->times(6)->andReturn(null);
        $this->app->instance(SteamClient::class, $client);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->postJson('/api/steam/connect-vanity', ['vanity' => 'test-handle'])
                ->assertStatus(422);
        }

        $response = $this->actingAs($user)
            ->postJson('/api/steam/connect-vanity', ['vanity' => 'test-handle']);

        $response->assertStatus(429);
        $this->assertIsNumeric($response->headers->get('Retry-After'));
    }
}
