<?php

namespace Tests\Unit\Services;

use App\Exceptions\SteamApiException;
use App\Exceptions\SteamPrivateLibraryException;
use App\Services\SteamClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SteamClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_get_owned_games_normalizes_response_shape(): void
    {
        Http::fake([
            'https://api.steampowered.com/*' => Http::response([
                'response' => [
                    'game_count' => 1,
                    'games' => [[
                        'appid' => 620,
                        'name' => 'Portal 2',
                        'playtime_forever' => 123,
                        'rtime_last_played' => 1782964800,
                        'img_icon_url' => 'iconhash',
                    ]],
                ],
            ]),
        ]);

        $games = app(SteamClient::class)->getOwnedGames('76561198000000000');

        $this->assertSame([[
            'appid' => 620,
            'name' => 'Portal 2',
            'playtime_forever' => 123,
            'last_played_at' => '2026-07-02 04:00:00',
            'cover_url' => 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/620/library_600x900.jpg',
        ]], $games->all());
    }

    public function test_get_owned_games_uses_cache_for_repeat_calls(): void
    {
        Http::fake([
            'https://api.steampowered.com/*' => Http::response([
                'response' => [
                    'game_count' => 1,
                    'games' => [[
                        'appid' => 620,
                        'name' => 'Portal 2',
                    ]],
                ],
            ]),
        ]);

        $client = app(SteamClient::class);

        $client->getOwnedGames('76561198000000000');
        $client->getOwnedGames('76561198000000000');

        Http::assertSentCount(1);
    }

    public function test_owned_games_cache_key_is_deterministic_and_hashed(): void
    {
        Http::fake([
            'https://api.steampowered.com/*' => Http::response([
                'response' => [
                    'game_count' => 0,
                    'games' => [],
                ],
            ]),
        ]);

        $client = app(SteamClient::class);
        $client->getOwnedGames('76561198000000000');

        $this->assertTrue(Cache::has($client->ownedGamesCacheKey('76561198000000000')));
        $this->assertSame(
            'steam:owned_games:'.sha1('76561198000000000'),
            $client->ownedGamesCacheKey('76561198000000000'),
        );
    }

    public function test_get_player_summary_returns_raw_payload(): void
    {
        Http::fake([
            'https://api.steampowered.com/*' => Http::response([
                'response' => [
                    'players' => [[
                        'steamid' => '76561198000000000',
                        'communityvisibilitystate' => 3,
                    ]],
                ],
            ]),
        ]);

        $summary = app(SteamClient::class)->getPlayerSummary('76561198000000000');

        $this->assertSame(3, $summary['communityvisibilitystate']);
    }

    public function test_get_owned_games_throws_on_malformed_json(): void
    {
        Http::fake([
            'https://api.steampowered.com/*' => Http::response('not-json', 200),
        ]);

        $this->expectException(SteamApiException::class);

        app(SteamClient::class)->getOwnedGames('76561198000000000');
    }

    public function test_get_owned_games_throws_on_non_200_response(): void
    {
        Http::fake([
            'https://api.steampowered.com/*' => Http::response('server error', 500),
        ]);

        $this->expectException(SteamApiException::class);

        app(SteamClient::class)->getOwnedGames('76561198000000000');
    }

    public function test_get_owned_games_distinguishes_private_library_from_empty_library(): void
    {
        Http::fake([
            'https://api.steampowered.com/*' => Http::response([
                'response' => [],
            ]),
        ]);

        $this->expectException(SteamPrivateLibraryException::class);

        app(SteamClient::class)->getOwnedGames('76561198000000000');
    }

    public function test_private_library_exceptions_are_not_cached(): void
    {
        Http::fake([
            'https://api.steampowered.com/*' => Http::response([
                'response' => [],
            ]),
        ]);

        $client = app(SteamClient::class);

        foreach ([1, 2] as $attempt) {
            try {
                $client->getOwnedGames('76561198000000000');
                $this->fail("Expected SteamPrivateLibraryException on attempt {$attempt}.");
            } catch (SteamPrivateLibraryException) {
                // Expected: Cache::remember should not store exceptions.
            }
        }

        $this->assertFalse(Cache::has($client->ownedGamesCacheKey('76561198000000000')));
        Http::assertSentCount(2);
    }

    public function test_player_summary_cache_ttl_is_60_seconds(): void
    {
        Cache::shouldReceive('remember')
            ->once()
            ->withArgs(function (string $key, int $ttl, callable $callback): bool {
                return str_starts_with($key, 'steam:summary:') && $ttl === 60;
            })
            ->andReturnUsing(fn (string $key, int $ttl, callable $callback) => $callback());

        Http::fake([
            'https://api.steampowered.com/*' => Http::response([
                'response' => [
                    'players' => [[
                        'communityvisibilitystate' => 3,
                    ]],
                ],
            ]),
        ]);

        app(SteamClient::class)->getPlayerSummary('76561198000000000');
    }

    public function test_missing_fields_are_normalized_without_dropping_the_game(): void
    {
        Http::fake([
            'https://api.steampowered.com/*' => Http::response([
                'response' => [
                    'game_count' => 1,
                    'games' => [[
                        'appid' => 620,
                        'name' => 'Portal 2',
                    ]],
                ],
            ]),
        ]);

        $games = app(SteamClient::class)->getOwnedGames('76561198000000000');

        $this->assertSame(0, $games[0]['playtime_forever']);
        $this->assertNull($games[0]['last_played_at']);
        $this->assertSame(
            'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/620/library_600x900.jpg',
            $games[0]['cover_url'],
        );
    }

}
