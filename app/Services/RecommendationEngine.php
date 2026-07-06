<?php

namespace App\Services;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\Gpu;
use App\Models\SettingPreset;
use App\Support\Recommendation\RamBucketClassifier;

class RecommendationEngine
{
    private const TIER_RANKS = [
        'low' => 0,
        'mid' => 1,
        'high' => 2,
        'enthusiast' => 3,
    ];

    private const ORDINAL_LEVELS = ['low', 'medium', 'high', 'ultra'];

    public function __construct(private readonly HeuristicRecommender $heuristic) {}

    /**
     * @return array{settings: array<string, mixed>, source: string, gpu_tier: string, cpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}
     */
    public function recommend(Game $game, Gpu $gpu, Cpu $cpu, int $ramGb, string $goal): array
    {
        $ramBucket = RamBucketClassifier::classify($ramGb);
        $anchor = $this->anchorFor($game, $gpu->tier, $goal);

        if ($anchor !== null) {
            $settings = $anchor->settings;
            $source = 'anchor';
        } else {
            $settings = $this->heuristic->recommend($gpu->tier, $goal, $this->capabilitiesFor($game));
            $source = 'heuristic';
        }

        return [
            'settings' => $this->applyRamAdjustment($settings, $ramBucket),
            'source' => $source,
            'gpu_tier' => $gpu->tier,
            'cpu_tier' => $cpu->tier,
            'ram_bucket' => $ramBucket,
            'cpu_bottleneck' => $this->isCpuBottleneck($gpu->tier, $cpu->tier),
        ];
    }

    private function anchorFor(Game $game, string $gpuTier, string $goal): ?SettingPreset
    {
        $query = SettingPreset::query()
            ->where('goal', $goal)
            ->where('gpu_tier', $gpuTier);

        if ($game->steam_app_id !== null) {
            $query->where('steam_app_id', $game->steam_app_id);
        } else {
            $query->where('game', $game->title);
        }

        return $query->first();
    }

    /**
     * @return array{dlss_supported: bool, fsr_supported: bool, ray_tracing_supported: bool}
     */
    private function capabilitiesFor(Game $game): array
    {
        $metadata = $game->metadata;

        return [
            'dlss_supported' => (bool) ($metadata->dlss_supported ?? false),
            'fsr_supported' => (bool) ($metadata->fsr_supported ?? false),
            'ray_tracing_supported' => (bool) ($metadata->ray_tracing_supported ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function applyRamAdjustment(array $settings, string $ramBucket): array
    {
        if ($ramBucket !== RamBucketClassifier::UNDER_16GB || ! array_key_exists('texture_quality', $settings)) {
            return $settings;
        }

        $index = array_search(strtolower((string) $settings['texture_quality']), self::ORDINAL_LEVELS, true);

        if ($index !== false && $index > 0) {
            $settings['texture_quality'] = self::ORDINAL_LEVELS[$index - 1];
        }

        return $settings;
    }

    private function isCpuBottleneck(string $gpuTier, string $cpuTier): bool
    {
        return (self::TIER_RANKS[$gpuTier] - self::TIER_RANKS[$cpuTier]) >= 2;
    }
}
