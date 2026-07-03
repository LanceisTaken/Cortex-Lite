<?php

namespace Database\Factories;

use App\Models\Gpu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gpu>
 */
class GpuFactory extends Factory
{
    public function definition(): array
    {
        $g3dMark = fake()->numberBetween(3000, 35000);
        $tier = match (true) {
            $g3dMark < 8000 => 'low',
            $g3dMark < 14000 => 'mid',
            $g3dMark < 22000 => 'high',
            default => 'enthusiast',
        };

        return [
            'name' => fake()->unique()->bothify('Model ##??'),
            'manufacturer' => fake()->randomElement(['NVIDIA', 'AMD', 'Intel']),
            'g3d_mark' => $g3dMark,
            'tier' => $tier,
            'released_year' => fake()->numberBetween(2018, 2024),
        ];
    }
}
