<?php

namespace Tests\Feature\SettingPresets;

use App\Models\SettingPreset;
use App\Services\HeuristicRecommender;
use Database\Seeders\SettingPresetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnchorRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_heuristic_recommender_does_not_contradict_anchor_capabilities(): void
    {
        $this->seed(SettingPresetSeeder::class);
        $recommender = new HeuristicRecommender();

        SettingPreset::all()->each(function (SettingPreset $preset) use ($recommender): void {
            $capabilities = $this->inferCapabilities($preset->settings);
            $recommendation = $recommender->recommend($preset->gpu_tier, $preset->goal, $capabilities);

            if ($recommendation['ray_tracing']) {
                $this->assertTrue(
                    $capabilities['ray_tracing_supported'],
                    "{$preset->game} {$preset->gpu_tier}/{$preset->goal} heuristic enabled ray tracing against the anchor.",
                );
            }

            if ($recommendation['upscaling'] !== 'off') {
                $this->assertTrue(
                    $capabilities['dlss_supported'] || $capabilities['fsr_supported'],
                    "{$preset->game} {$preset->gpu_tier}/{$preset->goal} heuristic enabled upscaling against the anchor.",
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{ray_tracing_supported: bool, dlss_supported: bool, fsr_supported: bool}
     */
    private function inferCapabilities(array $settings): array
    {
        $rayTracingOn = false;
        $upscalingSupported = false;

        foreach ($settings as $key => $value) {
            $normalizedKey = (string) $key;
            $normalizedValue = strtolower((string) $value);

            if (preg_match('/ray_trac/i', $normalizedKey) === 1) {
                $rayTracingOn = ! preg_match('/off|disabled|not supported/i', $normalizedValue);
            }

            if (preg_match('/upscal/i', $normalizedKey) === 1) {
                $upscalingSupported = ! preg_match('/not supported|none/i', $normalizedValue);
            }
        }

        return [
            'ray_tracing_supported' => $rayTracingOn,
            'dlss_supported' => $upscalingSupported,
            'fsr_supported' => false,
        ];
    }
}
