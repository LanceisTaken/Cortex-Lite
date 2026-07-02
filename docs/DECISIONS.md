# Decisions

ADR-style log of non-obvious architectural and implementation choices.

Format per entry:

```
### [Decision title]
**Date:** YYYY-MM-DD
**Decision:** What was chosen.
**Rationale:** Why.
**Alternatives considered:** What was rejected and why.
**Consequences:** Any tradeoffs or follow-on effects.
```

---

### Laravel 13 + PHP 8.4 (spec drift from Laravel 11 / PHP 8.3)
**Date:** 2026-07-01
**Decision:** Scaffolded on Laravel 13.8 running on PHP 8.4. The original build plan and CLAUDE.md spec called for Laravel 11 / PHP 8.3.
**Rationale:** `composer create-project laravel/laravel` without a version pin installed the current stable (Laravel 13, released Q1 2026), which in turn requires PHP ≥ 8.4.1. For a portfolio project the current stable is strictly better — it's what a Razer interviewer would expect a 2026 hire to be shipping. Downgrading to Laravel 11 would mean pinning to a two-major-versions-old release for no engineering reason.
**Alternatives considered:** Pin to Laravel 11 (`^11.0`) with PHP 8.3 to match the spec verbatim (rejected — no benefit, weaker signal on the resume). Pin to Laravel 12 (rejected — same argument, one version behind current).
**Consequences:** CLAUDE.md stack reference updated to Laravel 13 / PHP 8.4 / React 19. Sanctum, Cashier, Scheduler, Queue APIs are unchanged from 11 → 13 for the surface area we use. Docker base image is `php:8.4-fpm-alpine`. CI runs on PHP 8.4.

