<?php

namespace Tests\Unit\Services\RateLimiter;

use App\Exceptions\PcGamingWikiRateLimitException;
use App\Services\RateLimiter\PcGamingWikiLimiter;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class PcGamingWikiLimiterTest extends TestCase
{
    public function test_successful_reservation_runs_callback(): void
    {
        Redis::shouldReceive('eval')->once()->andReturn(0);

        $result = (new PcGamingWikiLimiter())->throttle(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_wait_ceiling_throws_rate_limit_exception(): void
    {
        Redis::shouldReceive('eval')->once()->andReturn(16000);

        $this->expectException(PcGamingWikiRateLimitException::class);

        (new PcGamingWikiLimiter())->throttle(fn () => 'never');
    }

    public function test_retry_after_delay_can_succeed(): void
    {
        $slept = [];
        Redis::shouldReceive('eval')->twice()->andReturn(2000, 0);

        $limiter = new PcGamingWikiLimiter(sleeper: function (int $milliseconds) use (&$slept): void {
            $slept[] = $milliseconds;
        });

        $this->assertSame('ok', $limiter->throttle(fn () => 'ok'));
        $this->assertSame([2000], $slept);
    }
}
