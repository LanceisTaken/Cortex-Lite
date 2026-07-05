# Plan: Phase 4 — Anchor dataset persistence + HeuristicRecommender
**Created:** 2026-07-05
**Status:** completed
**Complexity:** simple

---

## Context

Phase 4's hardware tier database and PCGamingWiki integration are shipped (commits 3a8b034, 4e26e0c, 094f03a, c80d602). The anchor settings dataset content is hand-curated at `docs/setting_presets.json` (untracked, 30 records: 10 games x 3 goal/gpu_tier combos, each citing a source in `notes`) but is not committed, not persisted to a table, and has no consumer. `HeuristicRecommender` — the generalizable primary path for any (game, tier, goal) tuple not covered by an anchor — does not exist yet. This plan closes out the remaining Phase 4 checklist items (`docs/cortex-lite-build-plan.md` lines 224-236).

Design decision from clarification: `HeuristicRecommender` outputs one fixed canonical schema for every game (`resolution_scale`, `upscaling`, `ray_tracing`, `shadow_quality`, `texture_quality`, `anti_aliasing`, `ambient_occlusion`) driven by GPU tier x goal, masked by capability flags mirroring `App\Models\GameMetadata` (`dlss_supported`, `fsr_supported`, `ray_tracing_supported`). This is the only shape that generalizes to games with zero anchor coverage. The anchor "calibration" check is therefore semantic/directional, not a byte-exact diff against the hand-curated JSON (anchor field names differ per game — Cyberpunk's `ray_tracing`/`dlss` vs CS2's `shader_detail`/`msaa` vs Valorant's `material_quality`).

The PCGamingWiki integration checkboxes at `docs/cortex-lite-build-plan.md` lines 217-219 and 222 are stale — that work already shipped (`app/Services/PcGamingWikiClient.php`, `app/Services/RateLimiter/PcGamingWikiLimiter.php`, `tests/Unit/Services/PcGamingWiki/*`, `tests/Feature/PcGamingWiki/EnrichGameMetadataCommandTest.php`) — this plan checks them off, does not rebuild them.

## Constraints

- Do not modify `setting_presets.json`'s content — move/commit as-is.
- Reference-data pattern must match the existing `GpuSeeder`/`CpuSeeder` convention: JSON lives under `database/data/`, an idempotent seeder `upsert()`s it into a table, a `Support/` classifier (if any derivation is needed) stays pure and static.
- `HeuristicRecommender` is fully deterministic — no LLM calls (CLAUDE.md rule 1).
- Reuse `App\Support\Hardware\GpuTierClassifier` tier vocabulary (`low`/`mid`/`high`/`enthusiast`) — do not reimplement tiering.
- No CPU/RAM bottleneck adjustment logic in this phase — that belongs to Phase 5's `RecommendationEngine`. `HeuristicRecommender`'s scope is GPU tier x goal + capability masking only.
- Anchor regression test is semantic (ray-tracing/upscaling contradiction checks + tier/goal monotonicity), not exact-match.
- Branch `Phase-4`, commits tagged `[Sprint 4] ...`. All commands via Makefile (`make test`, `make artisan CMD="..."`).
- Model attributes (`#[Fillable]`) over legacy `$fillable` arrays, per `docs/code-standards.md`.

---

## Implementation Phases

### Phase 1: Anchor dataset persistence
**Skills:** none -- mechanical migration/model/seeder work matching the established `GpuSeeder` pattern, no novel design.
**Model:** sonnet
**Gate:** Standard
**Depends on:** none
**File scope:** `docs/setting_presets.json`, `database/data/setting_presets.json`, `database/migrations/*_create_setting_presets_table.php`, `app/Models/SettingPreset.php`, `database/factories/SettingPresetFactory.php`, `database/seeders/SettingPresetSeeder.php`, `database/seeders/DatabaseSeeder.php`, `tests/Feature/SettingPresets/SettingPresetSeederTest.php`, `docs/DECISIONS.md`, `docs/cortex-lite-build-plan.md`

**Goal:** Commit the anchor dataset and make it queryable via a seeded `setting_presets` table.

**Scope:**
- IN: `git mv docs/setting_presets.json database/data/setting_presets.json` (matches where `gpus.json`/`cpus.json` already live for seeder consumption); migration for a `setting_presets` table; `SettingPreset` Eloquent model; factory; idempotent seeder; seeder registered in `DatabaseSeeder`; DECISIONS.md methodology entry; build-plan checkbox updates (anchor dataset section lines 226-229, and the stale PCGamingWiki section lines 217-219/222).
- OUT: Any recommender logic (Phase 2). Any HTTP endpoint exposing presets (not in Phase 4 scope — Phase 5's `RecommendationEngine` consumes this table server-side).

**Edge cases:**
- `steam_app_id` is `null` for 3 of the 10 games (Valorant, Fortnite, Minecraft: Java Edition — not Steam titles per the JSON's own notes). Column must be nullable.
- Re-running the seeder must not duplicate rows — `upsert()` keyed on the natural tuple `['game', 'goal', 'gpu_tier']` (mirrors `GpuSeeder`'s `uniqueBy: ['name']` pattern), enforced by a matching unique DB index.
- `settings` is a JSON blob with a different shape per game — store as `json`, cast to `array`, never assume specific keys at the persistence layer.

**Produces:** A seeded `setting_presets` table — columns `id`, `game` (string), `steam_app_id` (nullable unsigned integer), `goal` (string: performance/balanced/quality), `gpu_tier` (string: low/mid/high/enthusiast), `settings` (json), `notes` (text), timestamps; unique on `(game, goal, gpu_tier)`. Phase 2's regression test queries this table via `SettingPreset::all()`.

**Done when:**
- [ ] DW-1.1: `database/data/setting_presets.json` exists with byte-identical content to the original `docs/setting_presets.json`; the old path is removed (`git mv`, not copy).
- [ ] DW-1.2: Migration `create_setting_presets_table` shipped with `up()`/`down()`, unique index on `(game, goal, gpu_tier)`.
- [ ] DW-1.3: `App\Models\SettingPreset` shipped with `#[Fillable]` on all data columns, `casts()` returning `['settings' => 'array']`, and a factory.
- [ ] DW-1.4: `SettingPresetSeeder` reads `database/data/setting_presets.json`, `upsert()`s all 30 `presets` entries keyed on `(game, goal, gpu_tier)`, registered in `DatabaseSeeder::run()`.
- [ ] DW-1.5: `tests/Feature/SettingPresets/SettingPresetSeederTest.php` asserts the seeder produces exactly 30 rows, re-running it produces no duplicates, and one known row (e.g. Cyberpunk 2077 / quality / high) has the expected `settings` JSON shape.
- [ ] DW-1.8: `tests/Feature/SettingPresets/SettingPresetSchemaTest.php` (matching the `CpuSchemaTest`/`GpuSchemaTest` convention) asserts `SettingPresetFactory` creates a valid row, and a dirty-path test asserts a raw `SettingPreset::create()` with a duplicate `(game, goal, gpu_tier)` throws `QueryException` (proves the unique index from DW-1.2 is real, not just the seeder's `upsert()` silently deduplicating).
- [ ] DW-1.6: `docs/DECISIONS.md` gets an entry documenting anchor methodology: anchors are calibration ground truth for `HeuristicRecommender`, not a universal lookup table; each cites a real source in `notes`.
- [ ] DW-1.7: `docs/cortex-lite-build-plan.md` checkboxes updated: PCGamingWiki integration lines 217-219 and 222 checked (already shipped), anchor dataset lines 226-229 checked.

---

### Phase 2: HeuristicRecommender + anchor regression test
**Skills:** code-foundations:cc-defensive-programming
**Model:** sonnet
**Gate:** Standard
**Depends on:** Phase 1
**File scope:** `app/Services/HeuristicRecommender.php`, `tests/Unit/Services/HeuristicRecommenderTest.php`, `tests/Feature/SettingPresets/AnchorRegressionTest.php`, `docs/cortex-lite-build-plan.md`

**Goal:** Ship the deterministic, capability-masked settings generator that covers every (gpu_tier, goal) tuple, and prove it never contradicts the curated anchors.

**Scope:**
- IN: `HeuristicRecommender::recommend(string $gpuTier, string $goal, array $capabilities): array` with the exact axis rules below; unit tests for the algorithm's own monotonicity and masking properties; a feature test that runs the recommender against every seeded anchor and checks for semantic contradictions.
- OUT: Anchor-priority lookup (i.e. "if an anchor exists, return it directly") — that orchestration is Phase 5's `RecommendationEngine`, not this service. CPU/RAM bottleneck adjustment (Phase 5). Any HTTP endpoint.

**Algorithm (exact contract, to remove ambiguity for the anchor regression test):**
- Tier rank: `low=0, mid=1, high=2, enthusiast=3`. Goal rank: `performance=0, balanced=1, quality=2`.
- Ordinal fields (`shadow_quality`, `texture_quality`, `anti_aliasing`, `ambient_occlusion`): level = `clamp(round((tierRank + goalRank) / 5 * 3), 0, 3)` mapped to `[low, medium, high, ultra]`. This is a monotonic transform of `tierRank + goalRank`, so it is non-decreasing as either input rises — that monotonicity is what DW-2.2 tests, not a magic constant to preserve verbatim.
- `resolution_scale`: `"90%"` when `goal === 'performance' && gpuTier === 'low'`, else `"100%"`.
- `upscaling`: one of `off|performance|balanced|quality`. `"off"` unless `capabilities['dlss_supported'] || capabilities['fsr_supported']` is true, in which case it equals the goal name directly (`performance`->`"performance"`, etc).
- `ray_tracing`: boolean. `true` only when `capabilities['ray_tracing_supported'] === true && goal === 'quality' && tierRank >= 2`.
- Missing capability keys are treated as `false` (fail-safe — never recommend an unsupported feature).
- Unknown `gpuTier`/`goal` values throw `InvalidArgumentException` (defensive barricade at the boundary, per `cc-defensive-programming`).

**Edge cases:**
- Empty `capabilities` array (no `GameMetadata` row exists for a game) must never throw — every flag defaults to unsupported, so `upscaling` stays `"off"` and `ray_tracing` stays `false`.
- `gpuTier = 'enthusiast'` must be accepted even though no anchor covers it (anchors only use low/mid/high) — `HeuristicRecommender` is a generalizable service, not an anchor lookup.
- The anchor regression test must not assume the anchor's own field names — it infers "does this anchor imply ray tracing/upscaling is on" via a pattern match over the anchor's `settings` values (see DW-2.4), not a fixed key name, since key names vary per game.

**Produces:** `App\Services\HeuristicRecommender::recommend(string $gpuTier, string $goal, array $capabilities): array` returning `['resolution_scale' => string, 'upscaling' => string, 'ray_tracing' => bool, 'shadow_quality' => string, 'texture_quality' => string, 'anti_aliasing' => string, 'ambient_occlusion' => string]`. This is the seam Phase 5's `RecommendationEngine` calls when no anchor covers a tuple.

**Done when:**
- [ ] DW-2.1: `HeuristicRecommender::recommend()` implemented exactly per the Algorithm section above.
- [ ] DW-2.2: Unit test asserts non-decreasing ordinal rank for `shadow_quality`/`texture_quality`/`anti_aliasing`/`ambient_occlusion` (a) across goals (performance ≤ balanced ≤ quality) holding `gpuTier` fixed, and (b) across tiers (low ≤ mid ≤ high ≤ enthusiast) holding `goal` fixed.
- [ ] DW-2.3: Unit test asserts capability masking: `ray_tracing_supported: false` forces `ray_tracing === false` for every tier/goal combination; `dlss_supported: false, fsr_supported: false` forces `upscaling === 'off'` for every combination; an empty `capabilities` array behaves identically to all-false.
- [ ] DW-2.4: `tests/Feature/SettingPresets/AnchorRegressionTest.php` iterates all 30 seeded `SettingPreset` rows. For each, infer implied capabilities from the anchor's own `settings` values via case-insensitive key/value pattern matching (ray tracing implied "on" if any key matches `/ray_trac/i` and its value doesn't contain "off"/"disabled"/"not supported"; upscaling implied "supported" if any key matches `/upscal/i` and its value doesn't contain "not supported"/"none"). Call `HeuristicRecommender::recommend($row->gpu_tier, $row->goal, $impliedCapabilities)` and assert no contradiction: if the heuristic says `ray_tracing === true`, the anchor must also imply it's on; if the heuristic says `upscaling !== 'off'`, the anchor must imply upscaling is supported. Test fails (drift) on any contradiction.
- [ ] DW-2.5: Unit test (dirty path) asserts `InvalidArgumentException` on an unrecognized `gpuTier` or `goal` string.
- [ ] DW-2.6: `docs/cortex-lite-build-plan.md` heuristic recommender checkboxes (lines 233-234) checked off.

---

## Test Coverage
**Level:** 100% for new code (`SettingPreset` model, `SettingPresetSeeder`, `HeuristicRecommender`).

## Test Plan
- [ ] `tests/Feature/SettingPresets/SettingPresetSeederTest.php` — 30 rows seeded, idempotent re-run, known-row shape check (DW-1.5).
- [ ] `tests/Feature/SettingPresets/SettingPresetSchemaTest.php` — factory creates a valid row, duplicate `(game, goal, gpu_tier)` throws `QueryException` (DW-1.8).
- [ ] `tests/Unit/Services/HeuristicRecommenderTest.php` — ordinal monotonicity across goal and tier (DW-2.2), capability masking incl. empty-array fail-safe (DW-2.3), invalid-input exception (DW-2.5).
- [ ] `tests/Feature/SettingPresets/AnchorRegressionTest.php` — semantic non-contradiction against all 30 anchors (DW-2.4).

## Notes
- The `(tierRank + goalRank)/5*3` ordinal formula is a placeholder-reasonable heuristic, not a value taken from any source — it exists to be internally monotonic, not to match a specific anchor's exact wording. This is intentional and matches the project's "heuristic engine is primary, anchors are calibration" framing (build-plan changelog).
- Phase 5's `RecommendationEngine` (not built here) is expected to: look up an exact anchor first, else call `HeuristicRecommender`, else apply CPU/RAM adjustments. This plan only ships the "else" branch's generator.

---

## Execution Log
- 2026-07-05: Moved the supplied anchor dataset from `docs/setting_presets.json` to `database/data/setting_presets.json` with matching SHA-256 hash.
- 2026-07-05: Added `setting_presets` migration/model/factory/seeder and registered the seeder in `DatabaseSeeder`.
- 2026-07-05: Added `HeuristicRecommender` with deterministic GPU-tier/goal rules, capability masking, and invalid-input guards.
- 2026-07-05: Added PHPUnit coverage for seeder idempotency, schema uniqueness, heuristic monotonicity/masking, and semantic anchor regression.
- 2026-07-05: Updated Phase 4 docs/checklists for PCGamingWiki stale boxes, anchor methodology, schema, and deterministic recommender behavior.
- 2026-07-05: Verified with `make test` -> 218 passed.
