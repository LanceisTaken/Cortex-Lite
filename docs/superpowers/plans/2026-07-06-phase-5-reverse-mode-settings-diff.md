# Reverse-Mode Settings Diff Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the deterministic reverse-mode `SettingsDiffEngine` and its `POST /api/reverse` endpoint, turning a user's pasted current-settings JSON plus a (game, GPU, CPU, RAM, goal) request into a structured diff against the canonical recommendation, with a deterministic static explanation.

**Architecture:** A pure `SettingsComparator` support class compares two settings arrays and emits an ordered list of per-setting changes. A new `SettingsDiffEngine` service orchestrates the existing deterministic `RecommendationEngine` (to produce the canonical preset) and the comparator (to diff the pasted JSON against it). A thin controller scopes the game to the authenticated user (IDOR-safe), resolves GPU/CPU, calls the engine, and returns the structured diff plus a **deterministic static `explanation`** — the LLM-generated explanation is a separate, later section of Phase 5 and will replace this fallback string.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8.4 (SQLite in tests), PHPUnit, Sanctum SPA auth.

## Global Constraints

- **The LLM never decides settings.** This entire engine is deterministic. No Gemini/LLM call appears anywhere in this plan. The diff is a pure rule-based comparison against the deterministic `RecommendationEngine` output. (CLAUDE.md rule 1; build plan: "Reverse mode is rule-based, not LLM-driven.")
- **The diff is deterministic:** identical inputs (pasted settings + hardware + goal) must always produce byte-identical output, including diff entry ordering. Ordering follows the canonical-settings key order. (CLAUDE.md testing expectations: "SettingsDiffEngine correctness".)
- **Authorization + IDOR on every resource endpoint.** `POST /api/reverse` must scope `game_id` to the authenticated user; User A passing User B's `game_id` returns 404, never 200. (CLAUDE.md testing expectations.)
- **Sanctum SPA auth only** — the endpoint sits behind `auth:sanctum`. (CLAUDE.md rule 3.)
- **Free-tier quota gating is OUT OF SCOPE for this plan.** The rolling-30-day-window quota, `usage_events`, and Stripe gating are a separate later section of Phase 5. Do not add usage logging or quota checks here.
- **No new DB tables or migrations.** This slice consumes existing tables (`games`, `gpus`, `cpus`, `setting_presets`, `game_metadata`) only, and reuses the existing `RecommendationEngine`.
- **Sprint-tagged commits:** every commit message is prefixed `[Sprint 5] `.
- **Dev commands go through `make`** — `make test`, `make artisan CMD="..."`. Never call raw `php`/`docker`.

## Scope boundary (read before starting)

This plan implements exactly the build-plan sub-section **"Reverse mode (settings diff)"** (`docs/cortex-lite-build-plan.md`, Phase 5, the three unchecked items: `SettingsDiffEngine` service, `POST /api/reverse` endpoint + DECISIONS.md note, and diff-engine PHPUnit tests). It mirrors the scoping decision already made by the forward-mode plan (`docs/superpowers/plans/2026-07-06-phase-5-forward-mode-recommendation-engine.md`): it deliberately stops at a deterministic static `explanation` string.

Three neighbouring Phase-5 sub-sections are **separate plans** and must not be pulled in here:
- **LLM-generated explanation** (`ExplanationGenerator` + Redis LLM cache keyed on `hash(diff_structure, hardware_tier, goal)`) — will replace the static explanation this plan produces, and reuse it as the API-failure fallback.
- **Stripe premium gating** (rolling-window quota + `usage_events` logging on each reverse-mode call).
- **React UI** (paste box + diff table) — lives in the "LLM-generated explanation" build-plan section.

The static `explanation` built here doubles as the documented fallback the LLM section returns on API failure (`TROUBLESHOOTING.md`: "LLM API timeout fallback — return recommendation/diff with a static explanation"), so it is not throwaway work. The diff structure this plan produces is deliberately ordered and stable so the later LLM section can hash it for its cache key.

## File Structure

