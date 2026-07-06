<?php

namespace Tests\Unit\Services;

use App\Services\ExplanationGenerator;
use App\Services\GeminiClient;
use App\Support\Recommendation\ExplanationCacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExplanationGeneratorTest extends TestCase
{
    private array $recommendation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.5-flash');
        config()->set('services.gemini.cache_store', 'array');
        Cache::store('array')->flush();

        $this->recommendation = [
            'settings' => [
                'texture_quality' => 'ultra',
                'ray_tracing' => false,
            ],
            'source' => 'heuristic',
            'gpu_tier' => 'high',
            'cpu_tier' => 'high',
            'ram_bucket' => '32GB+',
            'cpu_bottleneck' => false,
        ];
    }

    public function test_forward_generates_and_caches_successful_prose(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->candidate('AI prose.')),
        ]);

        $key = ExplanationCacheKey::forward(42, 'high', 'high', '32GB+', 'quality');
        $result = $this->generator()->forward($this->recommendation, 'quality', 42, 'fallback');

        $this->assertSame('AI prose.', $result);
        $this->assertSame('AI prose.', Cache::store('array')->get($key));
        Http::assertSent(fn ($request) => str_contains((string) data_get($request->data(), 'contents.0.parts.0.text'), 'texture_quality'));
    }

    public function test_forward_cache_hit_skips_gemini(): void
    {
        $key = ExplanationCacheKey::forward(42, 'high', 'high', '32GB+', 'quality');
        Cache::store('array')->put($key, 'Cached prose.', 60);
        Http::fake();

        $result = $this->generator()->forward($this->recommendation, 'quality', 42, 'fallback');

        $this->assertSame('Cached prose.', $result);
        Http::assertNothingSent();
    }

    public function test_forward_failure_returns_fallback_without_caching_it(): void
    {
        config()->set('services.gemini.api_key', '');
        Http::fake();

        $key = ExplanationCacheKey::forward(42, 'high', 'high', '32GB+', 'quality');
        $result = $this->generator()->forward($this->recommendation, 'quality', 42, 'static fallback');

        $this->assertSame('static fallback', $result);
        $this->assertNull(Cache::store('array')->get($key));
        Http::assertNothingSent();
    }

    public function test_reverse_generates_and_caches_successful_prose(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->candidate('AI diff prose.')),
        ]);

        $diff = [[
            'setting' => 'texture_quality',
            'current' => 'low',
            'recommended' => 'ultra',
            'label' => 'low -> ultra',
        ]];
        $key = ExplanationCacheKey::reverse($diff, 'high', 'high', '32GB+', 'quality');
        $result = $this->generator()->reverse($diff, $this->recommendation, 'quality', 'fallback');

        $this->assertSame('AI diff prose.', $result);
        $this->assertSame('AI diff prose.', Cache::store('array')->get($key));
        Http::assertSent(fn ($request) => str_contains((string) data_get($request->data(), 'contents.0.parts.0.text'), 'texture_quality'));
    }

    public function test_reverse_failure_returns_fallback_without_caching_it(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $diff = [[
            'setting' => 'texture_quality',
            'current' => 'low',
            'recommended' => 'ultra',
            'label' => 'low -> ultra',
        ]];
        $key = ExplanationCacheKey::reverse($diff, 'high', 'high', '32GB+', 'quality');
        $result = $this->generator()->reverse($diff, $this->recommendation, 'quality', 'static fallback');

        $this->assertSame('static fallback', $result);
        $this->assertNull(Cache::store('array')->get($key));
    }

    private function generator(): ExplanationGenerator
    {
        return new ExplanationGenerator(new GeminiClient());
    }

    private function candidate(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }
}
