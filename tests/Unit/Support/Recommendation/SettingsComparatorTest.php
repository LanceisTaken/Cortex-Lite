<?php

namespace Tests\Unit\Support\Recommendation;

use App\Support\Recommendation\SettingsComparator;
use PHPUnit\Framework\TestCase;

class SettingsComparatorTest extends TestCase
{
    public function test_emits_an_entry_for_a_changed_scalar_setting(): void
    {
        $diff = SettingsComparator::compare(
            ['texture_quality' => 'ultra'],
            ['texture_quality' => 'medium'],
        );

        $this->assertSame([
            [
                'setting' => 'texture_quality',
                'current' => 'ultra',
                'recommended' => 'medium',
                'label' => 'ultra → medium',
            ],
        ], $diff);
    }

    public function test_renders_booleans_as_on_and_off(): void
    {
        $diff = SettingsComparator::compare(
            ['ray_tracing' => true],
            ['ray_tracing' => false],
        );

        $this->assertSame([
            [
                'setting' => 'ray_tracing',
                'current' => 'on',
                'recommended' => 'off',
                'label' => 'on → off',
            ],
        ], $diff);
    }

    public function test_matching_values_produce_no_entry(): void
    {
        $diff = SettingsComparator::compare(
            ['shadow_quality' => 'high'],
            ['shadow_quality' => 'high'],
        );

        $this->assertSame([], $diff);
    }

    public function test_equality_is_case_insensitive(): void
    {
        $diff = SettingsComparator::compare(
            ['texture_quality' => 'High'],
            ['texture_quality' => 'high'],
        );

        $this->assertSame([], $diff);
    }

    public function test_ignores_pasted_keys_absent_from_the_recommendation(): void
    {
        $diff = SettingsComparator::compare(
            ['motion_blur' => 'on', 'texture_quality' => 'ultra'],
            ['texture_quality' => 'medium'],
        );

        $this->assertCount(1, $diff);
        $this->assertSame('texture_quality', $diff[0]['setting']);
    }

    public function test_skips_recommended_keys_the_user_did_not_provide(): void
    {
        $diff = SettingsComparator::compare(
            ['texture_quality' => 'ultra'],
            ['texture_quality' => 'medium', 'shadow_quality' => 'high'],
        );

        $this->assertCount(1, $diff);
        $this->assertSame('texture_quality', $diff[0]['setting']);
    }

    public function test_entry_order_follows_recommended_key_order(): void
    {
        $current = ['ray_tracing' => true, 'texture_quality' => 'ultra'];
        $recommended = ['texture_quality' => 'medium', 'ray_tracing' => false];

        $diff = SettingsComparator::compare($current, $recommended);

        $this->assertSame(['texture_quality', 'ray_tracing'], array_column($diff, 'setting'));
    }
}
