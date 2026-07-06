<?php

namespace App\Support\Recommendation;

class ExplanationCacheKey
{
    public static function forward(int $gameId, string $gpuTier, string $cpuTier, string $ramBucket, string $goal): string
    {
        return sprintf('llm:explain:forward:%d:%s:%s:%s:%s', $gameId, $gpuTier, $cpuTier, $ramBucket, $goal);
    }

    /**
     * Reverse-mode key = hash(diff structure, hardware tiers, goal). The diff's
     * label is derived from current/recommended and is excluded so it cannot
     * fragment the cache. No timestamps or request-unique values.
     *
     * @param  list<array{setting: string, current: string, recommended: string, label: string}>  $diff
     */
    public static function reverse(array $diff, string $gpuTier, string $cpuTier, string $ramBucket, string $goal): string
    {
        $structure = array_map(
            static fn (array $entry): array => [$entry['setting'], $entry['current'], $entry['recommended']],
            $diff,
        );

        $hash = hash('sha256', (string) json_encode([
            'diff' => $structure,
            'gpu_tier' => $gpuTier,
            'cpu_tier' => $cpuTier,
            'ram_bucket' => $ramBucket,
            'goal' => $goal,
        ]));

        return 'llm:explain:reverse:'.$hash;
    }
}
