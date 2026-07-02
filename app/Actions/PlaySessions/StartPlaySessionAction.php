<?php

namespace App\Actions\PlaySessions;

use App\Exceptions\PlaySessionAlreadyActiveException;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class StartPlaySessionAction
{
    public function execute(User $user, int $gameId): PlaySession
    {
        return DB::transaction(function () use ($user, $gameId) {
            // Serialize concurrent starts for this user without a non-portable partial index.
            User::whereKey($user->id)->lockForUpdate()->first();

            $game = $user->games()->whereKey($gameId)->first();
            if ($game === null) {
                throw new ModelNotFoundException();
            }

            if ($user->playSessions()->whereNull('ended_at')->exists()) {
                throw new PlaySessionAlreadyActiveException();
            }

            return $user->playSessions()->create([
                'game_id' => $game->id,
                'started_at' => now(),
            ]);
        });
    }
}
