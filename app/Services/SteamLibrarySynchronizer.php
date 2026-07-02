<?php

namespace App\Services;

use App\Exceptions\SteamPrivateProfileException;
use App\Models\Game;
use App\Models\User;

class SteamLibrarySynchronizer
{
    public function __construct(
        private readonly SteamClient $steamClient,
    ) {
    }

    public function sync(User $user): array
    {
        $summary = $this->steamClient->getPlayerSummary($user->steam_id);
        $visibilityState = (int) ($summary['communityvisibilitystate'] ?? 0);

        if ($visibilityState < 3) {
            throw new SteamPrivateProfileException('Steam profile is not public.');
        }

        $games = $this->steamClient->getOwnedGames($user->steam_id);

        if ($games->isEmpty()) {
            return ['imported' => 0, 'updated' => 0];
        }

        $existingSteamIds = $user->games()
            ->whereNotNull('steam_app_id')
            ->pluck('steam_app_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $existingSet = array_flip($existingSteamIds);
        $updated = 0;
        $imported = 0;
        $timestamp = now();
        $rows = [];

        foreach ($games as $game) {
            if (isset($existingSet[$game['appid']])) {
                $updated++;
            } else {
                $imported++;
            }

            $rows[] = [
                'user_id' => $user->id,
                'title' => $game['name'],
                'status' => 'backlog',
                'steam_app_id' => $game['appid'],
                'source' => 'steam',
                'metadata_status' => 'pending',
                'cover_url' => $game['cover_url'],
                'playtime_minutes' => $game['playtime_forever'],
                'last_played_at' => $game['last_played_at'] ?? null,
                'updated_at' => $timestamp,
                'created_at' => $timestamp,
            ];
        }

        Game::upsert(
            $rows,
            ['user_id', 'steam_app_id'],
            ['title', 'cover_url', 'playtime_minutes', 'last_played_at', 'source', 'metadata_status', 'updated_at'],
        );

        return [
            'imported' => $imported,
            'updated' => $updated,
        ];
    }
}
