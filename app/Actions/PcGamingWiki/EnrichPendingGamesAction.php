<?php

namespace App\Actions\PcGamingWiki;

use App\Exceptions\PcGamingWikiApiException;
use App\Exceptions\PcGamingWikiRateLimitException;
use App\Models\Game;
use App\Models\GameMetadata;
use App\Services\PcGamingWikiClient;
use Illuminate\Support\Facades\Log;

class EnrichPendingGamesAction
{
    public function execute(int $limit, PcGamingWikiClient $client): array
    {
        $result = ['enriched' => 0, 'missing' => 0, 'skipped' => 0, 'rate_limited' => false];

        $games = Game::query()
            ->where('metadata_status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($games as $game) {
            if ($game->steam_app_id === null) {
                $game->forceFill(['metadata_status' => 'missing'])->save();
                $result['skipped']++;

                Log::warning('Pending metadata game had no Steam AppID.', ['game_id' => $game->id]);

                continue;
            }

            try {
                $metadata = $client->fetchMetadata((int) $game->steam_app_id);
            } catch (PcGamingWikiRateLimitException $exception) {
                $result['rate_limited'] = true;

                Log::warning('PCGamingWiki metadata enrichment hit the rate limit.', [
                    'game_id' => $game->id,
                    'steam_app_id' => $game->steam_app_id,
                    'message' => $exception->getMessage(),
                ]);

                break;
            } catch (PcGamingWikiApiException $exception) {
                $game->forceFill(['metadata_status' => 'missing'])->save();
                $result['missing']++;

                Log::warning('PCGamingWiki metadata enrichment failed for game.', [
                    'game_id' => $game->id,
                    'steam_app_id' => $game->steam_app_id,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($metadata === null) {
                $game->forceFill(['metadata_status' => 'missing'])->save();
                $result['missing']++;

                continue;
            }

            GameMetadata::updateOrCreate(['game_id' => $game->id], $metadata);
            $game->forceFill(['metadata_status' => 'ok'])->save();
            $result['enriched']++;
        }

        return $result;
    }
}
