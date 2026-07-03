<?php

namespace Tests\Unit\Support\Hardware;

use App\Support\Hardware\GpuTierClassifier;
use PHPUnit\Framework\TestCase;

class GpuTierClassifierTest extends TestCase
{
    public function test_below_8000_is_low(): void
    {
        $this->assertSame('low', GpuTierClassifier::classify(0));
        $this->assertSame('low', GpuTierClassifier::classify(7999));
    }

    public function test_8000_to_13999_is_mid(): void
    {
        $this->assertSame('mid', GpuTierClassifier::classify(8000));
        $this->assertSame('mid', GpuTierClassifier::classify(13999));
    }

    public function test_14000_to_21999_is_high(): void
    {
        $this->assertSame('high', GpuTierClassifier::classify(14000));
        $this->assertSame('high', GpuTierClassifier::classify(21999));
    }

    public function test_22000_or_above_is_enthusiast(): void
    {
        $this->assertSame('enthusiast', GpuTierClassifier::classify(22000));
        $this->assertSame('enthusiast', GpuTierClassifier::classify(999999));
    }
}
