# Forward-Mode Recommendation Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the deterministic forward-mode `RecommendationEngine` and its `POST /api/recommend` endpoint, turning a (game, GPU, CPU, RAM, goal) request into a structured settings payload calibrated by anchor presets and the heuristic recommender.

**Architecture:** A new `RecommendationEngine` service orchestrates the existing deterministic pieces: it resolves GPU/CPU tiers and a RAM bucket, prefers a curated `setting_presets` anchor for the exact `(game, gpu_tier, goal)` tuple, and otherwise falls through to the existing `HeuristicRecommender` masked by the game's PCGamingWiki capability metadata. It then applies a deterministic RAM texture-pool adjustment and flags CPU bottlenecks. A thin controller scopes the game to the authenticated user (IDOR-safe) and returns the settings JSON plus a **deterministic static `explanation`** — the LLM-generated explanation is a separate, later section of Phase 5 and will replace this fallback string.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8.4 (SQLite in tests), PHPUnit, Sanctum SPA auth.

## Global Constraints

- **The LLM never decides settings.** This entire engine is deterministic. No Gemini/LLM call appears anywhere in this plan. (CLAUDE.md rule 1.)
- **The recommendation engine is deterministic:** identical inputs must always produce byte-identical output. (Build plan: "The recommendation engine is deterministic.")
- **Authorization + IDOR on every resource endpoint.** `POST /api/recommend` must scope `game_id` to the authenticated user; User A passing User B's `game_id` returns 404, never 200. (CLAUDE.md testing expectations.)
- **Sanctum SPA auth only** — the endpoint sits behind `auth:sanctum`. (CLAUDE.md rule 3.)
- **Free-tier quota gating is OUT OF SCOPE for this plan.** The rolling-30-day-window quota, `usage_events`, and Stripe gating are a separate later section of Phase 5. Do not add usage logging or quota checks here.
- **No new DB tables or migrations.** This slice consumes existing tables (`games`, `gpus`, `cpus`, `setting_presets`, `game_metadata`) only.
- **Sprint-tagged commits:** every commit message is prefixed `[Sprint 5] `.
- **Dev commands go through `make`** — `make test`, `make artisan CMD="..."`. Never call raw `php`/`docker`.

## Scope boundary (read before starting)

This plan implements exactly the build-plan sub-section **"Forward-mode recommendation engine"** (`docs/cortex-lite-build-plan.md`, Phase 5). It deliberately stops at a deterministic static `explanation` string. Three neighbouring Phase-5 sub-sections are **separate plans** and must not be pulled in here:
- **LLM-generated explanation** (`ExplanationGenerator` + Redis LLM cache) — will replace the static explanation.
- **Reverse mode** (`SettingsDiffEngine`).
- **Stripe premium gating** (rolling-window quota).

The static `explanation` built here doubles as the documented fallback the LLM section returns on API failure (`TROUBLESHOOTING.md`: "LLM API timeout fallback — return recommendation with a static explanation"), so it is not throwaway work.

## File Structure

- `app/Support/Recommendation/RamBucketClassifier.php` — **new.** Pure static classifier mapping `ram_gb` → a stable bucket token. Mirrors the existing `app/Support/Hardware/*TierClassifier` pattern. One responsibility: RAM bucketing with documented boundaries.
- `app/Services/RecommendationEngine.php` — **new.** The orchestrator. Depends on `HeuristicRecommender` (constructor-injected) and reads `SettingPreset` / `Game->metadata`. Returns a structured array. No HTTP, no auth concerns.
- `app/Http/Requests/Recommendations/RecommendRequest.php` — **new.** Form Request validating the five inputs.
- `app/Http/Controllers/RecommendationController.php` — **new.** Scopes the game to the user (IDOR), resolves GPU/CPU, calls the engine, attaches the static explanation, returns JSON.
- `routes/api.php` — **modify.** Add `POST /api/recommend` inside the existing `auth:sanctum` group.
- `tests/Unit/Support/Recommendation/RamBucketClassifierTest.php` — **new.** Boundary tests (pure, no DB).
- `tests/Feature/Recommendations/RecommendationEngineTest.php` — **new.** DB-backed engine tests (anchor hit, heuristic fallback, RAM clamp, CPU bottleneck, determinism, title-match fallback).
- `tests/Feature/Recommendations/RecommendEndpointTest.php` — **new.** HTTP tests (auth, validation, IDOR, anchor/heuristic happy paths, response shape).

