# Plan: Phase 4 — PCGamingWiki integration (client + rate limiter + ingestion + UI status)
**Created:** 2026-07-03
**Status:** ready
**Complexity:** simple

---

## Context

Phase 4's hardware tier database is shipped (commits 3a8b034, 4e26e0c, 094f03a). Phase 4.0 spike passed 2026-07-03 (DECISIONS.md): PCGamingWiki publishes a firm 30 req/min limit; Steam AppID → wiki page hit rate 10/10 on the sampled set. This plan builds the **PCGamingWiki integration sub-section of Phase 4** (`docs/cortex-lite-build-plan.md` lines 216–222).

Phase 2's `SteamLibrarySynchronizer` already flags newly imported Steam-sourced games with `metadata_status='pending'` on the existing `games` table (`app/Services/SteamLibrarySynchronizer.php:56`, upsert refreshes it too). This plan is the consumer of that flag: enrich pending rows with structured graphics-capability metadata (DLSS / FSR / HDR / ultrawide / RT / D3D versions) via PCGamingWiki's MediaWiki **Cargo API**, persist to a new `game_metadata` table, and flip `games.metadata_status` to `ok` / `missing`. The enriched metadata is consumed by Phase 5's `HeuristicRecommender` to mask nonsensical recommendations (e.g., don't recommend DLSS on a game that doesn't support it).

## Constraints

- **Verified 30 req/min PCGamingWiki limit** (DECISIONS.md 2026-07-03) — enforce with a token-bucket throttle in `app/Services/RateLimiter/PcGamingWikiLimiter.php`.
- **Custom User-Agent with contact email** per PCGamingWiki etiquette (see their API page). Contact email injected via `.env` (`PCGAMINGWIKI_CONTACT_EMAIL`) and appears in every request.
- **Redis cache 7-day TTL**, deterministic key on Steam AppID only. **NO timestamps or request-unique values in cache keys** (CLAUDE.md rule 2 — timestamp-in-key bug multiplies cost).
- **Persistence:** new `game_metadata` table, FK → `games.id` cascade-delete, unique on `game_id`. Structured columns for the specific booleans the recommender needs; `raw_response` JSON column for forward-compat with any new field.
- **Ingestion job flips `games.metadata_status`:** `pending → ok` on hit (record persisted), `pending → missing` on no-match or hard failure. Rows the run **attempted** are never left `pending`; the exception is a rate-limit backoff mid-tick, where remaining unattempted rows stay `pending` for the next tick (see Phase 2 edge cases).
- **Batch cadence:** every 5 minutes, ≤ 20 games per tick — 240/hr steady-state (well under 30/min ceiling), a 100-game library drains in ~25 minutes (5 ticks).
- **Scope:** only rows where `metadata_status='pending'`. Manual entries with a user-annotated `steam_app_id` are out of scope for this plan.
- **All commands via Makefile** (`make test`, `make artisan CMD="..."`, `make composer CMD="..."`) — never raw `docker exec` or `php artisan`.
- **Test coverage: 100%** for new code (default). Unit tests for `PcGamingWikiClient` + `PcGamingWikiLimiter`, feature tests for the ingestion command.
- **Branch:** `Phase-4`; commits tagged `[Sprint 4] <verb> <what>`.
- **Model attributes over legacy properties:** `#[Fillable]` / `#[Hidden]` on new Eloquent models (code-standards.md §1).
- **No cross-cutting `oxlint` disables** on the client-side status-badge component.

---

## Implementation Phases

### Phase 1: Data layer — client, rate limiter, cache, schema, model

**Skills:** code-foundations:aposd-designing-deep-modules, code-foundations:cc-defensive-programming, code-foundations:cc-quality-practices
**Model:** sonnet
**Gate:** Standard
**Security-sensitive:** yes
**Depends on:** none
**File scope:** `app/Services/PcGamingWikiClient.php`, `app/Services/RateLimiter/**`, `app/Services/PcGamingWiki/**`, `app/Models/GameMetadata.php`, `app/Models/Game.php`, `app/Exceptions/PcGamingWiki*.php`, `database/migrations/*_create_game_metadata_table.php`, `database/factories/GameMetadataFactory.php`, `.env.example`, `config/services.php`, `tests/Unit/Services/PcGamingWiki/**`, `tests/Unit/Services/RateLimiter/**`, `docs/DECISIONS.md`

