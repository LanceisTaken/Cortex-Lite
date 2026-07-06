<?php

namespace Tests\Feature\Recommendations;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\GameMetadata;
use App\Models\Gpu;
use App\Models\SettingPreset;
use App\Models\User;
use App\Services\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): RecommendationEngine
    {
        return app(RecommendationEngine::class);
    }

    public function test_uses_anchor_preset_when_one_matches_by_steam_app_id(): void
    {
        $game = Game::factory()->for(User::factory())->create([
            'steam_app_id' => 1091500,
            'title' => 'Cyberpunk 2077',
        ]);
        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        $preset = SettingPreset::factory()->create([
            'game' => 'Cyberpunk 2077',
            'steam_app_id' => 1091500,
            'goal' => 'quality',
            'gpu_tier' => 'high',
            'settings' => ['upscaling' => 'DLSS Quality mode', 'shadow_quality' => 'ultra'],
        ]);

        $result = $this->engine()->recommend($game, $gpu, $cpu, 32, 'quality');

        $this->assertSame('anchor', $result['source']);
        $this->assertSame($preset->settings, $result['settings']);
        $this->assertSame('high', $result['gpu_tier']);
        $this->assertSame('high', $result['cpu_tier']);
    }

    public function test_matches_anchor_by_title_when_game_has_no_steam_app_id(): void
    {
        $game = Game::factory()->for(User::factory())->create([
            'steam_app_id' => null,
            'title' => 'Minecraft Java',
        ]);
        $gpu = Gpu::factory()->create(['tier' => 'mid', 'g3d_mark' => 10000]);
        $cpu = Cpu::factory()->create(['tier' => 'mid', 'single_thread_mark' => 3000]);

        SettingPreset::factory()->create([
            'game' => 'Minecraft Java',
            'steam_app_id' => null,
            'goal' => 'balanced',
            'gpu_tier' => 'mid',
            'settings' => ['render_distance' => '16 chunks'],
        ]);

        $result = $this->engine()->recommend($game, $gpu, $cpu, 32, 'balanced');

        $this->assertSame('anchor', $result['source']);
        $this->assertSame(['render_distance' => '16 chunks'], $result['settings']);
    }

    public function test_falls_through_to_heuristic_when_no_anchor_matches(): void
    {
        $game = Game::factory()->for(User::factory())->create(['steam_app_id' => 424242]);
        GameMetadata::factory()->create([
            'game_id' => $game->id,
            'dlss_supported' => true,
            'fsr_supported' => false,
            'ray_tracing_supported' => true,
        ]);
        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        $result = $this->engine()->recommend($game, $gpu, $cpu, 32, 'quality');

        $this->assertSame('heuristic', $result['source']);
        $this->assertSame('quality', $result['settings']['upscaling']);
        $this->assertTrue($result['settings']['ray_tracing']);
        $this->assertArrayHasKey('texture_quality', $result['settings']);
    }

    public function test_low_ram_clamps_texture_quality_down_one_level(): void
    {
        $game = Game::factory()->for(User::factory())->create(['steam_app_id' => 424243]);
        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        $ample = $this->engine()->recommend($game, $gpu, $cpu, 32, 'quality');
        $starved = $this->engine()->recommend($game, $gpu, $cpu, 8, 'quality');

        $this->assertSame('high', $ample['settings']['texture_quality']);
        $this->assertSame('medium', $starved['settings']['texture_quality']);
        $this->assertSame('under_16gb', $starved['ram_bucket']);
    }

    public function test_flags_cpu_bottleneck_when_gpu_outranks_cpu_by_two_tiers(): void
    {
        $game = Game::factory()->for(User::factory())->create(['steam_app_id' => 424244]);
        $strongGpu = Gpu::factory()->create(['tier' => 'enthusiast', 'g3d_mark' => 30000]);
        $weakCpu = Cpu::factory()->create(['tier' => 'low', 'single_thread_mark' => 2000]);
        $matchedCpu = Cpu::factory()->create(['tier' => 'enthusiast', 'single_thread_mark' => 4200]);

        $bottlenecked = $this->engine()->recommend($game, $strongGpu, $weakCpu, 32, 'quality');
        $balanced = $this->engine()->recommend($game, $strongGpu, $matchedCpu, 32, 'quality');

        $this->assertTrue($bottlenecked['cpu_bottleneck']);
        $this->assertFalse($balanced['cpu_bottleneck']);
    }

    public function test_is_deterministic_for_identical_inputs(): void
    {
        $game = Game::factory()->for(User::factory())->create(['steam_app_id' => 424245]);
        $gpu = Gpu::factory()->create(['tier' => 'mid', 'g3d_mark' => 10000]);
        $cpu = Cpu::factory()->create(['tier' => 'mid', 'single_thread_mark' => 3000]);

        $first = $this->engine()->recommend($game, $gpu, $cpu, 16, 'balanced');
        $second = $this->engine()->recommend($game, $gpu, $cpu, 16, 'balanced');

        $this->assertSame($first, $second);
    }
}
