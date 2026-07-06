<?php

namespace Tests\Feature\Recommendations;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\Gpu;
use App\Models\SettingPreset;
use App\Models\User;
use App\Services\SettingsDiffEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsDiffEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): SettingsDiffEngine
    {
        return app(SettingsDiffEngine::class);
    }

    /**
     * Anchor-drive the canonical preset so the expected diff is fully controlled.
     *
     * @param  array<string, mixed>  $settings
     * @return array{0: Game, 1: Gpu, 2: Cpu}
     */
    private function scenarioWithAnchor(array $settings): array
    {
        $game = Game::factory()->for(User::factory())->create([
            'steam_app_id' => 1091500,
            'title' => 'Cyberpunk 2077',
        ]);
        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        SettingPreset::factory()->create([
            'game' => 'Cyberpunk 2077',
            'steam_app_id' => 1091500,
            'goal' => 'quality',
            'gpu_tier' => 'high',
            'settings' => $settings,
        ]);

        return [$game, $gpu, $cpu];
    }

    public function test_diffs_pasted_settings_against_the_canonical_preset(): void
    {
        [$game, $gpu, $cpu] = $this->scenarioWithAnchor([
            'texture_quality' => 'medium',
            'ray_tracing' => false,
            'shadow_quality' => 'high',
        ]);

        $result = $this->engine()->diff($game, $gpu, $cpu, 32, 'quality', [
            'texture_quality' => 'ultra',
            'ray_tracing' => true,
            'shadow_quality' => 'high',
        ]);

        $this->assertSame(['texture_quality', 'ray_tracing'], array_column($result['diff'], 'setting'));
        $this->assertSame('ultra → medium', $result['diff'][0]['label']);
        $this->assertSame('on → off', $result['diff'][1]['label']);
    }

    public function test_returns_the_recommendation_metadata_alongside_the_diff(): void
    {
        [$game, $gpu, $cpu] = $this->scenarioWithAnchor(['texture_quality' => 'medium']);

        $result = $this->engine()->diff($game, $gpu, $cpu, 32, 'quality', [
            'texture_quality' => 'ultra',
        ]);

        $this->assertSame('anchor', $result['recommendation']['source']);
        $this->assertSame('high', $result['recommendation']['gpu_tier']);
        $this->assertSame(['texture_quality' => 'medium'], $result['recommendation']['settings']);
    }

    public function test_empty_diff_when_current_settings_already_match(): void
    {
        [$game, $gpu, $cpu] = $this->scenarioWithAnchor([
            'texture_quality' => 'medium',
            'ray_tracing' => false,
        ]);

        $result = $this->engine()->diff($game, $gpu, $cpu, 32, 'quality', [
            'texture_quality' => 'medium',
            'ray_tracing' => false,
        ]);

        $this->assertSame([], $result['diff']);
    }

    public function test_ignores_pasted_settings_the_recommendation_does_not_cover(): void
    {
        [$game, $gpu, $cpu] = $this->scenarioWithAnchor(['texture_quality' => 'medium']);

        $result = $this->engine()->diff($game, $gpu, $cpu, 32, 'quality', [
            'texture_quality' => 'medium',
            'motion_blur' => 'on',
            'film_grain' => 'high',
        ]);

        $this->assertSame([], $result['diff']);
    }
}
