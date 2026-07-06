<?php

namespace Tests\Unit\Support\Recommendation;

use App\Support\Recommendation\ExplanationCacheKey;
use Tests\TestCase;

class ExplanationCacheKeyTest extends TestCase
{
    public function test_forward_key_is_exact_deterministic_and_has_no_timestamp(): void
    {
        $key = ExplanationCacheKey::forward(42, 'high', 'high', '16-31GB', 'quality');

        $this->assertSame('llm:explain:forward:42:high:high:16-31GB:quality', $key);
        $this->assertSame($key, ExplanationCacheKey::forward(42, 'high', 'high', '16-31GB', 'quality'));
        $this->assertStringNotContainsString((string) now()->timestamp, $key);
    }

    public function test_reverse_key_is_deterministic_ignores_label_and_has_no_timestamp(): void
    {
        $diffA = [['setting' => 'texture_quality', 'current' => 'ultra', 'recommended' => 'medium', 'label' => 'ultra -> medium']];
        $diffB = [['setting' => 'texture_quality', 'current' => 'ultra', 'recommended' => 'medium', 'label' => 'A DIFFERENT LABEL']];

        $keyA = ExplanationCacheKey::reverse($diffA, 'high', 'high', '32GB+', 'quality');
        $keyB = ExplanationCacheKey::reverse($diffB, 'high', 'high', '32GB+', 'quality');

        $this->assertSame($keyA, $keyB);
        $this->assertStringStartsWith('llm:explain:reverse:', $keyA);
        $this->assertSame($keyA, ExplanationCacheKey::reverse($diffA, 'high', 'high', '32GB+', 'quality'));
        $this->assertStringNotContainsString((string) now()->timestamp, $keyA);
    }

    public function test_reverse_key_changes_with_diff_content(): void
    {
        $a = ExplanationCacheKey::reverse(
            [['setting' => 'x', 'current' => 'a', 'recommended' => 'b', 'label' => 'a -> b']],
            'high',
            'high',
            '32GB+',
            'quality',
        );
        $b = ExplanationCacheKey::reverse(
            [['setting' => 'x', 'current' => 'a', 'recommended' => 'c', 'label' => 'a -> c']],
            'high',
            'high',
            '32GB+',
            'quality',
        );

        $this->assertNotSame($a, $b);
    }

    public function test_reverse_key_changes_with_hardware_tier(): void
    {
        $diff = [['setting' => 'x', 'current' => 'a', 'recommended' => 'b', 'label' => 'a -> b']];

        $this->assertNotSame(
            ExplanationCacheKey::reverse($diff, 'high', 'high', '32GB+', 'quality'),
            ExplanationCacheKey::reverse($diff, 'low', 'high', '32GB+', 'quality'),
        );
    }
}
