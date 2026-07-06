# Phase 5 — LLM-Generated Explanation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an `ExplanationGenerator` that turns the *already-decided* forward-mode recommendation and reverse-mode settings diff into 3–4 sentences of Gemini-written prose, cached in Redis and fail-open to the existing deterministic static explanation.

**Architecture:** A thin `GeminiClient` wraps Google's Generative Language REST API via Laravel's `Http` facade (mirroring the existing `PcGamingWikiClient`). `ExplanationGenerator` orchestrates a Redis get → generate → put cycle keyed on the deterministic recommendation inputs, catches every failure and returns the caller-supplied static fallback (never caching a failure). Both `RecommendationController` and `ReverseController` already compute a deterministic fallback string and expose an `explanation` field; this plan swaps that inline string for a call through the generator, passing the existing string as the fallback.

**Tech Stack:** Laravel 13 / PHP 8.4, Laravel `Http` client (`Http::fake()` in tests), Redis cache, Google Generative Language API (`gemini-3.5-flash`, pinned via `GEMINI_MODEL`), PHPUnit.

## Global Constraints

- **The LLM never decides settings.** `ExplanationGenerator` receives already-computed structured input (settings/diff + tiers + goal) and returns prose only. `RecommendationEngine` and `SettingsDiffEngine` remain the sole source of truth. (CLAUDE.md rule 1.)
- **Cache keys are deterministic — no timestamps or request-unique values.** Forward: `(game_id, gpu_tier, cpu_tier, ram_bucket, goal)`. Reverse: `hash(diff_structure, gpu_tier, cpu_tier, ram_bucket, goal)`. Cache-key construction is unit-tested. A timestamp-in-key bug multiplies LLM cost 1000×. (CLAUDE.md rule 2.)
- **Cache successful LLM responses only.** Never cache the static fallback — a transient outage must not poison the cache. Use explicit `get`/`put`, not `Cache::remember()`.
- **Sync call, fail-open.** The LLM is called synchronously in the request. Any failure (timeout, non-2xx, empty candidates, missing key, connection error) returns the deterministic static explanation. The request must never fail because of the LLM. (Documented ADR: "Sync LLM call over async queue for v1".)
- **Provider is Gemini via `config('services.gemini.*')`.** Use `GEMINI_API_KEY` / `GEMINI_MODEL` — never `ANTHROPIC_*`. (See `docs/DECISIONS.md` → "Gemini API over Claude Haiku for explanation prose". Note: `CLAUDE.md` still names Claude Haiku and is stale on this point — flagged as a doc-hygiene follow-up in Task 6.)
- **Match existing idioms.** Follow `app/Services/PcGamingWikiClient.php` for the HTTP client shape and `tests/Unit/Services/PcGamingWiki/PcGamingWikiClientTest.php` for `Http::fake()` test style.

## Scope

**In scope:** the backend LLM explanation layer — `GeminiClient`, `ExplanationGenerator`, cache-key builder, wiring into the two existing endpoints, tests, and docs.

**Out of scope:** the optimizer **React UI** (hardware form + game search + goal selector + results/paste cards). Those pages do not exist yet (`client/src/pages/` has no Recommend/Reverse/Optimizer page) and span the forward-mode, reverse-mode, *and* explanation sub-sections equally — building them belongs in a dedicated optimizer-UI plan, consistent with the prior forward/reverse slices which shipped backend-only. This plan leaves the build-plan React-UI bullet unchecked and notes the deferral.

## File Structure

- Create `app/Exceptions/GeminiApiException.php` — typed failure for every Gemini error path (mirrors `PcGamingWikiApiException`).
- Create `app/Services/GeminiClient.php` — one public method `generate(string $prompt): string`; throws `GeminiApiException` on any failure.
- Create `app/Support/Recommendation/ExplanationCacheKey.php` — pure static `forward()` / `reverse()` key builders (isolated for the mandated cache-key unit test).
- Create `app/Services/ExplanationGenerator.php` — `forward()` / `reverse()`; cache-get → generate → cache-put; fail-open to fallback; deterministic prompt builders.
- Modify `config/services.php` — add `'cache_store'` to the existing `gemini` block.
- Modify `.env.example` — add `GEMINI_CACHE_STORE=redis` under the Gemini block.
- Modify `app/Http/Controllers/RecommendationController.php` — inject `ExplanationGenerator`, route the `explanation` field through it with the existing static string as fallback.
- Modify `app/Http/Controllers/ReverseController.php` — same, for the diff.
- Create `tests/Unit/Services/GeminiClientTest.php`
- Create `tests/Unit/Support/Recommendation/ExplanationCacheKeyTest.php`
- Create `tests/Unit/Services/ExplanationGeneratorTest.php`
- Modify `tests/Feature/Recommendations/RecommendEndpointTest.php` — add LLM-prose + fallback cases.
- Modify `tests/Feature/Recommendations/ReverseEndpointTest.php` — add LLM-prose + fallback cases.
- Modify `docs/DECISIONS.md`, `docs/TROUBLESHOOTING.md`, `docs/cortex-lite-build-plan.md`, `README.md`.

