<?php

namespace App\Actions\PlaySessions;

use App\Exceptions\PlaySessionAlreadyEndedException;
use App\Models\PlaySession;
use Illuminate\Support\Facades\DB;

class EndPlaySessionAction
{
    public function execute(PlaySession $session): PlaySession
    {
        return DB::transaction(function () use ($session) {
            // Re-fetch under a row lock so concurrent end calls serialize cleanly.
            $locked = PlaySession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->ended_at !== null) {
                throw new PlaySessionAlreadyEndedException();
            }

            $endedAt = now();
            $durationSeconds = (int) $endedAt->diffInSeconds($locked->started_at, absolute: true);

            $locked->update([
                'ended_at' => $endedAt,
                'duration_seconds' => $durationSeconds,
            ]);

            $game = $locked->game()->lockForUpdate()->first();
            $update = ['last_played_at' => $endedAt];

            if ($game->source === 'manual') {
                $update['playtime_minutes'] = $game->playtime_minutes + intdiv($durationSeconds, 60);
            }

            $game->update($update);

            return $locked->fresh();
        });
    }
}