### FPM workers run as root in the dev container
**Date:** 2026-07-01
**Decision:** In the dev `docker/app/Dockerfile`, override the FPM pool config to run workers as `root` (not the default `www-data`), and start FPM with `--allow-to-run-as-root`.
**Rationale:** On Windows and macOS Docker Desktop, bind-mounted host files show as root-owned inside the container regardless of the host user. The default www-data FPM worker cannot then write to `storage/` or `bootstrap/cache/`, which breaks Blade view compilation, session storage, and every other Laravel runtime write. Running FPM as root in dev sidesteps this cross-platform mount-ownership problem entirely.
**Alternatives considered:** `chmod -R 0777 storage bootstrap/cache` (rejected — leaves world-writable directories in the repo, ugly). Entrypoint script that fixes ownership on startup (rejected — extra moving part for a dev-only concern). Match container UID to host UID via build args (rejected — Windows doesn't have a meaningful UID to match; only works on Linux hosts).
**Consequences:** Dev-only. Production images (built via the multi-stage Dockerfile from ECR) bake code directly into the image with correct ownership, so www-data can run there normally. When we build the prod app image in Phase 6, use a separate Dockerfile (or a build target) that keeps the default FPM user.

### Sanctum SPA cookie auth over API tokens
**Date:** 2026-07-01
**Decision:** Use Laravel Sanctum in SPA mode (stateful cookie-based auth with CSRF) for the React client.
**Rationale:** The React frontend is first-party and served from the same origin as the API in production. Cookie-based auth with CSRF is Sanctum's documented pattern for first-party SPAs — the browser handles session cookies, CSRF middleware protects state-changing requests, and there's no token storage problem to solve in JS.
**Alternatives considered:** API tokens (Sanctum's `HasApiTokens`). Rejected because tokens require secure client-side storage (localStorage is XSS-exposed, in-memory loses state on reload) and offer no benefit for a first-party SPA. Tokens would be appropriate for third-party clients or mobile apps only.
**Consequences:** Must configure `SANCTUM_STATEFUL_DOMAINS`, CORS `Access-Control-Allow-Credentials: true`, and Axios `withCredentials: true`. Client must hit `GET /sanctum/csrf-cookie` before the first authenticated request. Missing any of the three is the #1 Sanctum SPA gotcha (see TROUBLESHOOTING.md).

### Redis for Steam Web API response caching
**Date:** 2026-07-01
**Decision:** Cache Steam Web API responses in Redis with a 1-hour TTL.
**Rationale:** Steam's per-key quota is 100k calls/day and player-owned-games data changes slowly. Caching reduces latency on repeat views, protects against quota exhaustion during traffic spikes or bugs, and eliminates redundant network round-trips.
**Alternatives considered:** No caching (rejected — trivially exhausts quota if a user reloads their library repeatedly). Database-backed cache (rejected — extra query per fetch, no TTL semantics without extra work). Longer TTL (rejected — users expect a fresh library shortly after buying a game).
**Consequences:** Cache invalidation is time-based only; a user who buys a game must wait up to 1 hour to see it unless they trigger a manual sync. Acceptable tradeoff for portfolio scope.

### Redis for PCGamingWiki response caching
**Date:** 2026-07-01
**Decision:** Cache PCGamingWiki Cargo query responses in Redis with a 7-day TTL.
**Rationale:** PCGamingWiki data (graphics options, DLSS/FSR/HDR support, D3D versions) changes on the order of months, not hours. A 7-day TTL is well within the "still fresh" window and dramatically cuts request volume, making rate-limit compliance trivial.
**Alternatives considered:** Shorter TTL (rejected — no upside; the data doesn't change fast enough to matter). Persistent DB storage only (kept — the `game_metadata` table is the durable copy; Redis is the hot layer that avoids repeat Cargo hits during ingestion).
**Consequences:** New PCGamingWiki fields don't propagate until cache expiry or a manual flush. Acceptable for a static/slow-moving external source.

### Redis for LLM response caching
**Date:** 2026-07-01
**Decision:** Cache LLM (Claude Haiku) responses in Redis keyed by the deterministic inputs to the recommendation.
**Rationale:** Two users with the same GPU tier, CPU tier, RAM bucket, game, and goal get identical prose — running the LLM twice wastes tokens and adds latency. Caching by the deterministic input tuple gives high hit rate for popular (game × hardware × goal) combinations, capping cost.
**Alternatives considered:** No caching (rejected — costs scale linearly with request volume, no ceiling). Cache-by-response-hash (rejected — cache is populated only after the call is made, so it doesn't prevent the first hit).
**Consequences:** Cache key construction MUST NOT include timestamps or request-unique values — a timestamp-in-key bug multiplies LLM cost 1000×. Enforced via a dedicated unit test on the cache key builder. Forward-mode key: `(game_id, gpu_tier, cpu_tier, ram_bucket, goal)`. Reverse-mode key: `hash(diff_structure, hardware_tier, goal)`.

### Redis for freemium quota state
**Date:** 2026-07-01
**Decision:** Use Redis as the fast lane for rate-limit / quota-related state (throttle counters, token buckets for external APIs). Durable usage-event records still live in MySQL for the rolling-window count.
**Rationale:** Redis is the correct primitive for per-user rate limits (Laravel's throttle middleware backs onto it) and external-API rate-limit token buckets (PCGamingWiki, Steam). MySQL is the source of truth for the quota check itself (auditable, transactional), Redis is where the millisecond-critical counters live.
**Alternatives considered:** MySQL-only for everything (rejected — throttle counters would create write hotspots and lose sub-second precision). Redis-only for quota (rejected — no durability; a Redis restart would grant free extra calls).
**Consequences:** Redis is now load-bearing across four use cases (Steam cache, PCGamingWiki cache, LLM cache, throttle state) — enough real justification that "Redis for the resume" is not a valid critique.

### Rolling 30-day window over reset job for free-tier quotas
**Date:** 2026-07-01
**Decision:** Enforce the 3-recommendations / 5-reverse-mode-calls free-tier limit via a rolling `count where user_id = ? and type = 'recommend' and created_at >= now() - interval 30 day` query on the usage-events table. No counter column, no monthly reset job.
**Rationale:** Rolling windows are fair (users can't game a calendar boundary), simple (no scheduled job, no risk of the job failing to run), and thundering-herd-free (nothing resets at midnight for all users at once). The count query is indexed and cheap.
**Alternatives considered:** Counter column reset by a scheduled job at month boundary (rejected — a stampede on the first of the month if it triggers billing/notification logic, plus the "did the job run?" reliability concern). Calendar-month counter with `used_this_month` column (rejected — same problems, worse UX at month boundaries).
**Consequences:** Every recommendation/reverse-mode call must insert a row into the usage-events table before the quota check completes. The events table becomes append-only; growth is bounded by real usage, and old rows can be pruned by a low-priority scheduled cleanup after 60 days.

### LLM scoped to prose only (never decides settings)
**Date:** 2026-07-01
**Decision:** The LLM (`ExplanationGenerator`) receives structured input (a settings recommendation or a settings diff) and produces natural-language prose only. It never constructs or modifies the recommendation itself.
**Rationale:** Hallucination cannot affect user-facing settings advice if the LLM cannot decide settings. The rule-based `RecommendationEngine` and `SettingsDiffEngine` are the source of truth; the LLM is a formatter. This is the honest interview answer to "what if the LLM hallucinates?"
**Alternatives considered:** LLM decides settings, rule engine validates (rejected — validation is hard to make exhaustive; users could still hit bad recommendations for niche games). LLM decides and explains in one prompt (rejected — same hallucination surface, harder to unit-test, no deterministic fallback).
**Consequences:** Every recommendation is deterministic and unit-testable end-to-end (input → structured settings). LLM outages degrade the UX (prose replaced by a static fallback string) but never break the core feature. This constraint is a load-bearing part of the interview narrative.

### Reverse mode as rule-based diff (SettingsDiffEngine), not LLM judgment
**Date:** 2026-07-01
**Decision:** Reverse mode compares user-pasted settings JSON against the canonical preset via a deterministic `SettingsDiffEngine`. The LLM only explains the diff in prose.
**Rationale:** Same principle as forward mode: LLM cannot judge "your settings are wrong" because that's a hallucination surface with high user impact. The diff engine produces `{ setting: "current → recommended", ... }` deterministically; the LLM turns that into a paragraph.
**Alternatives considered:** LLM judges pasted settings directly (rejected — the exact hallucination story we're avoiding in forward mode; would break the safety narrative across modes). Diff-only, no LLM (rejected — the prose is the UX differentiator on the free demo path).
**Consequences:** Reverse mode is fully testable with fixture inputs and expected diff outputs. The LLM call sits behind the same cache and fallback machinery as forward mode.

### GPU tier absolute thresholds over percentile
**Date:** 2026-07-01
**Decision:** Classify GPUs into 4 tiers (Low / Mid / High / Enthusiast) using absolute G3D Mark thresholds, not percentiles across a benchmark dataset.
**Rationale:** A percentile cut across all PassMark history skews modern hardware low — half the dataset is 15-year-old cards, so a GTX 1060 lands in "high tier." Users see modern hardware; the tiering must reflect modern expectations. Absolute thresholds (Low <8k, Mid 8k–14k, High 14k–22k, Enthusiast ≥22k) match how gamers actually talk about their cards.
**Alternatives considered:** Percentile-based tiering ingested from a Kaggle PassMark dump (rejected — see above; produces inverted-feeling tiers). ML-based clustering (rejected — massively over-engineered for 60 rows of curated data).
**Consequences:** The tier table is hand-curated and version-controlled (`gpus.json`). Adding new GPUs is a small, reviewable diff. Same shape used for CPUs with single-thread benchmark thresholds.

### EC2 t3.small over t2.micro for the live deploy
**Date:** 2026-07-01
**Decision:** Deploy to EC2 `t3.small` (2 GB RAM) for the 48-hour demo window, not `t2.micro` (1 GB).
**Rationale:** The Compose stack — nginx, PHP-FPM (app), scheduler, queue worker, plus in-container Redis — will OOM on 1 GB the first time a Steam sync job and an LLM call overlap, which is exactly what happens during a live demo. At ~$0.02/hr, 48 hours of t3.small ≈ $1 against the $200 credit pool. The savings from t2.micro are not real once you factor in the demo failing.
**Alternatives considered:** t2.micro (rejected — OOM risk during the demo; false economy). t3.medium (rejected — no meaningful additional headroom for this workload; wastes credit).
**Consequences:** `docker stats` check before declaring the deploy live is part of the checklist. Queue worker is the drop-first candidate if we somehow still hit memory pressure.

### CloudFront cache-behavior carve-out for `/api/stripe/webhook`
**Date:** 2026-07-01
**Decision:** Add a dedicated CloudFront cache behavior for the path pattern `/api/stripe/webhook`: caching disabled (TTL 0), all headers forwarded, request body unmodified, POST-only.
**Rationale:** CloudFront's default behavior strips several headers (including `Stripe-Signature`) and can modify the request body, both of which break Stripe's signature verification. Signature verification is the only thing keeping the webhook endpoint safe — without the carve-out, either the webhook fails silently in production or (worse) we'd have to disable signature checks.
**Alternatives considered:** Route the webhook to a separate origin that bypasses CloudFront (rejected — extra infra for one endpoint). Disable signature verification (rejected — obvious security hole, and the topic Stripe interviews always test).
**Consequences:** The carve-out must be applied before testing webhooks end-to-end; test with `stripe trigger checkout.session.completed` against the live CloudFront URL during the 48-hour window, not after. Documented in TROUBLESHOOTING.md.

### Sync LLM call over async queue for v1
**Date:** 2026-07-01
**Decision:** Call the LLM synchronously from the recommendation/reverse-mode request handlers in v1. The UI shows a loading state; response returns in ~2–3s on cache miss, ~50ms on cache hit.
**Rationale:** Sync is simpler (no queue plumbing, no polling endpoint, no client-side state machine). ~2–3s is inside "acceptable for a considered action" and users expect a short wait for AI features. Cache hits are effectively instant, which will be the common path for popular game/hardware tuples.
**Alternatives considered:** Async via queue + client polling (rejected for v1 — more code across backend, queue worker, and client for a UX gain that's marginal at this latency). Server-Sent Events or WebSocket streaming (rejected — extra infra, no obvious payoff for a 3-second call).
**Consequences:** The `queue` service isn't heavily exercised by the LLM path in v1 — it earns its keep via Steam sync jobs and scheduled tasks. If cold-path latency becomes a real complaint, we can move `ExplanationGenerator` to async in a later phase without changing the recommendation-engine contract.

### Cashier installed in Phase 1 (pulled forward from Phase 5) for DELETE /api/account
**Date:** 2026-07-01
**Decision:** Install Laravel Cashier during Phase 1 so `DELETE /api/account` can actually cancel a subscription via `$user->subscription('default')?->cancelNow()`. Cashier's migrations, `Billable` trait, and env keys are wired now; Stripe routes, checkout, and webhook remain in Phase 5.
**Rationale:** The alternative — a `// TODO(phase-5)` comment or a swappable `BillingService` interface — leaves a hole in the delete-account resume bullet that we'd have to defend as half-shipped. Cashier install is additive: no runtime cost until a real subscription exists.
**Alternatives considered:** Null billing service in Phase 1, real Stripe in Phase 5 (rejected — extra scaffolding for a one-line cutover). Defer the whole endpoint to Phase 5 (rejected — plan explicitly lists it in Phase 1, and the security signal of a working GDPR-shaped delete belongs with the auth surface).
**Consequences:** Three additional migrations run in Phase 1. `Billable` on User. No Stripe API calls until Phase 5 (routes/webhook/UI still deferred).

### Vite proxy for dev instead of separate origins + CORS
**Date:** 2026-07-01
**Decision:** Dev-time browser sees `http://localhost:5173` only; Vite proxies `/api/*` and `/sanctum/*` to nginx at `:8080`.
**Rationale:** Matches production topology (nginx serves React and proxies `/api` — same origin). Removes cross-origin cookie / CORS-credentials edge cases from the dev loop, which is the exact class of bugs `TROUBLESHOOTING.md` calls out as the #1 Sanctum SPA gotcha.
**Alternatives considered:** Two origins + CORS + `withCredentials` (rejected — reproduces a production-inconsistent dev environment for zero benefit).
**Consequences:** `SANCTUM_STATEFUL_DOMAINS=localhost:5173`; `client/vite.config.js` gains a proxy block; CORS config kept for prod symmetry.

### Delete-account extracted to an Action; other auth flows stay in-controller
**Date:** 2026-07-01
**Decision:** `App\Actions\Auth\DeleteAccountAction` owns the transaction (cancel subscription → delete user); Register/Login/Logout stay in thin controllers.
**Rationale:** Only delete-account has real branching (subscription check, transaction, rollback semantics). The other auth flows are framework orchestration — extracting them into actions inflates the codebase without unit-test payoff.
**Alternatives considered:** Extract all four (rejected — over-engineering). Leave delete-account in controller (rejected — non-trivial rollback logic deserves isolated unit tests).
**Consequences:** Portfolio signals Actions pattern in the right place. Controllers stay 4–8 lines each.

### Custom `verified` middleware returning 409 for JSON APIs
**Date:** 2026-07-01
**Decision:** Override the framework's `EnsureEmailIsVerified` middleware with `app/Http/Middleware/EnsureEmailIsVerified.php`, aliased to `verified` in `bootstrap/app.php`. JSON requests from an unverified-but-authenticated user get `409 Conflict` instead of the framework default `403`.
**Rationale:** The frontend needs to distinguish "logged in but unverified" (a resolvable, expected state — show the resend-verification banner) from "forbidden" (a real authorization failure — show an error page). Reusing 403 for both would force the SPA to inspect the response body to tell them apart.
**Alternatives considered:** Keep the framework default 403 and disambiguate client-side via response body inspection (rejected — brittle, couples the frontend to error-message text). Custom header on a 403 (rejected — 409 is the more semantically correct status code for "conflicts with the current state of the resource" and needs no extra parsing).
**Consequences:** Non-JSON (web) requests keep the standard redirect-to-verification-notice behavior — only the JSON branch changes. Any new route gated by `verified` inherits this behavior automatically.

### Store game playtime in minutes
**Date:** 2026-07-02
**Decision:** The `games` table stores playtime as `playtime_minutes`, an unsigned integer. The React UI formats it as hours and minutes for humans.
**Rationale:** Steam's `playtime_forever` value is already measured in minutes. Matching that unit avoids lossy conversions, fractional-hour rounding, and follow-up migration work when Steam sync lands.
**Alternatives considered:** `hours_played` as a decimal, matching an early build-plan name (rejected because it diverges from Steam's API contract and makes exact import harder).
**Consequences:** Manual CRUD accepts minutes directly. Any future chart or session aggregate should convert for presentation only, not change storage units.

### Manual games schema includes Steam-sync fields from day one
**Date:** 2026-07-02
**Decision:** Add nullable `steam_app_id`, server-owned `source`, `metadata_status`, and nullable `cover_url` columns in the initial `games` migration even though this sprint only ships manual CRUD.
**Rationale:** Steam import is the next Phase 2 sub-phase. Including these columns now lets sync code upsert into the same table without another schema change, and lets manual entries later be annotated with a Steam app id.
**Alternatives considered:** Keep the manual CRUD schema minimal and add a Steam migration later (rejected because it creates avoidable migration churn and a less stable contract for the React library page). Separate manual and Steam game tables (rejected because the library should behave as one user-scoped collection).
**Consequences:** `source` and `metadata_status` are not accepted from client payloads. The controller sets manual defaults today; Steam sync can set Steam-specific values later.

### Cross-user game access returns 404
**Date:** 2026-07-02
**Decision:** `PUT /api/games/{game}` and `DELETE /api/games/{game}` resolve through the authenticated user's `games()` relationship and return 404 for cross-user ids.
**Rationale:** Returning 403 would confirm that another user's game id exists. A 404 preserves resource opacity and matches the IDOR testing rule for user-owned endpoints.
**Alternatives considered:** Policies returning 403 (rejected for existence disclosure). Globally scoped route-model binding (deferred; explicit user-scoped lookup in the controller is clearer for this first resource).
**Consequences:** Tests assert 404, not 403, for cross-user update and delete. Future user-owned resources should follow the same response posture unless there is a product reason to reveal existence.

### Hand-rolled Steam OpenID verification
**Date:** 2026-07-02
**Decision:** Implement Steam OpenID verification in a small in-repo `SteamOpenIdVerifier` instead of adding a third-party package.
**Rationale:** The protocol surface we need is tiny: guard the expected OpenID parameters, post `check_authentication` back to Steam, and extract the SteamID64 from `claimed_id`. Keeping that logic visible in the repo makes the security story reviewable in an interview and avoids adding a dependency for a small amount of code.
**Alternatives considered:** `xPaw/SteamOpenID` (rejected because the code we need is small, security-critical, and easier to reason about directly in the repo). Inlining the protocol logic in a controller (rejected because the verification logic deserves focused tests and a single seam).
**Consequences:** We own the protocol guards and tests. The verifier hard-codes Steam's OpenID endpoint for the check-authentication POST, which closes off request-driven OP/SSRF surprises.

### Split Steam cache TTLs by endpoint
**Date:** 2026-07-02
**Decision:** Cache Steam owned-games responses for 1 hour and player summaries for 60 seconds in Redis.
**Rationale:** Owned-games data changes slowly enough that a 1 hour cache meaningfully cuts quota and latency. Profile visibility is different: if a user fixes Steam privacy settings after a failed sync, a 1 hour summary cache would trap them behind a stale private-profile result. A 60 second summary cache preserves the recovery path without throwing away the rate-limit protection.
**Alternatives considered:** 1 hour for everything (rejected because it makes privacy-toggle recovery frustrating). No cache on player summaries (rejected because every sync click would always hit Steam even during repeated retries).
**Consequences:** Steam cache keys are deterministic and hashed by stable input only. Troubleshooting guidance needs to mention the short wait after fixing privacy settings.

### Transactional Steam sync via upsert on `(user_id, steam_app_id)`
**Date:** 2026-07-02
**Decision:** Steam library sync uses a composite unique index on `(user_id, steam_app_id)` and bulk `upsert()` inside a database transaction.
**Rationale:** The unique index gives the sync path a stable identity for a user's Steam-owned rows. `upsert()` updates Steam-owned fields in place while leaving manual rows with null `steam_app_id` alone, and the transaction guarantees we never land in a partially-synced state.
**Alternatives considered:** Per-row `updateOrCreate()` (rejected because it is noisier, slower, and easier to leave partially applied without careful transaction coverage). Title-based matching (rejected because manual and Steam rows must stay distinct even when titles collide).
**Consequences:** Steam is authoritative for Steam-owned rows only. Re-syncs refresh playtime/title/icon metadata without overwriting user-curated `status`, `genre`, or `platform`.

### Nightly Steam sync on the existing scheduler service
**Date:** 2026-07-02
**Decision:** Register `steam:sync-all` on Laravel's scheduler with a daily cadence.
**Rationale:** The scheduler container already exists from Phase 0, so nightly sync adds no new infrastructure. A daily refresh keeps the demo library reasonably fresh without needing WebSockets or progress infrastructure in Phase 2.
**Alternatives considered:** On-demand sync only (rejected because the scheduler service would remain unused and the Steam story would feel incomplete). Higher-frequency polling (rejected because it adds unnecessary API churn for a portfolio app).
**Consequences:** The batch command must continue past per-user failures and log warnings instead of aborting the run. The command becomes a reusable operational seam for later monitoring in Phase 6.

### Manual Steam fallback uses direct SteamID64 entry
**Date:** 2026-07-02
**Decision:** Replace the manual Steam fallback with direct SteamID64 entry instead of Steam vanity URL resolution.
**Rationale:** Vanity URLs are an optional Steam profile customization, not a universal identifier users can reliably copy from a normal profile link. Asking for SteamID64 is more explicit and avoids a second Steam API dependency just to translate user input before syncing.
**Alternatives considered:** Keep vanity resolution as the fallback (rejected because many users do not have a vanity URL configured, which makes the fallback confusing at the exact moment it is supposed to unblock them). Support both vanity and SteamID64 inputs (rejected for now because it adds UI and validation complexity without helping the demo path enough).
**Consequences:** The manual fallback route now validates a 17-digit SteamID64 locally and persists it directly. `SteamClient` no longer wraps `ResolveVanityURL`, so the Steam service surface is smaller and the tests focus on linking and sync behavior instead of profile-handle translation.

### One active play-session per user via row-locked user in a transaction
**Date:** 2026-07-02
**Decision:** Enforce "one open `play_sessions` row per user" via `DB::transaction` plus `User::whereKey($id)->lockForUpdate()` around a `whereNull('ended_at')->exists()` check before insert.
**Rationale:** Portable across MySQL 8 in dev/prod and SQLite in tests. Race-safe under concurrent "start session" calls from the same user.
**Alternatives considered:** Partial unique index on `user_id WHERE ended_at IS NULL` (rejected because MySQL 8 does not support it directly). Application-only check without row locking (rejected because it races under concurrent requests). Serializable transaction (rejected as heavier than needed).
**Consequences:** Every start-session call takes a brief row lock on the user's row for the duration of the transaction. Acceptable for a low-QPS user-scoped action.

### Session-end increments `games.playtime_minutes` only for manual-sourced games
**Date:** 2026-07-02
**Decision:** In `EndPlaySessionAction`, only bump `games.playtime_minutes` when `games.source = 'manual'`. Steam-sourced games have `last_played_at` updated but not the aggregate playtime.
**Rationale:** Steam is authoritative for Steam games. `SteamLibrarySynchronizer::sync` upserts `playtime_minutes` from Steam's `playtime_forever` on every scheduled sync, so any local increment would be silently overwritten. If we incremented locally and Steam later added the same minutes, the total could double-count during sync overlap.
**Alternatives considered:** Always increment locally (rejected because it double-counts or is clobbered against Steam re-sync). Never increment locally (rejected because manual games have no external source). Split into `local_playtime_minutes` and `steam_playtime_minutes` (rejected as schema churn for a small benefit).
**Consequences:** Session records are the source of truth for history. `games.playtime_minutes` is a cached aggregate with source-per-row semantics.

### `play_sessions` table avoids Laravel HTTP session collision
**Date:** 2026-07-02
**Decision:** Domain table is `play_sessions`; model is `App\Models\PlaySession`. URL paths remain `/api/sessions/*` per the build-plan spec.
**Rationale:** Laravel's database session driver already occupies the `sessions` table via the framework migration. Reusing the name would collide with HTTP session storage.
**Alternatives considered:** Switching the session driver to file/Redis to free the name (rejected because it changes the established Sanctum session setup). Keeping the URL and table both named `sessions` (rejected because the DB table would be ambiguous and unsafe).
**Consequences:** Slight internal-vs-URL naming divergence; documented here to prevent confusion.