- `app/Support/Recommendation/SettingsComparator.php` — **new.** Pure static comparator. One responsibility: given a user's current settings array and a recommended settings array, return an ordered list of the settings that differ. No DB, no HTTP. Mirrors the existing `app/Support/Recommendation/RamBucketClassifier.php` pure-support pattern.
- `app/Services/SettingsDiffEngine.php` — **new.** The orchestrator. Depends on `RecommendationEngine` (constructor-injected) to produce the canonical preset, then delegates the comparison to `SettingsComparator`. Returns a structured array. No HTTP, no auth concerns.
- `app/Http/Requests/Recommendations/ReverseRequest.php` — **new.** Form Request validating the same five inputs as `RecommendRequest` plus the pasted `current_settings` object.
- `app/Http/Controllers/ReverseController.php` — **new.** Scopes the game to the user (IDOR), resolves GPU/CPU, calls the engine, attaches the static explanation, returns JSON. Mirrors `RecommendationController`.
- `routes/api.php` — **modify.** Add `POST /api/reverse` inside the existing `auth:sanctum` group, next to `/recommend` (~line 64-66).
- `tests/Unit/Support/Recommendation/SettingsComparatorTest.php` — **new.** Pure comparator tests (no DB): value change, boolean change, no-change, ignored-unknown-key, missing-key, case-insensitive match, ordering/determinism.
- `tests/Feature/Recommendations/SettingsDiffEngineTest.php` — **new.** DB-backed engine tests (anchor-driven canonical preset, diff correctness, metadata pass-through, empty diff).
- `tests/Feature/Recommendations/ReverseEndpointTest.php` — **new.** HTTP tests (auth, validation incl. `current_settings` shape, IDOR, diff happy path, already-optimal path, response shape).

---

### Task 1: SettingsComparator (pure diff logic)

**Files:**
- Create: `app/Support/Recommendation/SettingsComparator.php`
- Test: `tests/Unit/Support/Recommendation/SettingsComparatorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `SettingsComparator::compare(array $current, array $recommended): array` returning a `list` (0-indexed, ordered) of entries, one per **recommended** key that the user both supplied and set to a different value:
  ```
  list<array{setting: string, current: string, recommended: string, label: string}>
  ```
  `label` is the display string `"<current> → <recommended>"` (a real `→` U+2192 arrow, spaces around it). `SettingsDiffEngine` (Task 2) consumes this method.

**Design notes (deterministic behaviour to implement):**
- **The recommendation defines the vocabulary.** Iterate over the *recommended* settings keys in their existing order. This bounds the diff to settings the engine has an opinion on and makes entry ordering deterministic (needed for the later `hash(diff_structure)` LLM cache key).
- **Only emit actionable, provided changes.** Emit an entry only when the user actually supplied that key (`array_key_exists`) **and** its normalised value differs from the recommended value. Keys the user pasted that are *not* in the recommendation are ignored (we have no opinion on them). Recommended keys the user did *not* paste are skipped (nothing to compare against). Both exclusions are deliberate and documented in Task 4.
- **Value normalisation for display:** booleans render as `on`/`off` (matching the build-plan example `ray_tracing: "on → off"`); arrays render as compact JSON (defensive against odd pasted input); everything else is `trim((string) $value)`.
- **Equality is case-insensitive** on the display strings (so pasted `"High"` matches recommended `"high"` and produces no entry), but the emitted `current`/`recommended`/`label` preserve each side's original display casing.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/Recommendation/SettingsComparatorTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make artisan CMD="test --filter=SettingsComparatorTest"`
Expected: FAIL — `Class "App\Support\Recommendation\SettingsComparator" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `app/Support/Recommendation/SettingsComparator.php`:

```php
<?php

namespace App\Support\Recommendation;

