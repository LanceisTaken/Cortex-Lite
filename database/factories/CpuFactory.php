<?php

namespace Database\Factories;

use App\Models\Cpu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cpu>
 */
class CpuFactory extends Factory
{
    public function definition(): array
    {
        $singleThreadMark = fake()->numberBetween(1800, 5000);
        $tier = match (true) {
            $singleThreadMark < 2800 => 'low',
            $singleThreadMark < 3400 => 'mid',
            $singleThreadMark < 4000 => 'high',
            default => 'enthusiast',
        };

        return [
            'name' => fake()->unique()->bothify('CPU ##??'),
            'manufacturer' => fake()->randomElement(['AMD', 'Intel']),
            'single_thread_mark' => $singleThreadMark,
            'tier' => $tier,
            'released_year' => fake()->numberBetween(2018, 2024),
        ];
    }
}
