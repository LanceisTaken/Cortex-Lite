<?php

namespace Tests\Feature\Steam;

use App\Exceptions\SteamApiException;
use App\Exceptions\SteamPrivateLibraryException;
use App\Models\Game;
use App\Models\User;
use App\Services\SteamClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_requires_connected_steam_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/steam/sync')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'steam_not_connected');
    }

    public function test_sync_returns_private_profile_error_when_profile_is_not_public(): void
    {
        $user = User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('getPlayerSummary')->once()->andReturn([
            'communityvisibilitystate' => 1,
        ]);
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/sync')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'steam_profile_private')
            ->assertJsonPath('help.profile_toggle', 'Set "My profile" to Public.')
            ->assertJsonPath('help.game_details_toggle', 'Set "Game details" to Public.');
    }

    public function test_sync_returns_same_private_profile_shape_when_game_details_are_private(): void
    {
        $user = User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('getPlayerSummary')->once()->andReturn([
            'communityvisibilitystate' => 3,
        ]);
        $client->shouldReceive('getOwnedGames')->once()->andThrow(new SteamPrivateLibraryException());
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/sync')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'steam_profile_private');
    }

    public function test_successful_sync_creates_steam_rows(): void
    {
        $user = User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('getPlayerSummary')->once()->andReturn([
            'communityvisibilitystate' => 3,
        ]);
        $client->shouldReceive('getOwnedGames')->once()->andReturn(collect([
            [
                'appid' => 620,
                'name' => 'Portal 2',
                'playtime_forever' => 120,
                'last_played_at' => '2026-07-02 04:00:00',
                'cover_url' => 'https://example.com/portal2.jpg',
            ],
            [
                'appid' => 730,
                'name' => 'Counter-Strike 2',
                'playtime_forever' => 300,
                'last_played_at' => null,
                'cover_url' => 'https://example.com/cs2.jpg',
            ],
            [
                'appid' => 440,
                'name' => 'Team Fortress 2',
                'playtime_forever' => 60,
                'last_played_at' => null,
                'cover_url' => 'https://example.com/tf2.jpg',
            ],
        ]));
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/sync')
            ->assertOk()
            ->assertJson([
                'imported' => 3,
                'updated' => 0,
            ]);

        $this->assertDatabaseHas('games', [
            'user_id' => $user->id,
            'steam_app_id' => 620,
            'source' => 'steam',
            'metadata_status' => 'pending',
            'cover_url' => 'https://example.com/portal2.jpg',
            'playtime_minutes' => 120,
            'last_played_at' => '2026-07-02 04:00:00',
        ]);
    }

    public function test_resync_updates_existing_steam_row_without_clobbering_user_fields(): void
    {
        $user = User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        Game::factory()->for($user)->create([
            'title' => 'Portal 2',
            'steam_app_id' => 620,
            'source' => 'steam',
            'status' => 'completed',
            'genre' => 'Puzzle',
            'platform' => 'Steam Deck',
            'playtime_minutes' => 20,
        ]);

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('getPlayerSummary')->once()->andReturn([
            'communityvisibilitystate' => 3,
        ]);
        $client->shouldReceive('getOwnedGames')->once()->andReturn(collect([
            [
                'appid' => 620,
                'name' => 'Portal 2',
                'playtime_forever' => 200,
                'last_played_at' => '2026-07-02 04:00:00',
                'cover_url' => 'https://example.com/portal2-new.jpg',
            ],
        ]));
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/sync')
            ->assertOk()
            ->assertJson([
                'imported' => 0,
                'updated' => 1,
            ]);

        $this->assertDatabaseHas('games', [
            'user_id' => $user->id,
            'steam_app_id' => 620,
            'status' => 'completed',
            'genre' => 'Puzzle',
            'platform' => 'Steam Deck',
            'playtime_minutes' => 200,
            'last_played_at' => '2026-07-02 04:00:00',
            'cover_url' => 'https://example.com/portal2-new.jpg',
        ]);
    }

    public function test_manual_game_with_matching_title_is_not_overwritten(): void
    {
        $user = User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        Game::factory()->for($user)->create([
            'title' => 'Portal 2',
            'steam_app_id' => null,
            'source' => 'manual',
        ]);

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('getPlayerSummary')->once()->andReturn([
            'communityvisibilitystate' => 3,
        ]);
        $client->shouldReceive('getOwnedGames')->once()->andReturn(collect([
            [
                'appid' => 620,
                'name' => 'Portal 2',
                'playtime_forever' => 200,
                'cover_url' => null,
            ],
        ]));
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)->postJson('/api/steam/sync')->assertOk();

        $this->assertSame(2, $user->fresh()->games()->count());
        $this->assertDatabaseHas('games', [
            'user_id' => $user->id,
            'steam_app_id' => null,
            'source' => 'manual',
        ]);
        $this->assertDatabaseHas('games', [
            'user_id' => $user->id,
            'steam_app_id' => 620,
            'source' => 'steam',
        ]);
    }

    public function test_empty_library_returns_zero_counts(): void
    {
        $user = User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('getPlayerSummary')->once()->andReturn([
            'communityvisibilitystate' => 3,
        ]);
        $client->shouldReceive('getOwnedGames')->once()->andReturn(collect());
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/sync')
            ->assertOk()
            ->assertJson([
                'imported' => 0,
                'updated' => 0,
            ]);

        $this->assertSame(0, $user->fresh()->games()->count());
    }

    public function test_sync_returns_502_when_steam_api_fails(): void
    {
        $user = User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('getPlayerSummary')
            ->once()
            ->andThrow(SteamApiException::badGateway());
        $this->app->instance(SteamClient::class, $client);

        $this->actingAs($user)
            ->postJson('/api/steam/sync')
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'steam_api_unavailable');
    }

    public function test_sync_route_is_throttled(): void
    {
        $user = User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        $client = Mockery::mock(SteamClient::class);
        $client->shouldReceive('getPlayerSummary')->times(6)->andReturn([
            'communityvisibilitystate' => 3,
        ]);
        $client->shouldReceive('getOwnedGames')->times(6)->andReturn(collect());
        $this->app->instance(SteamClient::class, $client);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->postJson('/api/steam/sync')
                ->assertOk();
        }

        $response = $this->actingAs($user)->postJson('/api/steam/sync');

        $response->assertStatus(429);
        $this->assertIsNumeric($response->headers->get('Retry-After'));
    }
}
