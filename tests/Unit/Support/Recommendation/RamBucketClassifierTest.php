<?php

namespace Tests\Unit\Support\Recommendation;

use App\Support\Recommendation\RamBucketClassifier;
use PHPUnit\Framework\TestCase;

class RamBucketClassifierTest extends TestCase
{
    public function test_below_16gb_is_the_under_bucket(): void
    {
        $this->assertSame(RamBucketClassifier::UNDER_16GB, RamBucketClassifier::classify(8));
        $this->assertSame(RamBucketClassifier::UNDER_16GB, RamBucketClassifier::classify(15));
    }

    public function test_16_to_31gb_is_the_mid_bucket(): void
    {
        $this->assertSame(RamBucketClassifier::MID_16_TO_31GB, RamBucketClassifier::classify(16));
        $this->assertSame(RamBucketClassifier::MID_16_TO_31GB, RamBucketClassifier::classify(31));
    }

    public function test_32gb_and_above_is_the_top_bucket(): void
    {
        $this->assertSame(RamBucketClassifier::AT_LEAST_32GB, RamBucketClassifier::classify(32));
        $this->assertSame(RamBucketClassifier::AT_LEAST_32GB, RamBucketClassifier::classify(128));
    }
}
