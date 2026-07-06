<?php

namespace App\Support\Recommendation;

class RamBucketClassifier
{
    public const UNDER_16GB = 'under_16gb';

    public const MID_16_TO_31GB = '16_to_31gb';

    public const AT_LEAST_32GB = '32gb_plus';

    public static function classify(int $ramGb): string
    {
        return match (true) {
            $ramGb < 16 => self::UNDER_16GB,
            $ramGb < 32 => self::MID_16_TO_31GB,
            default => self::AT_LEAST_32GB,
        };
    }
}
