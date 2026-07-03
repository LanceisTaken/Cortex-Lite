<?php

namespace Tests\Feature\Hardware;

use App\Models\Cpu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CpuSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cpus_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('cpus'));
        $this->assertTrue(Schema::hasColumns('cpus', [
            'id', 'name', 'manufacturer', 'single_thread_mark', 'tier', 'released_year',
            'created_at', 'updated_at',
        ]));
    }

    public function test_cpu_factory_creates_a_row(): void
    {
        $cpu = Cpu::factory()->create();

        $this->assertNotNull($cpu->id);
        $this->assertContains($cpu->tier, Cpu::TIERS);
        $this->assertIsInt($cpu->single_thread_mark);
        $this->assertGreaterThan(0, $cpu->single_thread_mark);
    }

    public function test_cpu_name_is_unique(): void
    {
        Cpu::factory()->create(['name' => 'Ryzen 9 7950X']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Cpu::factory()->create(['name' => 'Ryzen 9 7950X']);
    }
}
