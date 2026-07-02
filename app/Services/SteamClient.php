<?php

namespace App\Services;

use App\Exceptions\SteamApiException;
use App\Exceptions\SteamPrivateLibraryException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SteamClient
{
    private const API_BASE = 'https://api.steampowered.com';

    private const OWNED_GAMES_TTL_SECONDS = 3600;

    private const PLAYER_SUMMARY_TTL_SECONDS = 60;

    public function __construct(
        private readonly ?string $apiKey = null,
    ) {
    }

    public function getOwnedGames(string $steamId): Collection
    {
        return Cache::remember(
            $this->ownedGamesCacheKey($steamId),
            self::OWNED_GAMES_TTL_SECONDS,
            function () use ($steamId): Collection {
                $payload = $this->decodeResponse(
                    $this->apiGet('/IPlayerService/GetOwnedGames/v0001/', [
                        'steamid' => $steamId,
                        'include_appinfo' => 1,
                        'include_played_free_games' => 1,
                        'format' => 'json',
                    ])
                );

                $response = $payload['response'] ?? null;

                if (! is_array($response)) {
                    throw SteamApiException::badGateway('Steam owned-games response was malformed.');
                }

                if (! array_key_exists('games', $response)) {
                    if (($response['game_count'] ?? null) === 0) {
                        return collect();
                    }

                    throw new SteamPrivateLibraryException('Steam game details are private.');
                }

                return collect($response['games'])
                    ->filter(fn ($game) => is_array($game) && isset($game['appid'], $game['name']))
                    ->map(fn (array $game) => [
                        'appid' => (int) $game['appid'],
                        'name' => (string) $game['name'],
                        'playtime_forever' => (int) ($game['playtime_forever'] ?? 0),
                        'last_played_at' => $this->normalizeLastPlayedAt($game),
                        'cover_url' => $this->buildCoverUrl($game),
                    ])
                    ->values();
            }
        );
    }

    public function getPlayerSummary(string $steamId): array
    {
        return Cache::remember(
            $this->playerSummaryCacheKey($steamId),
            self::PLAYER_SUMMARY_TTL_SECONDS,
            function () use ($steamId): array {
                $payload = $this->decodeResponse(
                    $this->apiGet('/ISteamUser/GetPlayerSummaries/v0002/', [
                        'steamids' => $steamId,
                        'format' => 'json',
                    ])
                );

                $players = $payload['response']['players'] ?? null;

                if (! is_array($players)) {
                    throw SteamApiException::badGateway('Steam player-summary response was malformed.');
                }

                return is_array($players[0] ?? null) ? $players[0] : [];
            }
        );
    }

    public function ownedGamesCacheKey(string $steamId): string
    {
        return 'steam:owned_games:'.sha1($steamId);
    }

    public function playerSummaryCacheKey(string $steamId): string
    {
        return 'steam:summary:'.sha1($steamId);
    }

    public function playerSummaryTtlSeconds(): int
    {
        return self::PLAYER_SUMMARY_TTL_SECONDS;
    }

    private function apiGet(string $path, array $query): Response
    {
        try {
            $response = Http::timeout(10)->get(self::API_BASE.$path, [
                ...$query,
                'key' => $this->apiKey ?? (string) config('services.steam.api_key'),
            ]);
        } catch (ConnectionException $exception) {
            throw SteamApiException::serviceUnavailable(previous: $exception);
        }

        if (! $response->successful()) {
            throw SteamApiException::badGateway('Steam API request failed.');
        }

        return $response;
    }

    private function decodeResponse(Response $response): array
    {
        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            throw SteamApiException::badGateway('Steam API returned malformed JSON.');
        }

        return $decoded;
    }

    private function buildCoverUrl(array $game): ?string
    {
        if (! isset($game['appid'])) {
            return null;
        }

        return sprintf(
            'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/%s/library_600x900.jpg',
            $game['appid'],
        );
    }

    private function normalizeLastPlayedAt(array $game): ?string
    {
        $timestamp = (int) ($game['rtime_last_played'] ?? 0);

        if ($timestamp <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($timestamp)->toDateTimeString();
    }
}
