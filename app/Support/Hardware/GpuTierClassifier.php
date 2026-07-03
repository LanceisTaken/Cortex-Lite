<?php

namespace App\Support\Hardware;

final class GpuTierClassifier
{
    public const THRESHOLDS = [
        'low_max' => 7999,
        'mid_max' => 13999,
        'high_max' => 21999,
    ];

    public static function classify(int $g3dMark): string
    {
        return match (true) {
            $g3dMark <= self::THRESHOLDS['low_max'] => 'low',
            $g3dMark <= self::THRESHOLDS['mid_max'] => 'mid',
            $g3dMark <= self::THRESHOLDS['high_max'] => 'high',
            default => 'enthusiast',
        };
    }
}