**Goal:** Ship the `game_metadata` schema + `GameMetadata` model, the `PcGamingWikiClient` service (Cargo API wrapper with Redis cache), the `PcGamingWikiLimiter` (Redis token-bucket, 30 req/min), and unit tests covering cache-hit skip, rate-limit serialisation under burst, no-match → null, malformed response → null, hard HTTP failure → null.

**Scope:**
- **IN:** Migration + factory for `game_metadata`; `GameMetadata` Eloquent model with `#[Fillable]` + `casts()` + `game()` BelongsTo; `Game::metadata()` HasOne added; `PcGamingWikiClient::fetchMetadata(int $steamAppId): ?array` with cache-first + limiter-before-HTTP + defensive parsing; `PcGamingWikiLimiter::throttle(callable $fn): mixed` using Redis-backed token bucket (30 tokens/min, refill at 1 token per 2s); exception classes `PcGamingWikiRateLimitException` (thrown on 429) and `PcGamingWikiApiException` (thrown on 5xx / network error — caller decides retry vs mark-missing); `.env.example` gets `PCGAMINGWIKI_CONTACT_EMAIL=`; `config/services.php` gets a `pcgamingwiki` block reading it; three `DECISIONS.md` entries.
- **OUT:** The artisan command / scheduled task / batch orchestration (Phase 2). React changes (Phase 2). Any Phase 5 recommender code. No HTTP endpoint exposing metadata; metadata is server-side-only consumed by Phase 5.

**Edge cases:**
- **Cache hit path must skip HTTP entirely.** `Http::fake()` in the unit test asserts zero requests when the key is pre-warmed via `Cache::put`.
- **No-match:** Cargo returns `{"cargoquery":[]}`. `PcGamingWikiClient::fetchMetadata` returns `null` (not an exception — caller flips to `'missing'` on null).
- **Malformed response:** Response is 200 but missing `cargoquery` key, or `cargoquery[0].title` is not an object. Caught, logged, returns `null`. Do NOT crash the caller.
- **429 from PCGamingWiki:** Throws `PcGamingWikiRateLimitException`. Caller (Phase 2 command) catches and leaves the row at `pending` for the next tick — do NOT flip to `missing` on rate-limit backoff, or a stampede would burn the whole queue.
- **5xx / network error:** Throws `PcGamingWikiApiException`. Caller catches and marks `missing` (durable failure signal is fine for portfolio scope; a smarter retry policy is out of scope).
- **Rate-limiter burst under concurrency:** 100 back-to-back `throttle()` calls must serialise within the 30 req/min ceiling. Redis-backed token bucket with atomic Lua script (or `Redis::eval`) — not a per-process counter. Unit test uses `Carbon::setTestNow()` + `sleep` mocks to advance time deterministically.
- **User-Agent absent → PCGamingWiki bans the key.** Test that the `User-Agent` header on every outgoing request includes `Cortex-Lite/1.0 (contact: <email>)`; missing config throws a startup-time exception, not a per-request one.
- **Cache key must be `pcgw:metadata:{steam_app_id}` — one value, no timestamp, no request context.** Unit-test the cache-key builder directly.
- **JSON `raw_response` column** stores the full Cargo response for forward-compat. Structured columns (`dlss_supported`, etc.) are extracted via a defensive `array_key_exists` + type-check chain — never a raw `$data['dlss']` that PHP-warnings.
- **Field-name drift:** PCGamingWiki's Cargo fields for these booleans are things like `DLSS`, `FSR`, `HDR`, `Ultrawide_widescreen`, `Ray_tracing`, `Direct3D_versions`, `Vulkan`. Document the exact mapping in a `PcGamingWikiFieldMap` class-level constant so future field renames are one edit, not a grep.

**Produces:**
- `App\Services\PcGamingWikiClient::fetchMetadata(int $steamAppId): ?array`
  - Returns `['direct3d_versions' => array|null, 'vulkan_supported' => bool|null, 'hdr_supported' => bool|null, 'ultrawide_supported' => bool|null, 'dlss_supported' => bool|null, 'fsr_supported' => bool|null, 'ray_tracing_supported' => bool|null, 'raw_response' => array]` on hit.
  - Returns `null` on no-match or malformed response (caller flips `metadata_status` to `'missing'`).
  - Throws `PcGamingWikiRateLimitException` on 429 (caller leaves row at `'pending'`).
  - Throws `PcGamingWikiApiException` on 5xx / network / timeout (caller flips to `'missing'`).
  - Wraps caching (Redis, 7d TTL, key `pcgw:metadata:{steam_app_id}`) and rate limiting (`PcGamingWikiLimiter::throttle`) internally.
