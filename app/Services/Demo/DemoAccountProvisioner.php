<?php

namespace App\Services\Demo;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoAccountProvisioner
{
    public const EMAIL = 'demo@cortex-lite.example';

    public const PASSWORD = 'cortex-demo-2026';

    public function ensureUser(): User
    {
        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Cortex Demo',
                'password' => Hash::make(self::PASSWORD),
            ],
        );

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    public function reset(): User
    {
        return DB::transaction(function (): User {
            $user = $this->ensureUser();

            $user->games()->delete();
            $user->usageEvents()->delete();
            $user->forceFill(['is_premium' => false])->save();

            foreach ($this->fixtureLibrary() as $row) {
                $game = $user->games()->create([
                    ...$row,
                    'platform' => $row['source'] === 'steam' ? 'Steam' : 'PC',
                    'last_played_at' => $row['status'] === 'playing' ? now()->subDay() : now()->subDays(20),
                ]);

                if ($row['status'] === 'playing') {
                    $started = Carbon::now()->subDays(2)->setTime(20, 0);

                    $user->playSessions()->create([
                        'game_id' => $game->id,
                        'started_at' => $started,
                        'ended_at' => $started->copy()->addHour(),
                        'duration_seconds' => 3600,
                    ]);
                }
            }

            return $user->fresh();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fixtureLibrary(): array
    {
        return json_decode(file_get_contents(database_path('data/demo_library.json')), true, 512, JSON_THROW_ON_ERROR);
    }
}