---

### Task 1: `GeminiClient` + `GeminiApiException` + config

**Files:**
- Create: `app/Exceptions/GeminiApiException.php`
- Create: `app/Services/GeminiClient.php`
- Modify: `config/services.php:49-52`
- Modify: `.env.example:81-83`
- Test: `tests/Unit/Services/GeminiClientTest.php`

**Interfaces:**
- Consumes: `config('services.gemini.api_key')`, `config('services.gemini.model')`.
- Produces:
  - `App\Exceptions\GeminiApiException extends RuntimeException`.
  - `App\Services\GeminiClient::generate(string $prompt): string` — returns trimmed candidate text; throws `GeminiApiException` on empty key, non-2xx, connection error, or missing/empty candidate text. Sends `x-goog-api-key` header, POSTs to `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/GeminiClientTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Exceptions\GeminiApiException;
use App\Services\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.5-flash');
    }

    private function candidate(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    public function test_generate_returns_trimmed_candidate_text(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->candidate('  Great settings.  ')),
        ]);

        $this->assertSame('Great settings.', (new GeminiClient())->generate('hi'));
    }

    public function test_generate_sends_api_key_header_and_model_in_url(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->candidate('ok')),
        ]);

        (new GeminiClient())->generate('hi');

        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key')
            && str_contains($request->url(), 'models/gemini-3.5-flash:generateContent'));
    }

    public function test_missing_api_key_throws_without_sending(): void
    {
        config()->set('services.gemini.api_key', '');
        Http::fake();

        try {
            (new GeminiClient())->generate('hi');
            $this->fail('Expected GeminiApiException.');
        } catch (GeminiApiException) {
            Http::assertNothingSent();
        }
    }

    public function test_non_2xx_throws(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $this->expectException(GeminiApiException::class);

        (new GeminiClient())->generate('hi');
    }

    public function test_connection_failure_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->expectException(GeminiApiException::class);

        (new GeminiClient())->generate('hi');
    }

    public function test_empty_candidates_throws(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => []])]);

        $this->expectException(GeminiApiException::class);

        (new GeminiClient())->generate('hi');
    }

    public function test_blank_candidate_text_throws(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->candidate('   '))]);

        $this->expectException(GeminiApiException::class);

        (new GeminiClient())->generate('hi');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make test` (or `make artisan CMD="test --filter=GeminiClientTest"`)
Expected: FAIL — `Class "App\Services\GeminiClient" not found`.

- [ ] **Step 3: Add config keys**

Edit `config/services.php` — replace the existing `gemini` block (lines 49–52) with:

```php
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'cache_store' => env('GEMINI_CACHE_STORE'),
    ],
```

Edit `.env.example` — replace the Gemini block (lines 81–83) with:

```
# --- Gemini API (Phase 5) ---
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.5-flash
GEMINI_CACHE_STORE=redis
```

- [ ] **Step 4: Write the exception class**

Create `app/Exceptions/GeminiApiException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class GeminiApiException extends RuntimeException {}
```

- [ ] **Step 5: Write the client**

Create `app/Services/GeminiClient.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\GeminiApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiClient
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const TIMEOUT_SECONDS = 10;

    private const MAX_OUTPUT_TOKENS = 300;

    private const TEMPERATURE = 0.2;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {}

    public function generate(string $prompt): string
    {
        $apiKey = $this->resolvedApiKey();

        if ($apiKey === '') {
            throw new GeminiApiException('GEMINI_API_KEY is not configured.');
        }

        $url = sprintf('%s/%s:generateContent', self::API_BASE, $this->resolvedModel());

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post($url, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => self::TEMPERATURE,
                        'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new GeminiApiException('Gemini request failed.', 0, $exception);
        }

        if (! $response->successful()) {
            throw new GeminiApiException('Gemini returned HTTP '.$response->status().'.');
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new GeminiApiException('Gemini returned no usable text.');
        }

        return trim($text);
    }

    private function resolvedApiKey(): string
    {
        return trim((string) ($this->apiKey ?? config('services.gemini.api_key')));
    }

    private function resolvedModel(): string
    {
        return (string) ($this->model ?? config('services.gemini.model', 'gemini-3.5-flash'));
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `make artisan CMD="test --filter=GeminiClientTest"`
Expected: PASS (7 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Exceptions/GeminiApiException.php app/Services/GeminiClient.php config/services.php .env.example tests/Unit/Services/GeminiClientTest.php
git commit -m "[Sprint 5] add GeminiClient for LLM explanation prose"
```