class SettingsComparator
{
    /**
     * Compare a user's current settings against the recommended settings.
     *
     * Iterates the recommended keys (the recommendation defines the vocabulary
     * that matters), preserving their order so the diff is deterministic. Emits
     * an entry only where the user supplied that key AND its normalised value
     * differs. Pasted keys absent from the recommendation are ignored; recommended
     * keys the user did not paste are skipped.
     *
     * @param  array<string, mixed>  $current      user-pasted current settings
     * @param  array<string, mixed>  $recommended  canonical preset settings
     * @return list<array{setting: string, current: string, recommended: string, label: string}>
     */
    public static function compare(array $current, array $recommended): array
    {
        $diff = [];

        foreach ($recommended as $setting => $recommendedValue) {
            if (! array_key_exists($setting, $current)) {
                continue;
            }

            $currentDisplay = self::display($current[$setting]);
            $recommendedDisplay = self::display($recommendedValue);

            if (mb_strtolower($currentDisplay) === mb_strtolower($recommendedDisplay)) {
                continue;
            }

            $diff[] = [
                'setting' => (string) $setting,
                'current' => $currentDisplay,
                'recommended' => $recommendedDisplay,
                'label' => "{$currentDisplay} → {$recommendedDisplay}",
            ];
        }

        return $diff;
    }

    private static function display(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        return trim((string) $value);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make artisan CMD="test --filter=SettingsComparatorTest"`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Recommendation/SettingsComparator.php tests/Unit/Support/Recommendation/SettingsComparatorTest.php
git commit -m "[Sprint 5] add SettingsComparator for reverse-mode diffing"
```

---

### Task 2: SettingsDiffEngine service

**Files:**
- Create: `app/Services/SettingsDiffEngine.php`
- Test: `tests/Feature/Recommendations/SettingsDiffEngineTest.php`

**Interfaces:**
- Consumes:
  - `SettingsComparator::compare(array $current, array $recommended): array` (Task 1).
  - `RecommendationEngine::recommend(Game $game, Gpu $gpu, Cpu $cpu, int $ramGb, string $goal): array` (existing, `app/Services/RecommendationEngine.php`). Returns keys `settings, source, gpu_tier, cpu_tier, ram_bucket, cpu_bottleneck`.
  - Existing models: `Game`, `Gpu`, `Cpu`, and `SettingPreset` (for anchor-driven test fixtures).
- Produces: `SettingsDiffEngine::diff(Game $game, Gpu $gpu, Cpu $cpu, int $ramGb, string $goal, array $currentSettings): array` returning:
  ```
  [
    'diff'           => list<array{setting: string, current: string, recommended: string, label: string}>,
    'recommendation' => array{settings, source, gpu_tier, cpu_tier, ram_bucket, cpu_bottleneck}, // verbatim RecommendationEngine output
  ]
  ```
  `ReverseController` (Task 3) consumes this array.

**Design notes:**
- The engine runs `RecommendationEngine` first (build-plan algorithm step 1), then diffs the pasted settings against `recommendation['settings']` via the comparator (step 2), and returns both the diff and the full recommendation so the caller can build the explanation and (later) the LLM cache key from the same object (step 3). It adds no logic of its own beyond wiring — all determinism lives in `RecommendationEngine` (already tested) and `SettingsComparator` (Task 1).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Recommendations/SettingsDiffEngineTest.php`:

```php
<?php

namespace Tests\Feature\Recommendations;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\Gpu;
use App\Models\SettingPreset;
use App\Models\User;
use App\Services\SettingsDiffEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsDiffEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): SettingsDiffEngine
    {
        return app(SettingsDiffEngine::class);
    }

    /**
     * Anchor-drive the canonical preset so the expected diff is fully controlled.
     *
     * @return array{0: Game, 1: Gpu, 2: Cpu}
     */
    private function scenarioWithAnchor(array $settings): array
    {
        $game = Game::factory()->for(User::factory())->create([
            'steam_app_id' => 1091500,
            'title' => 'Cyberpunk 2077',
        ]);
        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        SettingPreset::factory()->create([
            'game' => 'Cyberpunk 2077',
            'steam_app_id' => 1091500,
            'goal' => 'quality',
            'gpu_tier' => 'high',
            'settings' => $settings,
        ]);

        return [$game, $gpu, $cpu];
    }

    public function test_diffs_pasted_settings_against_the_canonical_preset(): void
    {
        [$game, $gpu, $cpu] = $this->scenarioWithAnchor([
            'texture_quality' => 'medium',
            'ray_tracing' => false,
            'shadow_quality' => 'high',
        ]);

        $result = $this->engine()->diff($game, $gpu, $cpu, 32, 'quality', [
            'texture_quality' => 'ultra',
            'ray_tracing' => true,
            'shadow_quality' => 'high',
        ]);

        $this->assertSame(['texture_quality', 'ray_tracing'], array_column($result['diff'], 'setting'));
        $this->assertSame('ultra → medium', $result['diff'][0]['label']);
        $this->assertSame('on → off', $result['diff'][1]['label']);
    }

