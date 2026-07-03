<?php

namespace Tests\Feature\Hardware;

use App\Models\Gpu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GpuSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_gpus_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('gpus'));
        $this->assertTrue(Schema::hasColumns('gpus', [
            'id', 'name', 'manufacturer', 'g3d_mark', 'tier', 'released_year',
            'created_at', 'updated_at',
        ]));
    }

    public function test_gpu_factory_creates_a_row(): void
    {
        $gpu = Gpu::factory()->create();

        $this->assertNotNull($gpu->id);
        $this->assertContains($gpu->tier, Gpu::TIERS);
        $this->assertIsInt($gpu->g3d_mark);
        $this->assertGreaterThan(0, $gpu->g3d_mark);
    }

    public function test_gpu_name_is_unique(): void
    {
        Gpu::factory()->create(['name' => 'RTX 4090']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Gpu::factory()->create(['name' => 'RTX 4090']);
    }
}