---

### Task 2: `ExplanationCacheKey` builder

**Files:**
- Create: `app/Support/Recommendation/ExplanationCacheKey.php`
- Test: `tests/Unit/Support/Recommendation/ExplanationCacheKeyTest.php`

**Interfaces:**
- Consumes: nothing (pure functions).
- Produces:
  - `ExplanationCacheKey::forward(int $gameId, string $gpuTier, string $cpuTier, string $ramBucket, string $goal): string` → `"llm:explain:forward:{gameId}:{gpuTier}:{cpuTier}:{ramBucket}:{goal}"`.
  - `ExplanationCacheKey::reverse(list<array{setting:string,current:string,recommended:string,label:string}> $diff, string $gpuTier, string $cpuTier, string $ramBucket, string $goal): string` → `"llm:explain:reverse:{sha256}"`, hashing the diff's `setting/current/recommended` triples (label excluded) plus the tiers and goal.

The builders are string-agnostic about the tier/bucket values — they concatenate/hash whatever is passed, so the unit test uses representative strings.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/Recommendation/ExplanationCacheKeyTest.php`:

```php
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
        $diffA = [['setting' => 'texture_quality', 'current' => 'ultra', 'recommended' => 'medium', 'label' => 'ultra → medium']];
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
            [['setting' => 'x', 'current' => 'a', 'recommended' => 'b', 'label' => 'a → b']],
            'high', 'high', '32GB+', 'quality',
        );
        $b = ExplanationCacheKey::reverse(
            [['setting' => 'x', 'current' => 'a', 'recommended' => 'c', 'label' => 'a → c']],
            'high', 'high', '32GB+', 'quality',
        );

        $this->assertNotSame($a, $b);
    }

    public function test_reverse_key_changes_with_hardware_tier(): void
    {
        $diff = [['setting' => 'x', 'current' => 'a', 'recommended' => 'b', 'label' => 'a → b']];

        $this->assertNotSame(
            ExplanationCacheKey::reverse($diff, 'high', 'high', '32GB+', 'quality'),
            ExplanationCacheKey::reverse($diff, 'low', 'high', '32GB+', 'quality'),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make artisan CMD="test --filter=ExplanationCacheKeyTest"`
Expected: FAIL — `Class "App\Support\Recommendation\ExplanationCacheKey" not found`.

- [ ] **Step 3: Write the builder**

Create `app/Support/Recommendation/ExplanationCacheKey.php`:

```php
<?php

namespace App\Support\Recommendation;

class ExplanationCacheKey
{
    public static function forward(int $gameId, string $gpuTier, string $cpuTier, string $ramBucket, string $goal): string
    {
        return sprintf('llm:explain:forward:%d:%s:%s:%s:%s', $gameId, $gpuTier, $cpuTier, $ramBucket, $goal);
    }

    /**
     * Reverse-mode key = hash(diff_structure, hardware tiers, goal). The diff's
     * label is derived from current/recommended and is excluded so it cannot
     * fragment the cache. No timestamps or request-unique values.
     *
     * @param  list<array{setting: string, current: string, recommended: string, label: string}>  $diff
     */
    public static function reverse(array $diff, string $gpuTier, string $cpuTier, string $ramBucket, string $goal): string
    {
        $structure = array_map(
            static fn (array $entry): array => [$entry['setting'], $entry['current'], $entry['recommended']],
            $diff,
        );

        $hash = hash('sha256', (string) json_encode([
            'diff' => $structure,
            'gpu_tier' => $gpuTier,
            'cpu_tier' => $cpuTier,
            'ram_bucket' => $ramBucket,
            'goal' => $goal,
        ]));

        return 'llm:explain:reverse:'.$hash;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make artisan CMD="test --filter=ExplanationCacheKeyTest"`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Recommendation/ExplanationCacheKey.php tests/Unit/Support/Recommendation/ExplanationCacheKeyTest.php
git commit -m "[Sprint 5] add deterministic LLM explanation cache-key builder"
```

---

### Task 3: `ExplanationGenerator` service

**Files:**
- Create: `app/Services/ExplanationGenerator.php`
- Test: `tests/Unit/Services/ExplanationGeneratorTest.php`

**Interfaces:**
- Consumes: `GeminiClient::generate()`, `ExplanationCacheKey::forward()/reverse()`, `config('services.gemini.cache_store')`, `config('cache.default')`.
- Produces:
  - `ExplanationGenerator::forward(array $recommendation, string $goal, int $gameId, string $fallback): string` — `$recommendation` is the full `RecommendationEngine::recommend()` array (`settings, source, gpu_tier, cpu_tier, ram_bucket, cpu_bottleneck`).
  - `ExplanationGenerator::reverse(array $diff, array $recommendation, string $goal, string $fallback): string` — `$diff` is the `SettingsComparator::compare()` list; `$recommendation` is the engine array.
  - Both: return the cached prose if present; else call Gemini, cache the success, return it; on `GeminiApiException` return `$fallback` **without caching**.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/ExplanationGeneratorTest.php`:

```php
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
    /** @var array<string, mixed> */
    private array $recommendation = [
        'settings' => ['texture_quality' => 'high', 'shadow_quality' => 'high'],
        'source' => 'heuristic',
        'gpu_tier' => 'high',
        'cpu_tier' => 'high',
        'ram_bucket' => '32GB+',
        'cpu_bottleneck' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.cache_store', 'array');
        Cache::flush();
    }

    private function generator(): ExplanationGenerator
    {
        return new ExplanationGenerator(new GeminiClient());
    }

    private function fakeProse(string $text): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $text]]]]],
            ]),
        ]);
    }

    private function forwardKey(): string
    {
        return ExplanationCacheKey::forward(1, 'high', 'high', '32GB+', 'quality');
    }

    public function test_forward_returns_prose_and_caches_it(): void
    {
        $this->fakeProse('LLM prose.');
        $generator = $this->generator();

        $this->assertSame('LLM prose.', $generator->forward($this->recommendation, 'quality', 1, 'FALLBACK'));
        $this->assertSame('LLM prose.', $generator->forward($this->recommendation, 'quality', 1, 'FALLBACK'));

        Http::assertSentCount(1);
    }

    public function test_forward_returns_fallback_and_does_not_cache_on_failure(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);
        $generator = $this->generator();

        $this->assertSame('FALLBACK', $generator->forward($this->recommendation, 'quality', 1, 'FALLBACK'));
        $this->assertSame('FALLBACK', $generator->forward($this->recommendation, 'quality', 1, 'FALLBACK'));

        Http::assertSentCount(2);
        $this->assertNull(Cache::store('array')->get($this->forwardKey()));
    }

    public function test_forward_cache_hit_skips_the_client(): void
    {
        Cache::store('array')->put($this->forwardKey(), 'cached prose', 60);
        Http::fake();

        $this->assertSame('cached prose', $this->generator()->forward($this->recommendation, 'quality', 1, 'FALLBACK'));

        Http::assertNothingSent();
    }

    public function test_missing_api_key_returns_fallback_without_sending(): void
    {
        config()->set('services.gemini.api_key', '');
        Http::fake();

        $this->assertSame('FALLBACK', $this->generator()->forward($this->recommendation, 'quality', 1, 'FALLBACK'));

        Http::assertNothingSent();
    }

    public function test_reverse_returns_prose(): void
    {
        $this->fakeProse('Reverse prose.');
        $diff = [['setting' => 'texture_quality', 'current' => 'ultra', 'recommended' => 'high', 'label' => 'ultra → high']];

        $this->assertSame('Reverse prose.', $this->generator()->reverse($diff, $this->recommendation, 'quality', 'FALLBACK'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make artisan CMD="test --filter=ExplanationGeneratorTest"`
Expected: FAIL — `Class "App\Services\ExplanationGenerator" not found`.

- [ ] **Step 3: Write the service**

Create `app/Services/ExplanationGenerator.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\GeminiApiException;
use App\Support\Recommendation\ExplanationCacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExplanationGenerator
{
    private const CACHE_TTL_SECONDS = 2592000; // 30 days — prose is stable per (game, hardware, goal).

    public function __construct(private readonly GeminiClient $client) {}

    /**
     * @param  array{settings: array<string, mixed>, source: string, gpu_tier: string, cpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}  $recommendation
     */
    public function forward(array $recommendation, string $goal, int $gameId, string $fallback): string
    {
        $key = ExplanationCacheKey::forward(
            $gameId,
            $recommendation['gpu_tier'],
            $recommendation['cpu_tier'],
            $recommendation['ram_bucket'],
            $goal,
        );

        return $this->remember(
            $key,
            fn (): string => $this->client->generate($this->forwardPrompt($recommendation, $goal)),
            $fallback,
        );
    }

    /**
     * @param  list<array{setting: string, current: string, recommended: string, label: string}>  $diff
     * @param  array{gpu_tier: string, cpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}  $recommendation
     */
    public function reverse(array $diff, array $recommendation, string $goal, string $fallback): string
    {
        $key = ExplanationCacheKey::reverse(
            $diff,
            $recommendation['gpu_tier'],
            $recommendation['cpu_tier'],
            $recommendation['ram_bucket'],
            $goal,
        );

        return $this->remember(
            $key,
            fn (): string => $this->client->generate($this->reversePrompt($diff, $recommendation, $goal)),
            $fallback,
        );
    }

    /**
     * Cache only successful LLM responses. On any failure, return the deterministic
     * fallback WITHOUT caching, so a transient outage never poisons the cache.
     *
     * @param  callable(): string  $generate
     */
    private function remember(string $key, callable $generate, string $fallback): string
    {
        $store = Cache::store($this->cacheStore());

        $cached = $store->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        try {
            $prose = $generate();
        } catch (GeminiApiException $exception) {
            Log::warning('Gemini explanation failed; serving static fallback.', [
                'message' => $exception->getMessage(),
            ]);

            return $fallback;
        }

        $store->put($key, $prose, self::CACHE_TTL_SECONDS);

        return $prose;
    }

    private function cacheStore(): string
    {
        return (string) (config('services.gemini.cache_store') ?: config('cache.default'));
    }

    /**
     * @param  array{settings: array<string, mixed>, gpu_tier: string, cpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}  $recommendation
     */
    private function forwardPrompt(array $recommendation, string $goal): string
    {
        $settings = (string) json_encode($recommendation['settings'], JSON_PRETTY_PRINT);
        $bottleneck = $recommendation['cpu_bottleneck'] ? 'yes' : 'no';

        return <<<PROMPT
            You are a PC gaming graphics-settings assistant. A deterministic rule-based engine has already chosen the settings below. Write a short, friendly explanation of why they suit this player: 3-4 sentences, plain prose, no lists, no markdown. Do NOT change, add, remove, or second-guess any value — explain only the settings exactly as given.

            Goal: {$goal}
            GPU tier: {$recommendation['gpu_tier']}
            CPU tier: {$recommendation['cpu_tier']}
            RAM bucket: {$recommendation['ram_bucket']}
            CPU bottleneck: {$bottleneck}
            Chosen settings (JSON):
            {$settings}
            PROMPT;
    }

    /**
     * @param  list<array{setting: string, current: string, recommended: string, label: string}>  $diff
     * @param  array{gpu_tier: string, cpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}  $recommendation
     */
    private function reversePrompt(array $diff, array $recommendation, string $goal): string
    {
        $bottleneck = $recommendation['cpu_bottleneck'] ? 'yes' : 'no';
        $changes = $diff === []
            ? '(none — the pasted settings already match the recommendation)'
            : implode("\n", array_map(
                static fn (array $entry): string => "- {$entry['setting']}: {$entry['label']}",
                $diff,
            ));

        return <<<PROMPT
            You are a PC gaming graphics-settings assistant. The player pasted their current settings; a deterministic rule-based engine computed the exact changes needed to reach the recommended {$goal} configuration. Write a short, friendly explanation of why each change helps: 3-4 sentences, plain prose, no lists, no markdown. Do NOT invent or alter any change — explain only the changes listed.

            Goal: {$goal}
            GPU tier: {$recommendation['gpu_tier']}
            CPU tier: {$recommendation['cpu_tier']}
            RAM bucket: {$recommendation['ram_bucket']}
            CPU bottleneck: {$bottleneck}
            Changes ("current → recommended"):
            {$changes}
            PROMPT;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make artisan CMD="test --filter=ExplanationGeneratorTest"`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ExplanationGenerator.php tests/Unit/Services/ExplanationGeneratorTest.php
git commit -m "[Sprint 5] add ExplanationGenerator with success-only Redis caching and fail-open fallback"
```

---

### Task 4: Wire `ExplanationGenerator` into `RecommendationController`

**Files:**
- Modify: `app/Http/Controllers/RecommendationController.php:14-36`
- Test: `tests/Feature/Recommendations/RecommendEndpointTest.php`

**Interfaces:**
- Consumes: `ExplanationGenerator::forward()`. The existing `fallbackExplanation()` private method stays and supplies the fallback string.
- Produces: `POST /api/recommend` response `data.explanation` is now Gemini prose when configured, the existing static string otherwise. The response envelope is otherwise unchanged.

- [ ] **Step 1: Write the failing test**

Add these methods to `tests/Feature/Recommendations/RecommendEndpointTest.php` (and add `use Illuminate\Support\Facades\Http;` to the imports):

```php
    public function test_gemini_prose_is_returned_when_configured(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.cache_store', 'array');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'AI-written explanation.']]]]],
            ]),
        ]);

        [$user, $payload] = $this->scenario();

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertOk()
            ->assertJsonPath('data.explanation', 'AI-written explanation.');
    }

    public function test_falls_back_to_static_explanation_without_gemini_key(): void
    {
        config()->set('services.gemini.api_key', '');
        Http::fake();

        [$user, $payload] = $this->scenario();

        $response = $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertOk();

        $this->assertStringContainsString('heuristic engine', (string) $response->json('data.explanation'));
        Http::assertNothingSent();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make artisan CMD="test --filter=RecommendEndpointTest"`
Expected: FAIL — `test_gemini_prose_is_returned_when_configured` fails because `data.explanation` is still the static string, not the faked prose.

- [ ] **Step 3: Wire the generator into the controller**

Edit `app/Http/Controllers/RecommendationController.php`. Add the import and change the `store()` signature + the `explanation` line:

```php
use App\Http\Requests\Recommendations\RecommendRequest;
use App\Models\Cpu;
use App\Models\Gpu;
use App\Services\ExplanationGenerator;
use App\Services\RecommendationEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
```

```php
    public function store(RecommendRequest $request, RecommendationEngine $engine, ExplanationGenerator $explanations): JsonResponse
    {
        try {
            $game = $request->user()->games()->findOrFail($request->validated('game_id'));
        } catch (ModelNotFoundException) {
            return response()->json(null, 404);
        }

        $gpu = Gpu::query()->findOrFail($request->validated('gpu_id'));
        $cpu = Cpu::query()->findOrFail($request->validated('cpu_id'));
        $goal = $request->validated('goal');

        $result = $engine->recommend($game, $gpu, $cpu, (int) $request->validated('ram_gb'), $goal);
        $fallback = $this->fallbackExplanation($result, $goal);

        return response()->json([
            'data' => [
                'game_id' => $game->id,
                'goal' => $goal,
                ...$result,
                'explanation' => $explanations->forward($result, $goal, $game->id, $fallback),
            ],
        ]);
    }
```

Leave the `fallbackExplanation()` private method unchanged.

- [ ] **Step 4: Run tests to verify they pass**

Run: `make artisan CMD="test --filter=RecommendEndpointTest"`
Expected: PASS (all existing tests plus the two new ones).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/RecommendationController.php tests/Feature/Recommendations/RecommendEndpointTest.php
git commit -m "[Sprint 5] serve Gemini prose from POST /api/recommend with static fallback"
```

---

### Task 5: Wire `ExplanationGenerator` into `ReverseController`

**Files:**
- Modify: `app/Http/Controllers/ReverseController.php:14-43`
- Test: `tests/Feature/Recommendations/ReverseEndpointTest.php`

**Interfaces:**
- Consumes: `ExplanationGenerator::reverse()`. The existing `fallbackExplanation()` supplies the fallback string.
- Produces: `POST /api/reverse` response `data.explanation` is Gemini prose when configured, the existing static string otherwise. Envelope otherwise unchanged.

- [ ] **Step 1: Write the failing test**

Add these methods to `tests/Feature/Recommendations/ReverseEndpointTest.php` (and add `use Illuminate\Support\Facades\Http;` to the imports):

```php
    public function test_gemini_prose_is_returned_when_configured(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.cache_store', 'array');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'AI diff explanation.']]]]],
            ]),
        ]);

        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium'],
            ['texture_quality' => 'ultra'],
        );

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertOk()
            ->assertJsonPath('data.explanation', 'AI diff explanation.');
    }

    public function test_falls_back_to_static_explanation_without_gemini_key(): void
    {
        config()->set('services.gemini.api_key', '');
        Http::fake();

        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium'],
            ['texture_quality' => 'ultra'],
        );

        $response = $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertOk();

        $this->assertStringContainsString('align your settings', (string) $response->json('data.explanation'));
        Http::assertNothingSent();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make artisan CMD="test --filter=ReverseEndpointTest"`
Expected: FAIL — `test_gemini_prose_is_returned_when_configured` fails because `data.explanation` is still the static string.

- [ ] **Step 3: Wire the generator into the controller**

Edit `app/Http/Controllers/ReverseController.php`. Add the import and change the `store()` signature + the `explanation` line:

```php
use App\Http\Requests\Recommendations\ReverseRequest;
use App\Models\Cpu;
use App\Models\Gpu;
use App\Services\ExplanationGenerator;
use App\Services\SettingsDiffEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
```

```php
    public function store(ReverseRequest $request, SettingsDiffEngine $engine, ExplanationGenerator $explanations): JsonResponse
    {
        try {
            $game = $request->user()->games()->findOrFail($request->validated('game_id'));
        } catch (ModelNotFoundException) {
            return response()->json(null, 404);
        }

        $gpu = Gpu::query()->findOrFail($request->validated('gpu_id'));
        $cpu = Cpu::query()->findOrFail($request->validated('cpu_id'));
        $goal = $request->validated('goal');

        $result = $engine->diff(
            $game,
            $gpu,
            $cpu,
            (int) $request->validated('ram_gb'),
            $goal,
            $request->validated('current_settings'),
        );

        $fallback = $this->fallbackExplanation($result['diff'], $result['recommendation'], $goal);

        return response()->json([
            'data' => [
                'game_id' => $game->id,
                'goal' => $goal,
                ...$result,
                'explanation' => $explanations->reverse($result['diff'], $result['recommendation'], $goal, $fallback),
            ],
        ]);
    }
