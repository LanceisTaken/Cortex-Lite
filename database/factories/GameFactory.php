<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'platform' => fake()->randomElement(['PC', 'Steam Deck', 'Windows']),
            'genre' => fake()->randomElement(['Action', 'RPG', 'Strategy']),
            'status' => fake()->randomElement(['playing', 'backlog', 'completed', 'dropped']),
            'playtime_minutes' => fake()->numberBetween(0, 6000),
            'last_played_at' => fake()->optional()->dateTimeBetween('-2 months', 'now'),
            'steam_app_id' => fake()->optional()->numberBetween(10, 2000000),
            'source' => 'manual',
            'metadata_status' => 'missing',
            'cover_url' => fake()->optional()->url(),
        ];
    }
}
