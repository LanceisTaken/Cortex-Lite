<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SteamLibrarySynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SteamSyncAllCommand extends Command
{
    protected $signature = 'steam:sync-all';

    protected $description = 'Sync Steam libraries for all connected users.';

    public function handle(SteamLibrarySynchronizer $synchronizer): int
    {
        $users = User::query()
            ->whereNotNull('steam_id')
            ->orderBy('id')
            ->get();

        Log::info('Starting Steam sync batch.', ['count' => $users->count()]);

        foreach ($users as $user) {
            try {
                $result = $synchronizer->sync($user);

                Log::info('Steam sync completed.', [
                    'user_id' => $user->id,
                    'steam_id' => $user->steam_id,
                    ...$result,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Steam sync failed for user.', [
                    'user_id' => $user->id,
                    'steam_id' => $user->steam_id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
