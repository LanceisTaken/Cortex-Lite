<?php

namespace Tests\Unit\Services;

use App\Services\HeuristicRecommender;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HeuristicRecommenderTest extends TestCase
{
    private const TIERS = ['low', 'mid', 'high', 'enthusiast'];

    private const GOALS = ['performance', 'balanced', 'quality'];

    private const ORDINAL_FIELDS = [
        'shadow_quality',
        'texture_quality',
        'anti_aliasing',
        'ambient_occlusion',
    ];

    private const ORDINAL_RANKS = [
        'low' => 0,
        'medium' => 1,
        'high' => 2,
        'ultra' => 3,
    ];

    public function test_ordinal_fields_are_non_decreasing_across_goals(): void
    {
        $recommender = new HeuristicRecommender();

        foreach (self::TIERS as $tier) {
            foreach (self::ORDINAL_FIELDS as $field) {
                $previousRank = -1;

                foreach (self::GOALS as $goal) {
                    $recommendation = $recommender->recommend($tier, $goal);
                    $rank = self::ORDINAL_RANKS[$recommendation[$field]];

                    $this->assertGreaterThanOrEqual($previousRank, $rank);
                    $previousRank = $rank;
                }
            }
        }
    }

    public function test_ordinal_fields_are_non_decreasing_across_tiers(): void
    {
        $recommender = new HeuristicRecommender();

        foreach (self::GOALS as $goal) {
            foreach (self::ORDINAL_FIELDS as $field) {
                $previousRank = -1;

                foreach (self::TIERS as $tier) {
                    $recommendation = $recommender->recommend($tier, $goal);
                    $rank = self::ORDINAL_RANKS[$recommendation[$field]];

                    $this->assertGreaterThanOrEqual($previousRank, $rank);
                    $previousRank = $rank;
                }
            }
        }
    }

    public function test_capability_masking_disables_ray_tracing_for_every_combination(): void
    {
        $recommender = new HeuristicRecommender();

        foreach (self::TIERS as $tier) {
            foreach (self::GOALS as $goal) {
                $recommendation = $recommender->recommend($tier, $goal, [
                    'ray_tracing_supported' => false,
                    'dlss_supported' => true,
                ]);

                $this->assertFalse($recommendation['ray_tracing']);
            }
        }
    }

    public function test_capability_masking_disables_upscaling_for_every_combination(): void
    {
        $recommender = new HeuristicRecommender();

        foreach (self::TIERS as $tier) {
            foreach (self::GOALS as $goal) {
                $recommendation = $recommender->recommend($tier, $goal, [
                    'dlss_supported' => false,
                    'fsr_supported' => false,
                    'ray_tracing_supported' => true,
                ]);

                $this->assertSame('off', $recommendation['upscaling']);
            }
        }
    }

    public function test_empty_capabilities_fail_safe_to_unsupported_features(): void
    {
        $recommendation = (new HeuristicRecommender())->recommend('high', 'quality');

        $this->assertSame('off', $recommendation['upscaling']);
        $this->assertFalse($recommendation['ray_tracing']);
    }

    public function test_supported_capabilities_enable_goal_specific_features(): void
    {
        $recommendation = (new HeuristicRecommender())->recommend('high', 'quality', [
            'dlss_supported' => true,
            'ray_tracing_supported' => true,
        ]);

        $this->assertSame('quality', $recommendation['upscaling']);
        $this->assertTrue($recommendation['ray_tracing']);
    }

    public function test_unknown_gpu_tier_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new HeuristicRecommender())->recommend('ancient', 'quality');
    }

    public function test_unknown_goal_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new HeuristicRecommender())->recommend('high', 'cinematic');
    }
}
