<?php

namespace Database\Factories;

use App\Models\SettingPreset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettingPreset>
 */
class SettingPresetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game' => fake()->unique()->words(3, true),
            'steam_app_id' => fake()->optional()->numberBetween(10, 2_500_000),
            'goal' => fake()->randomElement(SettingPreset::GOALS),
            'gpu_tier' => fake()->randomElement(SettingPreset::GPU_TIERS),
            'settings' => [
                'resolution_scale' => '100%',
                'upscaling' => 'off',
                'ray_tracing' => 'off',
                'shadow_quality' => 'medium',
            ],
            'notes' => 'Source: factory-generated test preset.',
        ];
    }
}
