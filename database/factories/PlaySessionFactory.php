<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\PlaySession>
 */
class PlaySessionFactory extends Factory
{
    public function definition(): array
    {
        $started = fake()->dateTimeBetween('-30 days', '-1 hour');
        $duration = fake()->numberBetween(60, 4 * 3600);
        $ended = (clone $started)->modify("+{$duration} seconds");

        return [
            'user_id' => User::factory(),
            'game_id' => Game::factory(),
            'started_at' => $started,
            'ended_at' => $ended,
            'duration_seconds' => $duration,
        ];
    }

    public function active(): self
    {
        return $this->state(fn () => [
            'started_at' => now()->subMinutes(5),
            'ended_at' => null,
            'duration_seconds' => null,
        ]);
    }
}
