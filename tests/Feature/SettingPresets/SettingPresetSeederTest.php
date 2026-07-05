<?php

namespace Tests\Feature\SettingPresets;

use App\Models\SettingPreset;
use Database\Seeders\SettingPresetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingPresetSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_populates_all_anchor_presets(): void
    {
        $this->seed(SettingPresetSeeder::class);

        $this->assertSame(30, SettingPreset::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(SettingPresetSeeder::class);
        $countAfterFirst = SettingPreset::count();

        $this->seed(SettingPresetSeeder::class);
        $countAfterSecond = SettingPreset::count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_cyberpunk_quality_high_anchor_keeps_expected_settings_shape(): void
    {
        $this->seed(SettingPresetSeeder::class);

        $preset = SettingPreset::where('game', 'Cyberpunk 2077')
            ->where('goal', 'quality')
            ->where('gpu_tier', 'high')
            ->firstOrFail();

        $this->assertSame(1091500, $preset->steam_app_id);
        $this->assertSame('DLSS Quality mode', $preset->settings['upscaling']);
        $this->assertSame('on (medium/psycho reflections+shadows)', $preset->settings['ray_tracing']);
        $this->assertArrayHasKey('screen_space_reflections', $preset->settings);
    }
}
