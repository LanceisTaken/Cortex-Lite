<?php

namespace App\Services;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\Gpu;
use App\Support\Recommendation\SettingsComparator;

class SettingsDiffEngine
{
    public function __construct(private readonly RecommendationEngine $engine) {}

    /**
     * Diff a user's pasted current settings against the canonical recommendation.
     *
     * @param  array<string, mixed>  $currentSettings
     * @return array{diff: list<array{setting: string, current: string, recommended: string, label: string}>, recommendation: array<string, mixed>}
     */
    public function diff(Game $game, Gpu $gpu, Cpu $cpu, int $ramGb, string $goal, array $currentSettings): array
    {
        $recommendation = $this->engine->recommend($game, $gpu, $cpu, $ramGb, $goal);

        return [
            'diff' => SettingsComparator::compare($currentSettings, $recommendation['settings']),
            'recommendation' => $recommendation,
        ];
    }
}
