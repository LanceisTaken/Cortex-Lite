<?php

namespace App\Services;

use InvalidArgumentException;

class HeuristicRecommender
{
    private const TIER_RANKS = [
        'low' => 0,
        'mid' => 1,
        'high' => 2,
        'enthusiast' => 3,
    ];

    private const GOAL_RANKS = [
        'performance' => 0,
        'balanced' => 1,
        'quality' => 2,
    ];

    private const ORDINAL_LEVELS = ['low', 'medium', 'high', 'ultra'];

    public function recommend(string $gpuTier, string $goal, array $capabilities = []): array
    {
        $tierRank = $this->rankFor($gpuTier, self::TIER_RANKS, 'GPU tier');
        $goalRank = $this->rankFor($goal, self::GOAL_RANKS, 'goal');

        $ordinalLevel = self::ORDINAL_LEVELS[(int) round(($tierRank + $goalRank) / 5 * 3)];
        $upscalingSupported = ($capabilities['dlss_supported'] ?? false)
            || ($capabilities['fsr_supported'] ?? false);

        return [
            'resolution_scale' => $goal === 'performance' && $gpuTier === 'low' ? '90%' : '100%',
            'upscaling' => $upscalingSupported ? $goal : 'off',
            'ray_tracing' => ($capabilities['ray_tracing_supported'] ?? false) && $goal === 'quality' && $tierRank >= 2,
            'shadow_quality' => $ordinalLevel,
            'texture_quality' => $ordinalLevel,
            'anti_aliasing' => $ordinalLevel,
            'ambient_occlusion' => $ordinalLevel,
        ];
    }

    /**
     * @param  array<string, int>  $validRanks
     */
    private function rankFor(string $value, array $validRanks, string $label): int
    {
        if (! array_key_exists($value, $validRanks)) {
            throw new InvalidArgumentException("Unknown {$label}: {$value}");
        }

        return $validRanks[$value];
    }
}