- `App\Services\RateLimiter\PcGamingWikiLimiter::throttle(callable $fn): mixed` — token-bucket, 30 tokens/min, refill 1 token / 2s, blocks (sleep + retry) up to a configurable ceiling (default 15s) before throwing `PcGamingWikiRateLimitException`.
- `App\Models\GameMetadata` — Eloquent model with `#[Fillable]` on the seven structured columns + `raw_response` + `game_id`; `casts()` returns `['direct3d_versions' => 'array', 'raw_response' => 'array', 'vulkan_supported' => 'boolean', 'hdr_supported' => 'boolean', 'ultrawide_supported' => 'boolean', 'dlss_supported' => 'boolean', 'fsr_supported' => 'boolean', 'ray_tracing_supported' => 'boolean']`; `game()` BelongsTo.
- `Game::metadata()` HasOne on the existing `App\Models\Game` model.
- Exceptions `App\Exceptions\PcGamingWikiRateLimitException`, `App\Exceptions\PcGamingWikiApiException` (both extend `RuntimeException`).
- Config seam: `config('services.pcgamingwiki.contact_email')` reading `PCGAMINGWIKI_CONTACT_EMAIL`.

**Done when:**
- [ ] DW-1.1: Migration `create_game_metadata_table` shipped with `up()` + `down()`; columns: `id`, `game_id` (FK `games.id` cascade-delete, **unique**), `direct3d_versions` (json nullable), `vulkan_supported` (boolean nullable), `hdr_supported` (boolean nullable), `ultrawide_supported` (boolean nullable), `dlss_supported` (boolean nullable), `fsr_supported` (boolean nullable), `ray_tracing_supported` (boolean nullable), `raw_response` (json), `timestamps`.
- [ ] DW-1.2: `App\Models\GameMetadata` shipped with `#[Fillable]` on the six structured booleans + `direct3d_versions` + `raw_response` + `game_id`; `casts()` per **Produces**; `game()` BelongsTo; `Game::metadata()` HasOne added. `database/factories/GameMetadataFactory.php` shipped alongside (per `docs/code-standards.md §7`, one factory per model, created together with model + migration).
- [ ] DW-1.3: `App\Services\PcGamingWikiClient` shipped implementing the **Produces** contract exactly (return shape, null-on-no-match, exception classes for 429 vs 5xx). Cache-first via `Cache::store('redis')->remember('pcgw:metadata:'.$steamAppId, now()->addDays(7), $fn)`. Cache key builder is a private method unit-tested in isolation. Every outgoing HTTP request carries `User-Agent: Cortex-Lite/1.0 (contact: <email from config>)` — missing config throws at construction time, not per request. `PcGamingWikiFieldMap` class holds the Cargo → local-column mapping as a `const` (see Edge cases — Cargo field-name drift). Response parsing **caps `raw_response` at ≤ 32 KB after JSON re-encode and rejects nesting deeper than 8 levels** before persist, so a malicious or malformed upstream cannot bloat the row or trip Phase 5's JSON accessors.
- [ ] DW-1.4: `App\Services\RateLimiter\PcGamingWikiLimiter` shipped as a Redis token-bucket (30 tokens/min, refill 1 token / 2s, wait up to 15s before `PcGamingWikiRateLimitException`). Atomicity via `Redis::eval` Lua script or `Redis::pipeline` + `WATCH/MULTI/EXEC` — **not** a plain `get` + `decr` race.
- [ ] DW-1.5: Exceptions `App\Exceptions\PcGamingWikiRateLimitException`, `App\Exceptions\PcGamingWikiApiException` shipped (both extend `\RuntimeException`).
- [ ] DW-1.6: `.env.example` adds `PCGAMINGWIKI_CONTACT_EMAIL=` with a comment; `config/services.php` gets a `pcgamingwiki` block reading it. `PcGamingWikiClient` throws a startup-time exception if the config is empty (fail fast).
- [ ] DW-1.7: Unit tests under `tests/Unit/Services/PcGamingWiki/`:
  - **PcGamingWikiClientTest**: cache-hit-skips-HTTP (pre-warm cache, `Http::fake()`, assert zero requests, assert returned shape); happy-path Cargo hit → parsed structured shape; no-match (`{"cargoquery":[]}`) → `null`; malformed response (missing `cargoquery` key) → `null`; 429 → `PcGamingWikiRateLimitException`; 500 → `PcGamingWikiApiException`; network error (`Http::fake(fn () => throw new ConnectionException)`) → `PcGamingWikiApiException`; User-Agent header asserted on every outgoing request; missing `contact_email` config → startup exception.
  - **PcGamingWikiCacheKeyTest**: builder produces `pcgw:metadata:730` for AppID 730, is a pure function of the AppID, contains no timestamp / no random.
  - **PcGamingWikiLimiterTest**: burst of 100 sequential `throttle()` calls (via `Carbon::setTestNow` advancing time deterministically) never exceeds 30 tokens in any rolling 60-second window; a 31st call within the same minute blocks and eventually throws `PcGamingWikiRateLimitException` after the 15s ceiling; token refill re-permits calls after 2s of virtual time.
