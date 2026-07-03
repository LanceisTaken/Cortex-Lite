<?php

namespace Tests\Feature\Hardware;

use App\Models\Cpu;
use Database\Seeders\CpuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CpuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_populates_at_least_40_cpus(): void
    {
        $this->seed(CpuSeeder::class);

        $this->assertGreaterThanOrEqual(40, Cpu::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(CpuSeeder::class);
        $countAfterFirst = Cpu::count();

        $this->seed(CpuSeeder::class);
        $countAfterSecond = Cpu::count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_ryzen_5_2600_is_classified_as_low(): void
    {
        $this->seed(CpuSeeder::class);

        $cpu = Cpu::where('name', 'Ryzen 5 2600')->first();

        $this->assertNotNull($cpu);
        $this->assertSame('low', $cpu->tier);
    }

    public function test_ryzen_7_5800x_is_classified_as_high(): void
    {
        $this->seed(CpuSeeder::class);

        $cpu = Cpu::where('name', 'Ryzen 7 5800X')->first();

        $this->assertNotNull($cpu);
        $this->assertSame('high', $cpu->tier);
    }

    public function test_ryzen_9_7950x_is_classified_as_enthusiast(): void
    {
        $this->seed(CpuSeeder::class);

        $cpu = Cpu::where('name', 'Ryzen 9 7950X')->first();

        $this->assertNotNull($cpu);
        $this->assertSame('enthusiast', $cpu->tier);
    }
}
