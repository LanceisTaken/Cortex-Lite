<?php

namespace Tests\Unit\Services\PcGamingWiki;

use App\Exceptions\PcGamingWikiApiException;
use App\Exceptions\PcGamingWikiRateLimitException;
use App\Services\PcGamingWikiClient;
use App\Services\RateLimiter\PcGamingWikiLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class PcGamingWikiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pcgamingwiki.contact_email', 'dev@example.com');
        config()->set('services.pcgamingwiki.cache_store', 'array');
        Cache::flush();
    }

    public function test_fetch_metadata_parses_a_cargo_hit(): void
    {
        Http::fake([
            'https://www.pcgamingwiki.com/*' => Http::sequence()
                ->push($this->infoboxPayload('Counter-Strike 2'))
                ->push($this->videoPayload([
                    'Upscaling' => 'Nvidia Deep Learning Super Sampling (DLSS)',
                'HDR' => 'Supported',
                    'Ultrawidescreen' => 'Native',
                'Ray_tracing' => 'false',
                ])),
        ]);

        $metadata = $this->client()->fetchMetadata(730);

        $this->assertNull($metadata['direct3d_versions']);
        $this->assertTrue($metadata['dlss_supported']);
        $this->assertFalse($metadata['fsr_supported']);
        $this->assertTrue($metadata['hdr_supported']);
        $this->assertTrue($metadata['ultrawide_supported']);
        $this->assertFalse($metadata['ray_tracing_supported']);
        $this->assertNull($metadata['vulkan_supported']);
        $this->assertArrayHasKey('raw_response', $metadata);
    }

    public function test_cache_hit_skips_http(): void
    {
        Cache::store('array')->put('pcgw:metadata:730', ['dlss_supported' => true], 60);
        Http::fake();

        $metadata = $this->client()->fetchMetadata(730);

        $this->assertSame(['dlss_supported' => true], $metadata);
        Http::assertNothingSent();
    }

    public function test_no_match_returns_null(): void
    {
        Http::fake(['https://www.pcgamingwiki.com/*' => Http::response(['cargoquery' => []])]);

        $this->assertNull($this->client()->fetchMetadata(730));
    }

    public function test_malformed_response_returns_null(): void
    {
        Http::fake(['https://www.pcgamingwiki.com/*' => Http::response(['unexpected' => []])]);

        $this->assertNull($this->client()->fetchMetadata(730));
    }

    public function test_no_match_is_cached_with_sentinel(): void
    {
        Http::fake(['https://www.pcgamingwiki.com/*' => Http::response(['cargoquery' => []])]);
        $client = $this->client();

        $this->assertNull($client->fetchMetadata(730));
        $this->assertNull($client->fetchMetadata(730));

        Http::assertSentCount(1);
    }

    public function test_429_throws_rate_limit_exception(): void
    {
        Http::fake(['https://www.pcgamingwiki.com/*' => Http::response([], 429)]);

        $this->expectException(PcGamingWikiRateLimitException::class);

        $this->client()->fetchMetadata(730);
    }

    public function test_500_throws_api_exception(): void
    {
        Http::fake(['https://www.pcgamingwiki.com/*' => Http::response([], 500)]);

        $this->expectException(PcGamingWikiApiException::class);

        $this->client()->fetchMetadata(730);
    }

    public function test_network_failure_throws_api_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('no route'));

        $this->expectException(PcGamingWikiApiException::class);

        $this->client()->fetchMetadata(730);
    }

    public function test_user_agent_includes_contact_email(): void
    {
        Http::fake(['https://www.pcgamingwiki.com/*' => Http::response(['cargoquery' => []])]);

        $this->client()->fetchMetadata(730);

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'Cortex-Lite/1.0 (contact: dev@example.com)'));
    }

    public function test_missing_contact_email_fails_fast(): void
    {
        config()->set('services.pcgamingwiki.contact_email', '');

        $this->expectException(InvalidArgumentException::class);

        $this->client();
    }

    public function test_raw_response_safety_limits_truncate_raw_payload_without_losing_parsed_fields(): void
    {
        $deep = ['one' => ['two' => ['three' => ['four' => ['five' => ['six' => ['seven' => ['eight' => ['nine' => 'deep']]]]]]]]];

        Http::fake([
            'https://www.pcgamingwiki.com/*' => Http::sequence()
                ->push($this->infoboxPayload('Deep Game'))
                ->push($this->videoPayload([
                    'Upscaling' => 'DLSS',
                    'Ultrawidescreen' => 'hackable',
                    'deep' => $deep,
                ])),
        ]);

        $metadata = $this->client()->fetchMetadata(730);

        $this->assertTrue($metadata['dlss_supported']);
        $this->assertFalse($metadata['ultrawide_supported']);
        $this->assertTrue($metadata['raw_response']['_truncated']);
        $this->assertSame(14, $metadata['raw_response']['depth']);
    }

    public function test_hackable_is_not_treated_as_native_support(): void
    {
        Http::fake([
            'https://www.pcgamingwiki.com/*' => Http::sequence()
                ->push($this->infoboxPayload('Hack Game'))
                ->push($this->videoPayload(['Ultrawidescreen' => 'Hackable'])),
        ]);

        $metadata = $this->client()->fetchMetadata(730);

        $this->assertFalse($metadata['ultrawide_supported']);
    }

    public function test_api_error_payload_throws_api_exception(): void
    {
        Http::fake(['https://www.pcgamingwiki.com/*' => Http::response([
            'error' => ['info' => 'No field named "DLSS" found.'],
        ])]);

        $this->expectException(PcGamingWikiApiException::class);

        $this->client()->fetchMetadata(730);
    }

    private function client(): PcGamingWikiClient
    {
        return new PcGamingWikiClient(new ImmediatePcGamingWikiLimiter());
    }

    private function infoboxPayload(string $page): array
    {
        return ['cargoquery' => [['title' => ['Page' => $page, 'Steam AppID' => '730']]]];
    }

    private function videoPayload(array $title): array
    {
        return ['cargoquery' => [['title' => $title]]];
    }
}

class ImmediatePcGamingWikiLimiter extends PcGamingWikiLimiter
{
    public function throttle(callable $fn): mixed
    {
        return $fn();
    }
}
