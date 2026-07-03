<?php

namespace Tests\Unit\Support\Hardware;

use App\Support\Hardware\CpuTierClassifier;
use PHPUnit\Framework\TestCase;

class CpuTierClassifierTest extends TestCase
{
    public function test_below_2800_is_low(): void
    {
        $this->assertSame('low', CpuTierClassifier::classify(0));
        $this->assertSame('low', CpuTierClassifier::classify(2799));
    }

    public function test_2800_to_3399_is_mid(): void
    {
        $this->assertSame('mid', CpuTierClassifier::classify(2800));
        $this->assertSame('mid', CpuTierClassifier::classify(3399));
    }

    public function test_3400_to_3999_is_high(): void
    {
        $this->assertSame('high', CpuTierClassifier::classify(3400));
        $this->assertSame('high', CpuTierClassifier::classify(3999));
    }

    public function test_4000_or_above_is_enthusiast(): void
    {
        $this->assertSame('enthusiast', CpuTierClassifier::classify(4000));
        $this->assertSame('enthusiast', CpuTierClassifier::classify(99999));
    }
}