---

### Task 1: RAM bucket classifier

**Files:**
- Create: `app/Support/Recommendation/RamBucketClassifier.php`
- Test: `tests/Unit/Support/Recommendation/RamBucketClassifierTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `RamBucketClassifier::classify(int $ramGb): string` returning one of the class constants `UNDER_16GB = 'under_16gb'`, `MID_16_TO_31GB = '16_to_31gb'`, `AT_LEAST_32GB = '32gb_plus'`. `RecommendationEngine` (Task 2) uses both the method and the `UNDER_16GB` constant.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/Recommendation/RamBucketClassifierTest.php`:

```php
<?php

namespace Tests\Unit\Support\Recommendation;

use App\Support\Recommendation\RamBucketClassifier;
use PHPUnit\Framework\TestCase;

class RamBucketClassifierTest extends TestCase
{
    public function test_below_16gb_is_the_under_bucket(): void
    {
        $this->assertSame(RamBucketClassifier::UNDER_16GB, RamBucketClassifier::classify(8));
        $this->assertSame(RamBucketClassifier::UNDER_16GB, RamBucketClassifier::classify(15));
    }

    public function test_16_to_31gb_is_the_mid_bucket(): void
    {
        $this->assertSame(RamBucketClassifier::MID_16_TO_31GB, RamBucketClassifier::classify(16));
        $this->assertSame(RamBucketClassifier::MID_16_TO_31GB, RamBucketClassifier::classify(31));
    }

    public function test_32gb_and_above_is_the_top_bucket(): void
    {
        $this->assertSame(RamBucketClassifier::AT_LEAST_32GB, RamBucketClassifier::classify(32));
        $this->assertSame(RamBucketClassifier::AT_LEAST_32GB, RamBucketClassifier::classify(128));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make artisan CMD="test --filter=RamBucketClassifierTest"`
Expected: FAIL — `Class "App\Support\Recommendation\RamBucketClassifier" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `app/Support/Recommendation/RamBucketClassifier.php`:

```php
<?php

namespace App\Support\Recommendation;

class RamBucketClassifier
{
    public const UNDER_16GB = 'under_16gb';

    public const MID_16_TO_31GB = '16_to_31gb';

    public const AT_LEAST_32GB = '32gb_plus';