```

Leave the `fallbackExplanation()` private method unchanged.

- [ ] **Step 4: Run tests to verify they pass**

Run: `make artisan CMD="test --filter=ReverseEndpointTest"`
Expected: PASS (all existing tests plus the two new ones).

> Note on the fallback assertion: the existing `test_returns_the_diff_and_explanation` scenario produces a non-empty diff, so the static fallback contains "align your settings with the {goal} recommendation" (see `ReverseController::fallbackExplanation()`). The new fallback test reuses a non-empty-diff scenario, so `align your settings` is present.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ReverseController.php tests/Feature/Recommendations/ReverseEndpointTest.php
git commit -m "[Sprint 5] serve Gemini prose from POST /api/reverse with static fallback"
```

---

### Task 6: Documentation

**Files:**
- Modify: `docs/DECISIONS.md`
- Modify: `docs/TROUBLESHOOTING.md`
- Modify: `docs/cortex-lite-build-plan.md:268-274`
- Modify: `README.md` (sprint changelog section)

**Interfaces:** none (docs only). No test cycle; verified by `git diff --check` and a manual read.

- [ ] **Step 1: Add the DECISIONS.md entry**

Append to `docs/DECISIONS.md`:

```markdown
### LLM explanation caching: success-only, keyed by deterministic recommendation inputs
**Date:** 2026-07-06
**Decision:** `ExplanationGenerator` caches Gemini prose in Redis keyed by the deterministic recommendation inputs — forward: `(game_id, gpu_tier, cpu_tier, ram_bucket, goal)`; reverse: `sha256(diff_structure, gpu_tier, cpu_tier, ram_bucket, goal)` (the diff's derived `label` is excluded). Only successful responses are cached (explicit `get`/`put`, not `remember`); on any Gemini failure the deterministic static explanation is returned and NOT cached. The Gemini call is synchronous with a 10s timeout.
**Rationale:** The prose is a pure function of the structured input, so keying on those inputs gives a stable, high-hit-rate cache with no timestamp/request-unique component (the classic 1000×-cost bug). Caching only successes means a transient outage degrades to the static string without poisoning the cache for the 30-day TTL. Sync keeps the request path simple (see "Sync LLM call over async queue for v1"); fail-open keeps the LLM strictly non-load-bearing (see "LLM scoped to prose only").
**Alternatives considered:** `Cache::remember()` wrapping the call (rejected — it would cache the fallback on failure). Keying forward mode on `steam_app_id`/title for cross-user cache sharing (rejected for v1 — the build-plan/CLAUDE.md tuple specifies `game_id`; per-user fragmentation is an accepted cost cap, and the anchor catalog already shares across users at the recommendation layer). Async queue + polling (rejected — marginal UX gain for a ~2–3s cold call).
**Consequences:** Cache-key construction is unit-tested (`ExplanationCacheKeyTest`) to lock out timestamp regressions. Forward-mode cache is per-user (each user's copy of a game has a distinct `game_id`); acceptable given the per-user rolling quota. The static fallback string doubles as the LLM prompt-failure path and remains the deterministic source of truth.
```