- [ ] DW-1.8: `docs/DECISIONS.md` gets three new dated entries: (a) **Redis token-bucket over Laravel's built-in `RateLimiter`** — atomic across processes via Lua, matches the exact-quota shape PCGamingWiki enforces; (b) **7-day Redis TTL with AppID-only cache key** — reiterates CLAUDE.md rule 2 rationale; (c) **Null-return contract for no-match, exception for rate-limit / hard failure** — separates "wiki has no page for this game" (durable, mark missing) from "we hit the limit" (transient, keep pending) at the type level.
- [ ] DW-1.9: `make test` all green — existing suite + new unit tests (≥ 12 new tests across the three unit-test classes).

**Rollback:** Migration `down()` drops `game_metadata` cleanly. No other rollback needed — no destructive writes to existing tables in Phase 1.

---

### Phase 2: Ingestion command + scheduler + React status badge

**Skills:** code-foundations:cc-routine-and-class-design, code-foundations:cc-defensive-programming, code-foundations:cc-quality-practices
**Model:** sonnet
**Gate:** Standard
**Depends on:** Phase 1
**File scope:** `app/Console/Commands/EnrichGameMetadataCommand.php`, `app/Actions/PcGamingWiki/**`, `routes/console.php`, `client/src/components/games/**`, `client/src/pages/Library.jsx`, `tests/Feature/PcGamingWiki/**`, `docs/DECISIONS.md`, `docs/TROUBLESHOOTING.md`

**Goal:** Ship the `games:enrich-metadata` artisan command that consumes `PcGamingWikiClient` to enrich `metadata_status='pending'` rows, register it on the scheduler (every 5 min, `->withoutOverlapping()`), add a per-row status badge to the React library page, and feature-test the batch-happy-path, rate-limit-respect, no-match, per-game-failure-isolation flows.

**Scope:**
- **IN:** `EnrichGameMetadataCommand` (`games:enrich-metadata`) with `--limit=` option (default 20); `EnrichPendingGamesAction` (or single command handler — see Edge cases for the extraction decision); scheduler registration in `routes/console.php` at 5-min cadence with `->withoutOverlapping()->runInBackground()`; small React `MetadataStatusBadge` component + integration into the existing library-row rendering; PHPUnit feature tests under `tests/Feature/PcGamingWiki/`; `docs/TROUBLESHOOTING.md` entry for the 429-observed-in-logs / user-facing "missing" states; one `DECISIONS.md` entry (batch size + cadence rationale).
- **OUT:** Any Phase 5 recommender consumption. Metadata REST endpoint (Phase 5 reads the model directly). Retry-with-backoff for `missing` rows (durable-failure → stay `missing` until Phase 5+ builds an operator-facing reset).