    public static function classify(int $ramGb): string
    {
        return match (true) {
            $ramGb < 16 => self::UNDER_16GB,
            $ramGb < 32 => self::MID_16_TO_31GB,
            default => self::AT_LEAST_32GB,
        };
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make artisan CMD="test --filter=RamBucketClassifierTest"`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Recommendation/RamBucketClassifier.php tests/Unit/Support/Recommendation/RamBucketClassifierTest.php
git commit -m "[Sprint 5] add RAM bucket classifier"
```

---

### Task 2: RecommendationEngine service

**Files:**
- Create: `app/Services/RecommendationEngine.php`
- Test: `tests/Feature/Recommendations/RecommendationEngineTest.php`

**Interfaces:**
- Consumes:
  - `RamBucketClassifier::classify(int): string` and `RamBucketClassifier::UNDER_16GB` (Task 1).
  - `HeuristicRecommender::recommend(string $gpuTier, string $goal, array $capabilities = []): array` (existing, `app/Services/HeuristicRecommender.php`). Returns keys `resolution_scale, upscaling, ray_tracing, shadow_quality, texture_quality, anti_aliasing, ambient_occlusion`.
  - Existing models: `Game` (has `steam_app_id`, `title`, and `metadata(): HasOne GameMetadata`), `Gpu`/`Cpu` (both have a `tier` string in `{low,mid,high,enthusiast}`), `SettingPreset` (columns `game`, `steam_app_id`, `goal`, `gpu_tier`, `settings` cast to array), `GameMetadata` (boolean `dlss_supported`, `fsr_supported`, `ray_tracing_supported`).
- Produces: `RecommendationEngine::recommend(Game $game, Gpu $gpu, Cpu $cpu, int $ramGb, string $goal): array` returning:
  ```
  [
    'settings'       => array<string,mixed>, // anchor blob verbatim, or heuristic output, after RAM adjustment
    'source'         => 'anchor'|'heuristic',
    'gpu_tier'       => string,
    'cpu_tier'       => string,
    'ram_bucket'     => string, // a RamBucketClassifier token
    'cpu_bottleneck' => bool,
  ]
  ```
  `RecommendationController` (Task 3) consumes this array.

**Design notes (deterministic behaviour to implement):**
- **Anchor match key:** `(goal, gpu_tier)` plus game identity. Prefer `steam_app_id` when the game has one; otherwise match on `game` title. `setting_presets` is keyed by `steam_app_id`/`game`, **not** the user's `games.id`, so the engine resolves through the game's own Steam App ID / title.
- **Heuristic fallback capabilities:** read from `Game->metadata`; every flag defaults to `false` when metadata is absent (fail-safe to unsupported, matching the existing heuristic contract).
- **RAM adjustment:** only when the bucket is `UNDER_16GB` — clamp `texture_quality` down one ordinal level (`ultra→high→medium→low`) *if* the current value is a recognised ordinal. This is the deterministic realisation of the build plan's "if RAM < 16GB, force lower texture pool". Anchor blobs without a `texture_quality` key are left untouched.
- **CPU bottleneck flag:** true when the GPU tier outranks the CPU tier by 2+ steps (ranks `low=0,mid=1,high=2,enthusiast=3`). The engine surfaces the flag; it does not mutate unrelated settings for the CPU (kept minimal and defensible — documented in Task 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Recommendations/RecommendationEngineTest.php`:

```php
<?php

namespace Tests\Feature\Recommendations;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\GameMetadata;
use App\Models\Gpu;
use App\Models\SettingPreset;
use App\Models\User;
use App\Services\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): RecommendationEngine
    {
        return app(RecommendationEngine::class);
    }

    public function test_uses_anchor_preset_when_one_matches_by_steam_app_id(): void
    {
        $game = Game::factory()->for(User::factory())->create([
            'steam_app_id' => 1091500,
            'title' => 'Cyberpunk 2077',
        ]);
        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        $preset = SettingPreset::factory()->create([
            'game' => 'Cyberpunk 2077',
            'steam_app_id' => 1091500,
            'goal' => 'quality',
            'gpu_tier' => 'high',
            'settings' => ['upscaling' => 'DLSS Quality mode', 'shadow_quality' => 'ultra'],
        ]);

        $result = $this->engine()->recommend($game, $gpu, $cpu, 32, 'quality');

        $this->assertSame('anchor', $result['source']);
        $this->assertSame($preset->settings, $result['settings']);
        $this->assertSame('high', $result['gpu_tier']);
        $this->assertSame('high', $result['cpu_tier']);
    }

    public function test_matches_anchor_by_title_when_game_has_no_steam_app_id(): void
    {
        $game = Game::factory()->for(User::factory())->create([
            'steam_app_id' => null,
            'title' => 'Minecraft Java',
        ]);
        $gpu = Gpu::factory()->create(['tier' => 'mid', 'g3d_mark' => 10000]);
        $cpu = Cpu::factory()->create(['tier' => 'mid', 'single_thread_mark' => 3000]);

        SettingPreset::factory()->create([
            'game' => 'Minecraft Java',
            'steam_app_id' => null,
            'goal' => 'balanced',
            'gpu_tier' => 'mid',
            'settings' => ['render_distance' => '16 chunks'],
        ]);

        $result = $this->engine()->recommend($game, $gpu, $cpu, 32, 'balanced');

        $this->assertSame('anchor', $result['source']);
        $this->assertSame(['render_distance' => '16 chunks'], $result['settings']);
    }

    public function test_falls_through_to_heuristic_when_no_anchor_matches(): void
    {
        $game = Game::factory()->for(User::factory())->create(['steam_app_id' => 424242]);
        GameMetadata::factory()->create([
            'game_id' => $game->id,
            'dlss_supported' => true,
            'fsr_supported' => false,
            'ray_tracing_supported' => true,
        ]);
        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        $result = $this->engine()->recommend($game, $gpu, $cpu, 32, 'quality');

        $this->assertSame('heuristic', $result['source']);
        // HeuristicRecommender enables upscaling/RT only when capabilities allow it.
        $this->assertSame('quality', $result['settings']['upscaling']);
        $this->assertTrue($result['settings']['ray_tracing']);
        $this->assertArrayHasKey('texture_quality', $result['settings']);
    }

    public function test_low_ram_clamps_texture_quality_down_one_level(): void
    {
        $game = Game::factory()->for(User::factory())->create(['steam_app_id' => 424243]);
        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        $ample = $this->engine()->recommend($game, $gpu, $cpu, 32, 'quality');
        $starved = $this->engine()->recommend($game, $gpu, $cpu, 8, 'quality');

        // high/quality yields 'high' texture_quality; 8GB clamps it to 'medium'.
        $this->assertSame('high', $ample['settings']['texture_quality']);
        $this->assertSame('medium', $starved['settings']['texture_quality']);
        $this->assertSame('under_16gb', $starved['ram_bucket']);
    }

    public function test_flags_cpu_bottleneck_when_gpu_outranks_cpu_by_two_tiers(): void
    {
        $game = Game::factory()->for(User::factory())->create(['steam_app_id' => 424244]);
        $strongGpu = Gpu::factory()->create(['tier' => 'enthusiast', 'g3d_mark' => 30000]);
        $weakCpu = Cpu::factory()->create(['tier' => 'low', 'single_thread_mark' => 2000]);
        $matchedCpu = Cpu::factory()->create(['tier' => 'enthusiast', 'single_thread_mark' => 4200]);

        $bottlenecked = $this->engine()->recommend($game, $strongGpu, $weakCpu, 32, 'quality');
        $balanced = $this->engine()->recommend($game, $strongGpu, $matchedCpu, 32, 'quality');

        $this->assertTrue($bottlenecked['cpu_bottleneck']);
        $this->assertFalse($balanced['cpu_bottleneck']);
    }

    public function test_is_deterministic_for_identical_inputs(): void
    {
        $game = Game::factory()->for(User::factory())->create(['steam_app_id' => 424245]);
        $gpu = Gpu::factory()->create(['tier' => 'mid', 'g3d_mark' => 10000]);
        $cpu = Cpu::factory()->create(['tier' => 'mid', 'single_thread_mark' => 3000]);

        $first = $this->engine()->recommend($game, $gpu, $cpu, 16, 'balanced');
        $second = $this->engine()->recommend($game, $gpu, $cpu, 16, 'balanced');

        $this->assertSame($first, $second);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make artisan CMD="test --filter=RecommendationEngineTest"`
Expected: FAIL — `Class "App\Services\RecommendationEngine" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `app/Services/RecommendationEngine.php`:

```php
<?php

namespace App\Services;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\Gpu;
use App\Models\SettingPreset;
use App\Support\Recommendation\RamBucketClassifier;

class RecommendationEngine
{
    private const TIER_RANKS = [
        'low' => 0,
        'mid' => 1,
        'high' => 2,
        'enthusiast' => 3,
    ];

    private const ORDINAL_LEVELS = ['low', 'medium', 'high', 'ultra'];

    public function __construct(private readonly HeuristicRecommender $heuristic) {}

    /**
     * @return array{settings: array<string,mixed>, source: string, gpu_tier: string, cpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}
     */
    public function recommend(Game $game, Gpu $gpu, Cpu $cpu, int $ramGb, string $goal): array
    {
        $ramBucket = RamBucketClassifier::classify($ramGb);
        $anchor = $this->anchorFor($game, $gpu->tier, $goal);

        if ($anchor !== null) {
            $settings = $anchor->settings;
            $source = 'anchor';
        } else {
            $settings = $this->heuristic->recommend($gpu->tier, $goal, $this->capabilitiesFor($game));
            $source = 'heuristic';
        }

        return [
            'settings' => $this->applyRamAdjustment($settings, $ramBucket),
            'source' => $source,
            'gpu_tier' => $gpu->tier,
            'cpu_tier' => $cpu->tier,
            'ram_bucket' => $ramBucket,
            'cpu_bottleneck' => $this->isCpuBottleneck($gpu->tier, $cpu->tier),
        ];
    }

    private function anchorFor(Game $game, string $gpuTier, string $goal): ?SettingPreset
    {
        $query = SettingPreset::query()
            ->where('goal', $goal)
            ->where('gpu_tier', $gpuTier);

        if ($game->steam_app_id !== null) {
            $query->where('steam_app_id', $game->steam_app_id);
        } else {
            $query->where('game', $game->title);
        }

        return $query->first();
    }

    /**
     * @return array{dlss_supported: bool, fsr_supported: bool, ray_tracing_supported: bool}
     */
    private function capabilitiesFor(Game $game): array
    {
        $metadata = $game->metadata;

        return [
            'dlss_supported' => (bool) ($metadata->dlss_supported ?? false),
            'fsr_supported' => (bool) ($metadata->fsr_supported ?? false),
            'ray_tracing_supported' => (bool) ($metadata->ray_tracing_supported ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    private function applyRamAdjustment(array $settings, string $ramBucket): array
    {
        if ($ramBucket !== RamBucketClassifier::UNDER_16GB || ! array_key_exists('texture_quality', $settings)) {
            return $settings;
        }

        $index = array_search(strtolower((string) $settings['texture_quality']), self::ORDINAL_LEVELS, true);

        if ($index !== false && $index > 0) {
            $settings['texture_quality'] = self::ORDINAL_LEVELS[$index - 1];
        }

        return $settings;
    }

    private function isCpuBottleneck(string $gpuTier, string $cpuTier): bool
    {
        return (self::TIER_RANKS[$gpuTier] - self::TIER_RANKS[$cpuTier]) >= 2;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make artisan CMD="test --filter=RecommendationEngineTest"`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/RecommendationEngine.php tests/Feature/Recommendations/RecommendationEngineTest.php
git commit -m "[Sprint 5] add forward-mode RecommendationEngine service"
```

---

### Task 3: POST /api/recommend endpoint

**Files:**
- Create: `app/Http/Requests/Recommendations/RecommendRequest.php`
- Create: `app/Http/Controllers/RecommendationController.php`
- Modify: `routes/api.php` (add route inside the existing `auth:sanctum` group, ~line 57-82)
- Test: `tests/Feature/Recommendations/RecommendEndpointTest.php`

**Interfaces:**
- Consumes: `RecommendationEngine::recommend(...)` (Task 2); `SettingPreset::GOALS` (existing const `['performance','balanced','quality']`); the existing `$request->user()->games()` HasMany and the `ModelNotFoundException → 404` idiom used by `GameController`.
- Produces: HTTP `POST /api/recommend` (route name `recommend`) returning `200` with a `data` object:
  ```
  { "data": { "game_id", "goal", "settings", "source", "gpu_tier",
              "cpu_tier", "ram_bucket", "cpu_bottleneck", "explanation" } }
  ```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Recommendations/RecommendEndpointTest.php`:

```php
<?php

namespace Tests\Feature\Recommendations;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\Gpu;
use App\Models\SettingPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: array<string,mixed>}
     */
    private function scenario(?callable $tweakGame = null): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create(['steam_app_id' => 700700]);

        if ($tweakGame !== null) {
            $tweakGame($game);
        }

        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        return [$user, [
            'game_id' => $game->id,
            'gpu_id' => $gpu->id,
            'cpu_id' => $cpu->id,
            'ram_gb' => 32,
            'goal' => 'quality',
        ]];
    }

    public function test_guest_is_rejected_401(): void
    {
        $this->postJson('/api/recommend', [])->assertStatus(401);
    }

    public function test_missing_fields_return_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/recommend', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['game_id', 'gpu_id', 'cpu_id', 'ram_gb', 'goal']);
    }

    public function test_invalid_goal_returns_422(): void
    {
        [$user, $payload] = $this->scenario();
        $payload['goal'] = 'cinematic';

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('goal');
    }

    public function test_nonexistent_game_returns_422(): void
    {
        [$user, $payload] = $this->scenario();
        $payload['game_id'] = 999999;

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('game_id');
    }

    public function test_another_users_game_returns_404_idor(): void
    {
        [$user, $payload] = $this->scenario();
        $othersGame = Game::factory()->for(User::factory())->create();
        $payload['game_id'] = $othersGame->id;

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertStatus(404);
    }

    public function test_anchor_hit_returns_settings_and_explanation(): void
    {
        [$user, $payload] = $this->scenario();
        SettingPreset::factory()->create([
            'steam_app_id' => 700700,
            'goal' => 'quality',
            'gpu_tier' => 'high',
            'settings' => ['upscaling' => 'DLSS Quality mode', 'shadow_quality' => 'ultra'],
        ]);

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertOk()
            ->assertJsonPath('data.source', 'anchor')
            ->assertJsonPath('data.gpu_tier', 'high')
            ->assertJsonPath('data.settings.upscaling', 'DLSS Quality mode')
            ->assertJsonPath('data.game_id', $payload['game_id'])
            ->assertJsonPath('data.goal', 'quality')
            ->assertJsonStructure(['data' => ['settings', 'source', 'ram_bucket', 'cpu_bottleneck', 'explanation']]);
    }

    public function test_heuristic_fallback_when_no_anchor(): void
    {
        [$user, $payload] = $this->scenario();

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertOk()
            ->assertJsonPath('data.source', 'heuristic')
            ->assertJsonStructure(['data' => ['settings' => ['texture_quality'], 'explanation']]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make artisan CMD="test --filter=RecommendEndpointTest"`
Expected: FAIL — route `/api/recommend` not defined (405/404) / controller class missing.

- [ ] **Step 3: Write the Form Request**

Create `app/Http/Requests/Recommendations/RecommendRequest.php`:

```php
<?php

namespace App\Http\Requests\Recommendations;

use App\Models\SettingPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecommendRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'gpu_id' => ['required', 'integer', 'exists:gpus,id'],
            'cpu_id' => ['required', 'integer', 'exists:cpus,id'],
            'ram_gb' => ['required', 'integer', 'min:1', 'max:512'],
            'goal' => ['required', Rule::in(SettingPreset::GOALS)],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/RecommendationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Recommendations\RecommendRequest;
use App\Models\Cpu;
use App\Models\Gpu;
use App\Services\RecommendationEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class RecommendationController extends Controller
{
    public function store(RecommendRequest $request, RecommendationEngine $engine): JsonResponse
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

        return response()->json([
            'data' => [
                'game_id' => $game->id,
                'goal' => $goal,
                ...$result,
                'explanation' => $this->fallbackExplanation($result, $goal),
            ],
        ]);
    }

    /**
     * Deterministic static explanation. The LLM ExplanationGenerator (separate
     * Phase-5 section) replaces this and reuses it as its API-failure fallback.
     *
     * @param  array{source: string, gpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}  $result
     */
    private function fallbackExplanation(array $result, string $goal): string
    {
        $source = $result['source'] === 'anchor'
            ? 'a curated anchor preset'
            : 'the heuristic engine';

        $bottleneck = $result['cpu_bottleneck']
            ? ' Your CPU trails your GPU by two or more tiers, so CPU-bound scenes may still cap frame rate.'
            : '';

        return "These {$goal} settings come from {$source} for your {$result['gpu_tier']}-tier GPU "
            . "and {$result['ram_bucket']} memory bucket.{$bottleneck}";
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/api.php`, add the `RecommendationController` import alongside the other controller imports, then add this route **inside** the existing `Route::middleware('auth:sanctum')->group(...)` block (near the hardware routes, ~line 57-62):

```php
    Route::post('/recommend', [RecommendationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('recommend');
```

Import line to add near the top (keep the existing alphabetical-ish grouping):

```php
use App\Http\Controllers\RecommendationController;
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `make artisan CMD="test --filter=RecommendEndpointTest"`
Expected: PASS (7 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Recommendations/RecommendRequest.php app/Http/Controllers/RecommendationController.php routes/api.php tests/Feature/Recommendations/RecommendEndpointTest.php
git commit -m "[Sprint 5] add POST /api/recommend forward-mode endpoint"
```

---

### Task 4: Documentation + full-suite verification

**Files:**
- Modify: `docs/DECISIONS.md`
- Modify: `docs/cortex-lite-build-plan.md` (check off the "Forward-mode recommendation engine" items)

**Interfaces:**
- Consumes: nothing (docs only).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Add the DECISIONS.md entry**

Append to `docs/DECISIONS.md` (match the existing ADR format):

```markdown
### Forward-mode recommendation: anchor-first resolution with heuristic fallback and post-hoc RAM/CPU adjustment
**Date:** 2026-07-06
**Decision:** `RecommendationEngine` resolves settings deterministically: it prefers a `setting_presets` anchor keyed on `(steam_app_id or title, gpu_tier, goal)`, and falls through to `HeuristicRecommender` (masked by PCGamingWiki capability metadata) when no anchor matches. It then buckets RAM (`<16 / 16-31 / >=32 GB`), clamps `texture_quality` down one level under 16 GB, and flags a CPU bottleneck when the GPU tier outranks the CPU tier by 2+ steps.
**Rationale:** Anchors are curated ground truth for the most common tuples; the heuristic generalises everywhere else. Keeping both paths and all adjustments deterministic preserves the "LLM never decides settings" guarantee and makes the engine unit-testable with known-input→known-output assertions. Anchor lookup keys on the game's own Steam App ID / title (not the per-user `games.id`) because `setting_presets` is a shared catalog.
**Alternatives considered:** (a) LLM-generated settings — rejected, breaks the no-hallucination safety story. (b) Mutating CPU-bound settings directly for bottlenecks — rejected as over-reach given the current settings vocabulary; a surfaced `cpu_bottleneck` flag lets the explanation layer describe the tradeoff honestly without inventing setting changes.
**Consequences:** The `settings` shape differs between anchor blobs (rich, game-specific keys) and heuristic output (fixed key set); the `source` field tells consumers which they got. The `explanation` field is a deterministic static string in this slice; the later LLM section replaces it and reuses it as the API-failure fallback.
```

- [ ] **Step 2: Check off the build-plan items**

In `docs/cortex-lite-build-plan.md`, under Phase 5 → "Forward-mode recommendation engine", change the three `- [ ]` items (`RecommendationEngine service`, `Endpoint: POST /api/recommend`, `PHPUnit tests for the engine`) to `- [x]`.

- [ ] **Step 3: Run the full suite**

Run: `make test`
Expected: PASS — all pre-existing tests (218 as of the last Phase-4 entry) plus the new RamBucketClassifier (3), RecommendationEngine (6), and RecommendEndpoint (7) tests. Confirm zero failures before committing.

- [ ] **Step 4: Confirm no whitespace errors**

Run: `git diff --check`
Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add docs/DECISIONS.md docs/cortex-lite-build-plan.md
git commit -m "[Sprint 5] document forward-mode recommendation engine decisions"
```

---

## Self-Review

**Spec coverage (build plan "Forward-mode recommendation engine"):**
- "`RecommendationEngine` service … Inputs: game_id, gpu_id, cpu_id, ram_gb, goal" → Task 2 (engine takes resolved `Game`/`Gpu`/`Cpu` models; the controller in Task 3 maps the IDs → models, keeping the engine DB-lookup-free and unit-testable). ✔
- Algorithm step 1 (GPU/CPU tier + RAM bucket) → `RamBucketClassifier` (Task 1) + `$gpu->tier`/`$cpu->tier` (Task 2). ✔
- Algorithm step 2 (anchor for `(game, gpu_tier, goal)`) → `anchorFor()` (Task 2). ✔
- Algorithm step 3 (else `HeuristicRecommender` with metadata) → `capabilitiesFor()` + heuristic call (Task 2). ✔
- Algorithm step 4 (CPU/RAM bottleneck adjustments) → `applyRamAdjustment()` + `isCpuBottleneck()` (Task 2). ✔
- Algorithm step 5 (return structured settings JSON) → engine return array + endpoint `data` (Tasks 2-3). ✔
- "Endpoint `POST /api/recommend` … settings JSON plus an `explanation` field" → Task 3 (`explanation` is the deterministic static fallback; LLM section is out of scope, flagged). ✔
- "PHPUnit tests … known inputs → expected outputs … deterministic" → Task 2 determinism test + all engine/endpoint tests. ✔

**Placeholder scan:** No TBD/TODO/"add error handling"/"write tests for the above". Every code and test step shows complete content. ✔

**Type consistency:** `RamBucketClassifier::classify`/`UNDER_16GB` used identically in Tasks 1-2. `RecommendationEngine::recommend(Game,Gpu,Cpu,int,string)` return-array keys (`settings/source/gpu_tier/cpu_tier/ram_bucket/cpu_bottleneck`) match between Task 2 definition and Task 3 consumption. `SettingPreset::GOALS` referenced as an existing const. `HeuristicRecommender::recommend` signature matches the existing service. ✔

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-07-06-phase-5-forward-mode-recommendation-engine.md`. Two execution options:**

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