    public function test_returns_the_recommendation_metadata_alongside_the_diff(): void
    {
        [$game, $gpu, $cpu] = $this->scenarioWithAnchor(['texture_quality' => 'medium']);

        $result = $this->engine()->diff($game, $gpu, $cpu, 32, 'quality', [
            'texture_quality' => 'ultra',
        ]);

        $this->assertSame('anchor', $result['recommendation']['source']);
        $this->assertSame('high', $result['recommendation']['gpu_tier']);
        $this->assertSame(['texture_quality' => 'medium'], $result['recommendation']['settings']);
    }

    public function test_empty_diff_when_current_settings_already_match(): void
    {
        [$game, $gpu, $cpu] = $this->scenarioWithAnchor([
            'texture_quality' => 'medium',
            'ray_tracing' => false,
        ]);

        $result = $this->engine()->diff($game, $gpu, $cpu, 32, 'quality', [
            'texture_quality' => 'medium',
            'ray_tracing' => false,
        ]);

        $this->assertSame([], $result['diff']);
    }

    public function test_ignores_pasted_settings_the_recommendation_does_not_cover(): void
    {
        [$game, $gpu, $cpu] = $this->scenarioWithAnchor(['texture_quality' => 'medium']);

        $result = $this->engine()->diff($game, $gpu, $cpu, 32, 'quality', [
            'texture_quality' => 'medium',
            'motion_blur' => 'on',
            'film_grain' => 'high',
        ]);

        $this->assertSame([], $result['diff']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make artisan CMD="test --filter=SettingsDiffEngineTest"`
Expected: FAIL — `Class "App\Services\SettingsDiffEngine" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `app/Services/SettingsDiffEngine.php`:

```php
<?php

namespace App\Services;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\Gpu;
use App\Support\Recommendation\SettingsComparator;

class SettingsDiffEngine
{
    public function __construct(private readonly RecommendationEngine $engine) {}

    /**
     * Diff a user's pasted current settings against the canonical recommendation.
     *
     * @param  array<string, mixed>  $currentSettings
     * @return array{diff: list<array{setting: string, current: string, recommended: string, label: string}>, recommendation: array<string, mixed>}
     */
    public function diff(Game $game, Gpu $gpu, Cpu $cpu, int $ramGb, string $goal, array $currentSettings): array
    {
        $recommendation = $this->engine->recommend($game, $gpu, $cpu, $ramGb, $goal);

        return [
            'diff' => SettingsComparator::compare($currentSettings, $recommendation['settings']),
            'recommendation' => $recommendation,
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make artisan CMD="test --filter=SettingsDiffEngineTest"`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/SettingsDiffEngine.php tests/Feature/Recommendations/SettingsDiffEngineTest.php
git commit -m "[Sprint 5] add reverse-mode SettingsDiffEngine service"
```

---

### Task 3: POST /api/reverse endpoint

**Files:**
- Create: `app/Http/Requests/Recommendations/ReverseRequest.php`
- Create: `app/Http/Controllers/ReverseController.php`
- Modify: `routes/api.php` (add route inside the existing `auth:sanctum` group, next to `/recommend`, ~line 64-66)
- Test: `tests/Feature/Recommendations/ReverseEndpointTest.php`

**Interfaces:**
- Consumes: `SettingsDiffEngine::diff(...)` (Task 2); `SettingPreset::GOALS` (existing const `['performance','balanced','quality']`); the existing `$request->user()->games()` HasMany and the `ModelNotFoundException → 404` idiom used by `RecommendationController`.
- Produces: HTTP `POST /api/reverse` (route name `reverse`) returning `200` with a `data` object:
  ```
  { "data": { "game_id", "goal", "diff": [ {setting, current, recommended, label}, ... ],
              "recommendation": { settings, source, gpu_tier, cpu_tier, ram_bucket, cpu_bottleneck },
              "explanation" } }
  ```

**Design notes:**
- `ReverseRequest` extends the `RecommendRequest` field set with `current_settings` validated as a required array (the pasted-JSON object). The frontend parses the user's pasted text into an object before POSTing; the API contract is an object, not a raw string.
- The static `explanation` is deterministic and doubles as the later LLM API-failure fallback: it names the change count and lists the per-setting changes, or states the settings already match when the diff is empty.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Recommendations/ReverseEndpointTest.php`:

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

class ReverseEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: array<string,mixed>}
     */
    private function scenario(array $anchorSettings, array $currentSettings): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create([
            'steam_app_id' => 700700,
            'title' => 'Test Game',
        ]);
        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        SettingPreset::factory()->create([
            'game' => 'Test Game',
            'steam_app_id' => 700700,
            'goal' => 'quality',
            'gpu_tier' => 'high',
            'settings' => $anchorSettings,
        ]);

        return [$user, [
            'game_id' => $game->id,
            'gpu_id' => $gpu->id,
            'cpu_id' => $cpu->id,
            'ram_gb' => 32,
            'goal' => 'quality',
            'current_settings' => $currentSettings,
        ]];
    }