- [ ] **Step 2: Add the TROUBLESHOOTING.md entry**

Append to `docs/TROUBLESHOOTING.md`:

```markdown
### Recommendation/reverse `explanation` is the terse static string, not AI prose
**Cause:** `ExplanationGenerator` failed open. Either `GEMINI_API_KEY` is unset (the client throws before any network call), or the Gemini API timed out / returned a non-2xx / returned no candidate text. All of these are caught and logged as `Gemini explanation failed; serving static fallback.` and the deterministic static explanation is returned instead — by design, the LLM never fails the request.
**Fix:** Confirm `GEMINI_API_KEY` is set and `GEMINI_MODEL` is a valid model. Check `storage/logs` for the `Gemini explanation failed` warning and its `message`. Verify egress to `generativelanguage.googleapis.com`. Once a call succeeds it is cached in Redis for 30 days under `llm:explain:*`; flush that prefix if you need to re-test after fixing a bad key.
```

- [ ] **Step 3: Update the build plan checkboxes**

In `docs/cortex-lite-build-plan.md`, under `### LLM-generated explanation` (lines ~268–275), check every bullet **except** the React UI bullet, and annotate the React UI bullet:

- `[x]` Choose provider: Gemini API…
- `[x]` Build an `ExplanationGenerator` service used by both modes…
- `[x]` Prompt design — the LLM never decides settings…
- `[x]` Cache LLM responses in Redis… Unit-test the cache key construction…
- `[x]` Handle LLM API failures gracefully…
- `[x]` Decide sync vs async for the LLM call… (sync for v1)
- `[ ]` React UI: … — **Deferred to a dedicated optimizer-UI plan (no Recommend/Reverse page exists yet; the UI spans forward, reverse, and explanation equally).**

