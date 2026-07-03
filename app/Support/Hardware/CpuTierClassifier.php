<?php

namespace App\Support\Hardware;

final class CpuTierClassifier
{
    public const THRESHOLDS = [
        'low_max' => 2799,
        'mid_max' => 3399,
        'high_max' => 3999,
    ];

    public static function classify(int $singleThreadMark): string
    {
        return match (true) {
            $singleThreadMark <= self::THRESHOLDS['low_max'] => 'low',
            $singleThreadMark <= self::THRESHOLDS['mid_max'] => 'mid',
            $singleThreadMark <= self::THRESHOLDS['high_max'] => 'high',
            default => 'enthusiast',
        };
    }
}
