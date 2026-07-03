<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameMetadata;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameMetadata>
 */
class GameMetadataFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'direct3d_versions' => ['11', '12'],
            'vulkan_supported' => fake()->boolean(),
            'hdr_supported' => fake()->boolean(),
            'ultrawide_supported' => fake()->boolean(),
            'dlss_supported' => fake()->boolean(),
            'fsr_supported' => fake()->boolean(),
            'ray_tracing_supported' => fake()->boolean(),
            'raw_response' => ['cargoquery' => []],
        ];
    }
}
