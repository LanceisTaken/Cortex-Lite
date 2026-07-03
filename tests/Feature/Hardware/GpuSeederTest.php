<?php

namespace Tests\Feature\Hardware;

use App\Models\Gpu;
use Database\Seeders\GpuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GpuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_populates_at_least_60_gpus(): void
    {
        $this->seed(GpuSeeder::class);

        $this->assertGreaterThanOrEqual(60, Gpu::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(GpuSeeder::class);
        $countAfterFirst = Gpu::count();

        $this->seed(GpuSeeder::class);
        $countAfterSecond = Gpu::count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_gtx_1060_6gb_is_classified_as_low(): void
    {
        $this->seed(GpuSeeder::class);

        $gpu = Gpu::where('name', 'GeForce GTX 1060 6GB')->first();

        $this->assertNotNull($gpu);
        $this->assertSame('low', $gpu->tier);
    }

    public function test_rtx_3070_is_classified_as_high(): void
    {
        $this->seed(GpuSeeder::class);

        $gpu = Gpu::where('name', 'GeForce RTX 3070')->first();

        $this->assertNotNull($gpu);
        $this->assertSame('high', $gpu->tier);
    }

    public function test_rtx_4090_is_classified_as_enthusiast(): void
    {
        $this->seed(GpuSeeder::class);

        $gpu = Gpu::where('name', 'GeForce RTX 4090')->first();

        $this->assertNotNull($gpu);
        $this->assertSame('enthusiast', $gpu->tier);
    }
}