- [ ] **Step 4: Update the README sprint changelog**

Add a line to the Phase 5 entry in the README sprint changelog section:

```markdown
- **AI explanations (Gemini).** Forward recommendations and reverse-mode diffs are explained in natural-language prose by `gemini-3.5-flash`, cached in Redis by deterministic inputs, with a deterministic static fallback so the LLM can never fail or alter a recommendation.
```

- [ ] **Step 5: Verify and commit**

Run: `git diff --check`
Expected: no whitespace errors.

```bash
git add docs/DECISIONS.md docs/TROUBLESHOOTING.md docs/cortex-lite-build-plan.md README.md
git commit -m "[Sprint 5] document LLM explanation caching, fallback, and provider"
```

---

## Final verification

- [ ] Run the full suite: `make test`
- [ ] Expected: all prior tests still pass (234+ from the forward-mode slice) plus the new `GeminiClientTest` (7), `ExplanationCacheKeyTest` (4), `ExplanationGeneratorTest` (5), and the 2 new methods in each of `RecommendEndpointTest` / `ReverseEndpointTest`. No new network calls (all Gemini traffic is `Http::fake()`d; unconfigured-key paths never hit the network).
- [ ] `git diff --check` → clean.

## Follow-ups (out of scope for this plan)

1. **Optimizer React UI** — forward form (hardware autocomplete + game search + goal selector), results card with the settings table + explanation, and the reverse-mode paste box + diff table + explanation. Spans all three Phase 5 optimizer sub-sections; warrants its own plan.
2. **CLAUDE.md doc hygiene** — `CLAUDE.md` still names "Claude Haiku (`claude-haiku-4-5-20251001`)" in the project identity, stack reference, and rule 1. The project deliberately switched to Gemini (see today's DECISIONS ADR). Update those CLAUDE.md references to Gemini so the persistent context stops contradicting the code.

## Self-review notes

- **Spec coverage:** every bullet of the build plan's `### LLM-generated explanation` section maps to a task — provider (Task 1/config + Task 6 ADR), `ExplanationGenerator` (Task 3), prompt-only constraint (Task 3 prompts + Task 6 ADR), Redis cache + unit-tested key (Task 2 + Task 3), graceful failure (Task 3 + Task 6 TROUBLESHOOTING), sync vs async (Task 3 + Task 6 ADR). The React UI bullet is explicitly deferred with rationale.
- **Type consistency:** `ExplanationCacheKey::forward/reverse` signatures match their call sites in `ExplanationGenerator`; `forward(array $recommendation, string $goal, int $gameId, string $fallback)` and `reverse(array $diff, array $recommendation, string $goal, string $fallback)` match the controller call sites; the `$recommendation` array keys used (`gpu_tier`, `cpu_tier`, `ram_bucket`, `cpu_bottleneck`, `settings`, `source`) match `RecommendationEngine::recommend()`'s documented return shape; the diff entry keys (`setting`, `current`, `recommended`, `label`) match `SettingsComparator::compare()`.
- **No network in tests:** unconfigured `GEMINI_API_KEY` short-circuits in `GeminiClient` before any `Http` call, so pre-existing endpoint tests (which set no key) return the static fallback with zero network traffic; the test cache store resolves to `array` via `config('cache.default')` under `phpunit.xml`'s `CACHE_STORE=array`.
