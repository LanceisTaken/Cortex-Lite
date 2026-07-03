<?php

namespace Tests\Unit\Services\PcGamingWiki;

use App\Services\PcGamingWikiClient;
use App\Services\RateLimiter\PcGamingWikiLimiter;
use Tests\TestCase;

class PcGamingWikiCacheKeyTest extends TestCase
{
    public function test_cache_key_is_deterministic_and_steam_app_id_only(): void
    {
        config()->set('services.pcgamingwiki.contact_email', 'dev@example.com');
        config()->set('services.pcgamingwiki.cache_store', 'array');

        $client = new PcGamingWikiClient(new class extends PcGamingWikiLimiter
        {
            public function throttle(callable $fn): mixed
            {
                return $fn();
            }
        });

        $this->assertSame('pcgw:metadata:730', $client->cacheKey(730));
        $this->assertSame($client->cacheKey(730), $client->cacheKey(730));
        $this->assertStringNotContainsString((string) now()->timestamp, $client->cacheKey(730));
    }
}
