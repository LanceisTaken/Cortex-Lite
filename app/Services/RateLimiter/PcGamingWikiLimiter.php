<?php

namespace App\Services\RateLimiter;

use App\Exceptions\PcGamingWikiRateLimitException;
use Illuminate\Support\Facades\Redis;

class PcGamingWikiLimiter
{
    private const LUA = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local capacity = tonumber(ARGV[2])
local refill_ms = tonumber(ARGV[3])
local ttl_ms = tonumber(ARGV[4])

local bucket = redis.call('HMGET', key, 'tokens', 'refreshed_at')
local tokens = tonumber(bucket[1])
local refreshed_at = tonumber(bucket[2])

if tokens == nil then
  tokens = capacity
  refreshed_at = now
end

local elapsed = math.max(0, now - refreshed_at)
local refill = math.floor(elapsed / refill_ms)

if refill > 0 then
  tokens = math.min(capacity, tokens + refill)
  refreshed_at = refreshed_at + (refill * refill_ms)
end

if tokens < 1 then
  redis.call('HMSET', key, 'tokens', tokens, 'refreshed_at', refreshed_at)
  redis.call('PEXPIRE', key, ttl_ms)
  return math.max(1, refill_ms - (now - refreshed_at))
end

tokens = tokens - 1
redis.call('HMSET', key, 'tokens', tokens, 'refreshed_at', refreshed_at)
redis.call('PEXPIRE', key, ttl_ms)
return 0
LUA;

    public function __construct(
        private readonly int $capacity = 30,
        private readonly int $refillMilliseconds = 2000,
        private readonly int $maxWaitMilliseconds = 15000,
        private readonly string $key = 'pcgw:rate:metadata',
        private readonly mixed $clock = null,
        private readonly mixed $sleeper = null,
    ) {
    }

    public function throttle(callable $fn): mixed
    {
        $waited = 0;

        while (true) {
            $delay = $this->reserve();

            if ($delay === 0) {
                return $fn();
            }

            if (($waited + $delay) > $this->maxWaitMilliseconds) {
                throw new PcGamingWikiRateLimitException('PCGamingWiki rate limit wait ceiling exceeded.');
            }

            $this->sleep($delay);
            $waited += $delay;
        }
    }

    public function reserve(): int
    {
        $result = Redis::eval(self::LUA, 1, $this->key, $this->nowMilliseconds(), $this->capacity, $this->refillMilliseconds, 120000);

        return (int) $result;
    }

    private function nowMilliseconds(): int
    {
        if (is_callable($this->clock)) {
            return (int) call_user_func($this->clock);
        }

        return (int) floor(microtime(true) * 1000);
    }

    private function sleep(int $milliseconds): void
    {
        if (is_callable($this->sleeper)) {
            call_user_func($this->sleeper, $milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }
}
