<?php

namespace Tests\Feature\SettingPresets;

use App\Models\SettingPreset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SettingPresetSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_presets_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('setting_presets'));
        $this->assertTrue(Schema::hasColumns('setting_presets', [
            'id', 'game', 'steam_app_id', 'goal', 'gpu_tier', 'settings', 'notes',
            'created_at', 'updated_at',
        ]));
    }

    public function test_setting_preset_factory_creates_a_row(): void
    {
        $preset = SettingPreset::factory()->create();

        $this->assertNotNull($preset->id);
        $this->assertContains($preset->goal, SettingPreset::GOALS);
        $this->assertContains($preset->gpu_tier, SettingPreset::GPU_TIERS);
        $this->assertIsArray($preset->settings);
    }

    public function test_game_goal_gpu_tier_tuple_is_unique(): void
    {
        SettingPreset::create([
            'game' => 'Cyberpunk 2077',
            'steam_app_id' => 1091500,
            'goal' => 'quality',
            'gpu_tier' => 'high',
            'settings' => ['ray_tracing' => 'on'],
            'notes' => 'Source: test fixture.',
        ]);

        $this->expectException(QueryException::class);

        SettingPreset::create([
            'game' => 'Cyberpunk 2077',
            'steam_app_id' => 1091500,
            'goal' => 'quality',
            'gpu_tier' => 'high',
            'settings' => ['ray_tracing' => 'off'],
            'notes' => 'Source: duplicate test fixture.',
        ]);
    }
}