    public function test_guest_is_rejected_401(): void
    {
        $this->postJson('/api/reverse', [])->assertStatus(401);
    }

    public function test_missing_fields_return_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/reverse', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['game_id', 'gpu_id', 'cpu_id', 'ram_gb', 'goal', 'current_settings']);
    }

    public function test_current_settings_must_be_an_object_not_a_string(): void
    {
        [$user, $payload] = $this->scenario(['texture_quality' => 'medium'], ['texture_quality' => 'ultra']);
        $payload['current_settings'] = 'texture_quality=ultra';

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_settings');
    }

    public function test_invalid_goal_returns_422(): void
    {
        [$user, $payload] = $this->scenario(['texture_quality' => 'medium'], ['texture_quality' => 'ultra']);
        $payload['goal'] = 'cinematic';

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('goal');
    }

    public function test_another_users_game_returns_404_idor(): void
    {
        [$user, $payload] = $this->scenario(['texture_quality' => 'medium'], ['texture_quality' => 'ultra']);
        $othersGame = Game::factory()->for(User::factory())->create();
        $payload['game_id'] = $othersGame->id;

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertStatus(404);
    }

    public function test_returns_the_diff_and_explanation(): void
    {
        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium', 'ray_tracing' => false],
            ['texture_quality' => 'ultra', 'ray_tracing' => true],
        );

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertOk()
            ->assertJsonPath('data.game_id', $payload['game_id'])
            ->assertJsonPath('data.goal', 'quality')
            ->assertJsonPath('data.recommendation.source', 'anchor')
            ->assertJsonPath('data.diff.0.setting', 'texture_quality')
            ->assertJsonPath('data.diff.0.label', 'ultra → medium')
            ->assertJsonPath('data.diff.1.setting', 'ray_tracing')
            ->assertJsonPath('data.diff.1.label', 'on → off')
            ->assertJsonStructure(['data' => ['diff', 'recommendation' => ['settings', 'source'], 'explanation']]);
    }

    public function test_already_optimal_settings_return_an_empty_diff(): void
    {
        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium'],
            ['texture_quality' => 'medium'],
        );

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertOk()
            ->assertJsonPath('data.diff', [])
            ->assertJsonStructure(['data' => ['explanation']]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make artisan CMD="test --filter=ReverseEndpointTest"`
Expected: FAIL — route `/api/reverse` not defined (405/404) / controller class missing.

- [ ] **Step 3: Write the Form Request**

Create `app/Http/Requests/Recommendations/ReverseRequest.php`:

```php
<?php

namespace App\Http\Requests\Recommendations;

use App\Models\SettingPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReverseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'gpu_id' => ['required', 'integer', 'exists:gpus,id'],
            'cpu_id' => ['required', 'integer', 'exists:cpus,id'],
            'ram_gb' => ['required', 'integer', 'min:1', 'max:512'],
            'goal' => ['required', Rule::in(SettingPreset::GOALS)],
            'current_settings' => ['required', 'array'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/ReverseController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Recommendations\ReverseRequest;
use App\Models\Cpu;
use App\Models\Gpu;
use App\Services\SettingsDiffEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class ReverseController extends Controller
{
    public function store(ReverseRequest $request, SettingsDiffEngine $engine): JsonResponse
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

        return response()->json([
            'data' => [
                'game_id' => $game->id,
                'goal' => $goal,
                ...$result,
                'explanation' => $this->fallbackExplanation($result['diff'], $result['recommendation'], $goal),
            ],
        ]);
    }

    /**
     * Deterministic static explanation of the diff. The LLM ExplanationGenerator
     * (separate Phase-5 section) replaces this and reuses it as its API-failure
     * fallback.
     *
     * @param  list<array{setting: string, label: string}>  $diff
     * @param  array{gpu_tier: string}  $recommendation
     */
    private function fallbackExplanation(array $diff, array $recommendation, string $goal): string
    {
        $tier = $recommendation['gpu_tier'];

        if ($diff === []) {
            return "Your current settings already match the {$goal} recommendation for your {$tier}-tier GPU.";
        }

        $changes = implode(', ', array_map(
            static fn (array $entry): string => "{$entry['setting']} {$entry['label']}",
            $diff,
        ));
        $count = count($diff);
        $noun = $count === 1 ? 'change' : 'changes';

        return "{$count} {$noun} will align your settings with the {$goal} recommendation "
            . "for your {$tier}-tier GPU: {$changes}.";
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/api.php`, add the `ReverseController` import alongside the other controller imports (keep the existing alphabetical-ish grouping — it sorts after `RecommendationController`):

```php
use App\Http\Controllers\ReverseController;
```

Then add this route **inside** the existing `Route::middleware('auth:sanctum')->group(...)` block, directly after the existing `/recommend` route (~line 64-66):

```php
    Route::post('/reverse', [ReverseController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('reverse');
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `make artisan CMD="test --filter=ReverseEndpointTest"`
Expected: PASS (7 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Recommendations/ReverseRequest.php app/Http/Controllers/ReverseController.php routes/api.php tests/Feature/Recommendations/ReverseEndpointTest.php
git commit -m "[Sprint 5] add POST /api/reverse reverse-mode endpoint"
```

---

### Task 4: Documentation + full-suite verification

**Files:**
- Modify: `docs/DECISIONS.md`
- Modify: `docs/cortex-lite-build-plan.md` (check off the "Reverse mode (settings diff)" items)

**Interfaces:**
- Consumes: nothing (docs only).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Add the DECISIONS.md entry**

Append to `docs/DECISIONS.md` (match the existing ADR format). The first paragraph of Rationale is the verbatim architectural note required by the build plan ("Reverse mode is rule-based, not LLM-driven…"):

```markdown
### Reverse mode: rule-based settings diff against the deterministic recommendation
**Date:** 2026-07-06
**Decision:** `SettingsDiffEngine` runs the existing deterministic `RecommendationEngine` to produce the canonical preset for `(game, hardware, goal)`, then a pure `SettingsComparator` diffs the user's pasted `current_settings` against it. The diff iterates the *recommended* keys in order, emitting an entry only where the user supplied that key and its normalised value differs (`{setting, current, recommended, label}`, e.g. `texture_quality: "ultra → medium"`). `POST /api/reverse` returns the diff plus a deterministic static `explanation`.
**Rationale:** *Reverse mode is rule-based, not LLM-driven. The LLM explains the structured diff in prose but never judges settings directly. This preserves the "LLM cannot affect the recommendation" safety story across both modes.* Keying the diff on the recommendation's own key set bounds it to settings the engine has an opinion on and makes entry ordering deterministic, which the later LLM section needs to compute a stable `hash(diff_structure, hardware_tier, goal)` cache key. Ignoring pasted keys the recommendation does not cover, and skipping recommended keys the user did not paste, keeps the diff to genuinely actionable, user-provided changes.
**Alternatives considered:** (a) Let the LLM judge which settings are wrong — rejected, breaks the no-hallucination safety story and makes the diff non-deterministic and un-cacheable. (b) Diff over the union of both key sets, flagging "not set → recommended" for missing keys — rejected for v1 as noisier and harder to test deterministically; the intersection of provided-and-differing keys is the tightest defensible answer to "what should I change." (c) Accept the pasted settings as a raw JSON string parsed server-side — rejected; the frontend parses to an object so the API contract stays a validated array.
**Consequences:** The `explanation` field is a deterministic static string in this slice; the later LLM section replaces it and reuses it as the API-failure fallback. The endpoint returns the full `recommendation` object alongside the `diff` so the caller (and the later LLM cache-key builder) works from one payload. Reverse-mode usage logging and the free-tier 5-call quota are added by the separate Stripe-gating section.
```

- [ ] **Step 2: Check off the build-plan items**

In `docs/cortex-lite-build-plan.md`, under Phase 5 → "Reverse mode (settings diff)", change the three `- [ ]` items to `- [x]`:
- `SettingsDiffEngine service …`
- `Endpoint: POST /api/reverse …`
- `Architectural note documented in DECISIONS.md …` (now satisfied by Step 1)
- `PHPUnit tests for the diff engine …`

(The "LLM-generated explanation" and "Stripe premium gating" items stay unchecked — separate plans.)

- [ ] **Step 3: Run the full suite**

Run: `make test`
Expected: PASS — all previously-passing tests plus the new SettingsComparator (7), SettingsDiffEngine (4), and ReverseEndpoint (7) tests. Confirm **zero failures** before committing.

- [ ] **Step 4: Confirm no whitespace errors**

Run: `git diff --check`
Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add docs/DECISIONS.md docs/cortex-lite-build-plan.md
git commit -m "[Sprint 5] document reverse-mode settings-diff decisions"
```

---

## Self-Review

**Spec coverage (build plan "Reverse mode (settings diff)"):**
- "`SettingsDiffEngine` service. Inputs: pasted settings JSON, plus the same hardware/goal inputs as forward mode" → Task 2 (`diff(Game, Gpu, Cpu, int, string, array)`); the controller in Task 3 maps IDs → models, keeping the engine DB-lookup-free at its own boundary and reusing the tested `RecommendationEngine`. ✔
- Algorithm step 1 ("Run `RecommendationEngine` to get the canonical preset") → `SettingsDiffEngine::diff` calls `$this->engine->recommend(...)` (Task 2). ✔
- Algorithm step 2 ("Diff the pasted JSON against the canonical preset. Output: `{texture_quality: "high → medium", ...}`") → `SettingsComparator::compare` producing `{setting, current, recommended, label}` with the `→` label (Task 1). ✔
- Algorithm step 3 ("Return the structured diff to the caller") → engine return array `{diff, recommendation}` (Task 2). ✔
- "Endpoint `POST /api/reverse` taking pasted JSON + hardware/goal, returning the diff + an … explanation" → Task 3 (`explanation` is the deterministic static fallback; LLM prose is the separate, flagged section). ✔
- "Architectural note documented in `DECISIONS.md`" → Task 4 Step 1 quotes it verbatim. ✔
- "PHPUnit tests for the diff engine: known pasted JSON + canonical preset → expected diff" → Task 1 comparator tests + Task 2 engine tests + Task 3 endpoint tests. ✔

**Placeholder scan:** No TBD/TODO/"add error handling"/"write tests for the above". Every code and test step shows complete content. ✔

**Type consistency:** `SettingsComparator::compare(array,array): list<{setting,current,recommended,label}>` is defined in Task 1 and consumed identically in Task 2. `SettingsDiffEngine::diff(Game,Gpu,Cpu,int,string,array)` return keys (`diff`, `recommendation`) match between Task 2 definition and Task 3 consumption; `recommendation` reuses the existing `RecommendationEngine::recommend` return shape (`settings/source/gpu_tier/cpu_tier/ram_bucket/cpu_bottleneck`) verified against `app/Services/RecommendationEngine.php`. `SettingPreset::GOALS` referenced as an existing const. Controller `fallbackExplanation` reads only `gpu_tier` from `recommendation` and `setting`/`label` from diff entries — all present in the Task 1/Task 2 shapes. ✔

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-07-06-phase-5-reverse-mode-settings-diff.md`. Two execution options:**

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