**Edge cases:**
- **Per-game failure must not abort the batch.** `foreach` loop wraps each `fetchMetadata` call in a `try / catch (PcGamingWikiApiException)` — on catch, mark `missing`, log warning with `game_id` + `steam_app_id`, continue with next game.
- **`PcGamingWikiRateLimitException` mid-batch:** catch at the batch level, `Log::warning` the tick, exit gracefully (row stays `pending` for next tick — `->withoutOverlapping()` prevents thundering herd).
- **Race with a concurrent `steam:sync-all` marking new games `pending`:** command's `->where('metadata_status', 'pending')` picks up the new rows on the next tick; no locking needed since ingestion is idempotent (upsert-on-`game_id`-unique).
- **Empty pending queue** → command exits `0` with a "nothing to enrich" info log, does NOT touch PCGamingWiki.
- **Game has no `steam_app_id` but is `metadata_status='pending'`** — should not happen in practice (only Steam sync sets `pending`), but defensively skip and mark `missing` with a warning log rather than crashing.
- **Duplicate `game_metadata` on retry:** the unique index on `game_id` + `updateOrCreate` on the model prevents duplicate rows.
- **React badge accessibility:** each state has `aria-label` (`"Metadata ready"`, `"Metadata pending"`, `"Metadata unavailable"`) — colour + shape only fails colour-blind users.
- **Scheduler `->withoutOverlapping()` requires a mutex driver** — Laravel's default is Redis when `CACHE_STORE=redis`; verify by asserting the mutex key `framework/schedule-*` appears in Redis during a running tick (documented in `TROUBLESHOOTING.md`, not tested).
- **Action vs command-handler:** if the loop body grows past ~30 lines with try/catch branches, extract to `App\Actions\PcGamingWiki\EnrichPendingGamesAction::execute(int $limit): array` (returns `['enriched' => N, 'missing' => N, 'skipped' => N]`) — per `docs/code-standards.md §7`, actions own multi-write orchestration. If it stays small, keep it inline. Decide during implementation; either choice is defensible.

**Produces:**
- Artisan command `games:enrich-metadata [--limit=20]` — invocable manually via `make artisan CMD="games:enrich-metadata"`.
- Scheduler registration in `routes/console.php`: `Schedule::command('games:enrich-metadata')->everyFiveMinutes()->withoutOverlapping()->runInBackground();`.
- `client/src/components/games/MetadataStatusBadge.jsx` — pure component, prop `status: 'pending' | 'ok' | 'missing'`, renders coloured dot + `aria-label`. Imported by `client/src/pages/Library.jsx` (or wherever library rows are rendered — verify actual filename during implementation) into each game row.

**Done when:**
- [ ] DW-2.1: `App\Console\Commands\EnrichGameMetadataCommand` shipped; signature `games:enrich-metadata {--limit=20}`; queries `Game::where('metadata_status', 'pending')->whereNotNull('steam_app_id')->limit($limit)->get()`; for each, calls `PcGamingWikiClient::fetchMetadata($game->steam_app_id)`; on non-null result, `GameMetadata::updateOrCreate(['game_id' => $game->id], $data)` + flip `games.metadata_status='ok'`; on null result, flip `games.metadata_status='missing'`; on `PcGamingWikiApiException`, catch + log + flip `'missing'` + continue; on `PcGamingWikiRateLimitException`, catch at batch level + log + exit cleanly.
- [ ] DW-2.2: `routes/console.php` registers the command via `Schedule::command('games:enrich-metadata')->everyFiveMinutes()->withoutOverlapping()->runInBackground()`.
- [ ] DW-2.3: If loop body exceeds ~30 lines with branches, extracted to `App\Actions\PcGamingWiki\EnrichPendingGamesAction::execute(int $limit): array` per `code-standards.md §7`. Extraction decision documented inline in the command with a one-line WHY.
- [ ] DW-2.4: `client/src/components/games/MetadataStatusBadge.jsx` shipped: prop `status`, three states rendered as coloured dot + `aria-label` + `role="status"`; wired into the library-row rendering (verify the exact page/component during implementation — either `Library.jsx` or a `GameRow` component); no `oxlint` warnings.
- [ ] DW-2.5: Feature tests under `tests/Feature/PcGamingWiki/`:
  - **EnrichGameMetadataCommandTest**: 3 pending games + `Http::fake()` returning valid Cargo → all flipped to `'ok'`, 3 `game_metadata` rows persisted, exit 0; 1 pending game with empty Cargo → flipped to `'missing'`, no `game_metadata` row; 1 pending game → `Http::fake()` returns 500 → flipped to `'missing'`, warning logged; mid-batch `PcGamingWikiApiException` on game 2 of 5 → games 1, 3, 4, 5 still processed (isolation test); `PcGamingWikiRateLimitException` mid-batch → command exits cleanly, remaining rows stay `'pending'`; empty pending queue → exit 0, no HTTP calls; game with `metadata_status='pending'` but `steam_app_id=null` → skipped, flipped to `'missing'`, warning logged; scheduler registration asserted via `Schedule::events()` or `artisan('schedule:list')` output containing `games:enrich-metadata` at 5-min cadence.
  - **CacheHitFeatureTest**: pre-warm `Cache::store('redis')` with a valid payload for AppID X, run command against a pending game with that AppID, assert zero outgoing HTTP requests via `Http::assertNothingSent()`, assert row flipped to `'ok'`.
- [ ] DW-2.6: `docs/TROUBLESHOOTING.md` gets one new entry: **"Games stuck at `metadata_status='pending'`"** — check scheduler is running (`make logs`), check `PCGAMINGWIKI_CONTACT_EMAIL` is set (fail-fast at startup), check Redis is up (mutex + rate-limiter + cache all depend on it); **"Games at `metadata_status='missing'` — how to retry"** — clear via `Game::where('metadata_status', 'missing')->update(['metadata_status' => 'pending'])` in `make shell`.
- [ ] DW-2.7: `docs/DECISIONS.md` gets one new dated entry: **5-min cadence + 20-game batch rationale** — 240/hr steady-state fits comfortably under 30 req/min, 100-game library drains in ~25 minutes (5 ticks), avoids burst-drain that would starve real user requests of tokens.
- [ ] DW-2.8: `make test` all green — existing suite + Phase 1 unit tests + new feature tests (≥ 8 new tests).
- [ ] DW-2.9: `oxlint` clean on the client — new component ships no warnings.

**Rollback:** Remove the scheduler entry in `routes/console.php` and the command file; existing `metadata_status` values on `games` stay valid enum values (no schema change in this phase). Migration rollback from Phase 1 would separately drop `game_metadata`.

---

## Test Coverage

**Level:** 100% for new code.

## Test Plan

- [ ] **Unit (Phase 1):** cache-key builder pure-function determinism; `PcGamingWikiClient` cache-hit-skips-HTTP; happy-path Cargo response → parsed shape; no-match Cargo → `null`; malformed Cargo → `null`; 429 → `PcGamingWikiRateLimitException`; 500 → `PcGamingWikiApiException`; network / timeout → `PcGamingWikiApiException`; User-Agent header on every outgoing request; startup fail-fast when `contact_email` config missing.
- [ ] **Unit (Phase 1) dirty:** burst-of-100 through `PcGamingWikiLimiter` never exceeds 30 tokens in any rolling 60-second window; 31st call in a minute blocks and throws after 15s ceiling; token refill re-permits after 2s of virtual time.
- [ ] **Feature (Phase 2):** 3-pending happy path → all `'ok'`, 3 rows in `game_metadata`; empty-Cargo → `'missing'`, no row; empty pending queue → exit 0, zero HTTP; cache-hit path (pre-warmed Redis) → command runs, no HTTP fired (`Http::assertNothingSent()`), row still flipped to `'ok'`; scheduler registration asserted via `schedule:list`.
- [ ] **Feature (Phase 2) dirty:** 500 → `'missing'` + warning; mid-batch `PcGamingWikiApiException` on one game → remaining games still processed (isolation); `PcGamingWikiRateLimitException` mid-batch → clean exit, remaining rows stay `'pending'`; `pending` + `steam_app_id=null` → skipped, flipped `'missing'` + warning.

---

## Notes

- **Not exposing metadata via a REST endpoint yet.** Phase 5's `HeuristicRecommender` reads the `GameMetadata` model server-side. When a metadata-view endpoint is needed (Phase 5 UI or debug tooling), it gets its own IDOR-boundary test at that time.
- **Anchor games** (Cyberpunk, CS2, etc. — the 10 titles from the build plan's anchor dataset) will flow through the same pipeline **if they're Steam-owned by the demo account**. If not, Phase 5 handles the fallback. Explicitly not pre-seeded here — one path is simpler than two.
- **Cargo field names are unstable across MediaWiki upgrades.** The `PcGamingWikiFieldMap` constant centralises the mapping; a schema drift on PCGamingWiki's side is a one-line edit + a re-run of the ingestion command.
- **Redis is now load-bearing across five use cases** (Steam cache, PCGamingWiki cache, PCGamingWiki rate limiter, scheduler mutex, LLM cache in Phase 5). Reinforces the DECISIONS.md rationale — Redis is not "for the resume".

---

## Execution Log

_To be filled during /code-foundations:build_
