# Cortex Lite — Build Plan (v4)

**Goal:** A portfolio web application demonstrating the backend, cloud, and AI-integration skills required by the Razer Software Engineer JD, scoped around a coherent Cortex-shaped product story.

**Product concept:** A web companion app for PC gamers. Connects to Steam (via OpenID) to auto-import your library, lets you track play sessions, and provides AI-assisted graphics-settings recommendations based on your hardware and goal (performance vs quality). Free tier is fully featured but rate-limited; premium tier removes the cap.

**Stack:** Laravel · MySQL · Redis · React · Stripe · Docker · AWS · Gemini API · Steam Web API · PCGamingWiki API

**Time-boxed effort:** ~7 phases + one 1-day spike, ~6–8 weeks part-time. Per-phase budgets below with defined fallbacks if a phase overruns.

> **Changelog from v3:**
> - **Inverted the freemium gate.** Free tier gets recommendations AND reverse mode for all games, capped at 3 recommendations / 5 reverse-mode calls per rolling 30-day window. Premium removes both caps. The catalog restriction is gone.
> - **Reverse mode redesigned.** Rule-based `SettingsDiffEngine` compares pasted JSON to the canonical preset; LLM explains the diff in prose. Preserves the "LLM never decides settings" safety story across both modes, and is now on the free demo path.
> - **Steam connection UX rebuilt.** Steam OpenID login as primary; direct SteamID64 entry as fallback. Vanity URL resolution removed.
> - **GPU/CPU tiering rebuilt.** Hand-curated table of ~60 modern GPUs (2018+) and ~40 CPUs with **absolute** benchmark thresholds. Replaces percentile-tiered PassMark Kaggle ingestion.
> - **Curated settings dataset cut from 240 → ~30 anchor records.** Frame: heuristic engine is primary; anchors are calibration for the most common (game, tier, goal) tuples. Frees ~10 hours from Phase 4.
> - **Sanctum switched to cookie-based SPA auth.** CSRF + stateful domains, the production pattern.
> - **EC2 bumped to t3.small for the 48h window.** ~$0.02/hr fits the $200 credit pool comfortably; t2.micro OOM risk eliminated.
> - **CloudFront cache behavior carved out for `/api/stripe/webhook`.** No caching, all headers forwarded, raw body unmodified.
> - **Phase 4 now starts with a 1-day spike** to verify PCGamingWiki rate limit and Steam app ID → wiki page mapping before committing.
> - **Added `NATIVE_AGENT_CONTRACT.md`** — a portfolio artifact describing the hypothetical native-agent telemetry payload schema, written before Phase 6.
> - **Docs split.** `ARCHITECTURE.md` for system design, `DECISIONS.md` (ADR-style) for the tradeoff log, `TROUBLESHOOTING.md` for failure modes.
> - **Monthly recommendation count → rolling 30-day window** via a `created_at >= now() - interval 30 day` count on the recommendations table. No reset job; no thundering herd.
> - **Database transactions** wrapped around Steam bulk-insert and session start/end flows.
> - **Gemini model ID pinned** in `.env.example`.
> - **Steam API call params specified:** `include_appinfo=1&include_played_free_games=1` + cover-art URL pattern.
> - **Multi-stage Dockerfile** for React → nginx specified; `.dockerignore` mandated.
> - **Demo seed account** with a known-public Steam profile defined for the live deployment.
>
> **The honest interview narrative (unchanged):** *"This is the web/cloud companion layer for a Cortex-style product. The native agent that would collect real hardware telemetry and detect game launches is out of scope — the browser security model makes it impossible to build that in the web layer. What I built is the backend, data pipeline, AI integration, and cloud infrastructure that a native agent would feed into. The contract for that agent's payload is in `NATIVE_AGENT_CONTRACT.md`."*

---

## Guiding principles

- **Build for depth, not feature count.** Every feature must be one you can defend in an interview.
- **Code literacy first.** Read, understand, and explain every line. If a phase generates code you can't walk through, stop and study before moving on.
- **Honest justifications.** No "Redis for the resume." Every architectural choice has a real reason stated openly in `DECISIONS.md`.
- **Commit like you're in a sprint.** Feature branches, PR-style merges, sprint-tagged commits (`[Sprint 3] add Steam library sync`). This is your Agile evidence.
- **Four docs, not ten.** `README.md` (incl. sprint changelog), `ARCHITECTURE.md` (system design + AWS infra), `DECISIONS.md` (ADR-style tradeoff log), `TROUBLESHOOTING.md` (failure modes + fixes). One artifact: `NATIVE_AGENT_CONTRACT.md`.
- **Time-box ruthlessly.** Every phase has a budget. Overruns trigger pre-defined scope cuts, not extensions.
- **End every phase by updating the docs.** Each phase's PR includes the relevant entries in `DECISIONS.md` (and `ARCHITECTURE.md` / `TROUBLESHOOTING.md` if applicable). Retrofitted docs at the end are dead artifacts.

---

## Final feature set (locked)

### Core user-facing features

1. **Account & authentication.** Register, login, logout. Rate-limited login. Cookie-based Sanctum SPA auth (stateful, CSRF-protected).
2. **Steam-connected game library.** User logs in with Steam via OpenID; backend auto-imports their owned games, playtime, and metadata. Manual add/edit/delete also supported for non-Steam games.
3. **Library management UI.** Paginated game list with search, filter (by platform/genre/status), and sort. Per-game status: Currently Playing / Backlog / Completed / Dropped.
4. **Manual session tracking.** Start session → pick game from library → end session. Backend timestamps both events, persists a record, increments the game's total playtime.
5. **Steam playtime backfill.** A scheduled job re-syncs Steam-connected libraries periodically, picking up newly purchased games and aggregate playtime changes.
6. **AI-assisted settings optimizer (forward mode).** User selects hardware and a goal (performance / balanced / quality) and a game. Backend produces a recommended settings configuration with a natural-language explanation.
7. **Reverse mode.** User pastes a current settings JSON; rule-based engine diffs it against the canonical preset; LLM explains the diff. Available to free users (rate-limited).
8. **Premium subscription (Stripe).** Free tier: 3 recommendations + 5 reverse-mode calls per rolling 30 days, all games supported. Premium tier ($5/month): both caps removed.
9. **Account deletion.** `DELETE /api/account` cascades user data deletion (games, sessions, recommendations) and cancels any active Stripe subscription. GDPR-shaped signal.

### Backend / infrastructure features (not user-visible but built)

10. **Hardware tier database.** Hand-curated table of ~60 modern GPUs (2018+) and ~40 modern CPUs, classified into 4 performance tiers via **absolute benchmark thresholds**.
11. **PCGamingWiki integration.** Scheduled ingestion of game graphics-settings metadata via MediaWiki Cargo queries, with strict rate-limit compliance and Redis caching.
12. **Anchor settings dataset.** ~30 hand-curated records covering the most common (game, gpu_tier, goal) tuples for the 10 most-played AAA titles, used as calibration ground truth for the heuristic engine.
13. **Heuristic recommender.** Default path for all (game, tier, goal) tuples not covered by an anchor; uses GPU tier × goal × PCGamingWiki metadata to construct settings.
14. **Settings diff engine.** Reverse-mode engine that compares pasted JSON to the canonical preset and produces a structured diff. LLM explains the diff; LLM does not judge the settings.
15. **LLM integration layer.** Recommendations are generated by the rule-based engine; the LLM only writes prose. No hallucinated settings in either mode.
16. **Six-service local dev stack.** Docker Compose: `app`, `nginx`, `mysql`, `redis`, `scheduler` (Laravel scheduled jobs), `queue` (background sync + async LLM calls).
17. **CI pipeline.** GitHub Actions running `php artisan test` on every push.
18. **AWS deployment.** EC2 t3.small (Dockerized), RDS MySQL, ECR, CloudFront (HTTPS, with carved-out cache behavior for the Stripe webhook), Parameter Store (secrets), CloudWatch (logs).

### Stretch goals (only if Phases 0–6 finish on time)

19. **Real-time Steam sync progress via WebSockets.** Steam library sync for a 100+ game library can take 30+ seconds. WebSocket-pushed progress events give a live progress bar. Documented as a planned future enhancement if skipped.
20. **Deal alerts.** Integration with IsThereAnyDeal or CheapShark APIs to show price drops on games in the user's library.

---

## Phase 0 — Setup, Dockerization, and CI

**Time-box: 3–5 days. Fallback if overrun: drop the queue service stub, add it in Phase 4 when the first background job lands.**

**Why:** Get the entire local dev environment working before writing any feature code. Scaffold every service you'll eventually need now — including the scheduler and queue services — so that adding the features that depend on them is a code change, not an infrastructure change.

- [ ] Initialise Git repo, push to GitHub (public). Add a placeholder `README.md`.
- [ ] Scaffold Laravel project (`composer create-project laravel/laravel cortex-lite`).
- [ ] Scaffold React frontend in a `client/` subdirectory (`npm create vite@latest client -- --template react`).
- [ ] **Write `docker-compose.yml` with all six services from day one:**
  - `app` — PHP-FPM + Laravel
  - `nginx` — web server (no WebSocket headers needed in v4 unless you pursue stretch goal 19)
  - `mysql` — version 8.x
  - `redis` — version 7.x
  - `scheduler` — same image as `app`, runs `php artisan schedule:work`
  - `queue` — same image as `app`, runs `php artisan queue:work`
- [ ] **Write a multi-stage `Dockerfile` for production:** stage 1 builds the React app (`npm run build` in `client/`), stage 2 is the nginx container that serves the build output as static files. Documented in `ARCHITECTURE.md`. In dev, the Vite dev server runs on the host for HMR speed.
- [ ] **Add `.dockerignore`** excluding `node_modules`, `vendor`, `.git`, `storage/logs`, `.env`. Prevents 2GB images on ECR push.
- [ ] Create `.env.example` with every variable across all phases. Stripe keys, Steam API key, **`GEMINI_MODEL=gemini-3.5-flash`** (pin the exact model ID), `GEMINI_API_KEY`, PCGamingWiki user-agent string, DB creds — all listed (values blank).
- [ ] Document the React/Docker split: comment in `docker-compose.yml` and a note in `README.md`. *"Vite dev server runs on the host (`npm run dev`) for hot-reload performance. In production, the build output is served as static files by the `nginx` container via the multi-stage Dockerfile."*
- [ ] Configure Laravel `.env` to point at the Docker MySQL and Redis services.
- [ ] Run Laravel migrations against the Dockerized MySQL.
- [ ] **Add GitHub Actions CI** — workflow runs `php artisan test` on every push. Green badge in README.
- [ ] **Add a `Makefile`** with `make up`, `make down`, `make migrate`, `make test`, `make logs`, `make shell` (drop into the `app` container).
- [ ] Scaffold the four docs as empty files: `ARCHITECTURE.md`, `DECISIONS.md`, `TROUBLESHOOTING.md`. Leave `NATIVE_AGENT_CONTRACT.md` for Phase 6.
- [ ] Commit: `[Sprint 0] scaffold project, dockerize dev environment, add CI`.

**Deliverable:** `docker-compose up` brings all six services healthy. CI runs green on every push.

---

## Phase 1 — Auth & user management

**Time-box: 3–4 days. Fallback if overrun: skip the React register page polish; functional ugly UI is fine.**

- [ ] Install Laravel Sanctum and configure **SPA mode**: set `SANCTUM_STATEFUL_DOMAINS` to your dev domain, enable the CSRF cookie route, configure CORS to allow credentials.
- [ ] Document the auth pattern in `DECISIONS.md`: *"Cookie-based SPA auth (CSRF + stateful domains) chosen over API tokens because the React app is first-party, served from the same origin in production. This is Sanctum's intended pattern for first-party SPAs. API tokens would be appropriate for third-party clients only."*
- [ ] Build register/login/logout API endpoints (`POST /api/register`, `POST /api/login`, `POST /api/logout`).
- [ ] **Apply Laravel's throttle middleware to `/api/login`** — `throttle:5,1` (5 attempts/min). Verify the 429 response includes a `Retry-After` header.
- [ ] Build React login + register pages. Configure Axios with `withCredentials: true` and the CSRF cookie flow (`GET /sanctum/csrf-cookie` before login).
- [ ] Build protected `/api/me` endpoint and a React `Dashboard` page displaying the logged-in user.
- [ ] Build `DELETE /api/account` endpoint — cascades user data deletion (games, sessions, recommendations, attachments) within a single DB transaction and cancels any active Stripe subscription. UI button on the account settings page with double-confirm.
- [ ] Write PHPUnit feature tests: register success, login success, login failure with bad credentials, throttle triggers after 5 failed attempts (assert 429 + `Retry-After`), CSRF rejection on missing token, account deletion cascade.
- [ ] **Security-flavored tests:** mass-assignment protection on register/update (asserts `is_admin`-style fields can't be set via request body), SQL injection attempt on the search field (asserts parameterized queries).
- [ ] End-of-phase: update `DECISIONS.md` with the Sanctum SPA decision. Update `TROUBLESHOOTING.md` with the most common Sanctum SPA gotcha (CSRF cookie not sent → check `withCredentials` and CORS `Access-Control-Allow-Credentials`).
- [ ] CI must pass on the PR. Merge to `main` with a clear merge commit.

**Resume bullet (earn before claiming):** *"Implemented cookie-based SPA authentication with Laravel Sanctum (CSRF + stateful domains), rate-limited login endpoints with `Retry-After`, account-deletion cascade with Stripe subscription teardown, and PHPUnit feature tests covering auth flows, throttling, CSRF rejection, mass-assignment protection, and SQL injection attempts."*

---

## Phase 2 — Game library + Steam Web API integration

**Time-box: 5–7 days. Fallback if overrun: ship Steam auto-import without the manual CRUD UI; add manual UI later. The Steam integration is the differentiator here, not the form fields.**

**Why:** This is your "real backend" feature and your first external API integration. Demonstrates database schema design, REST patterns, validation, authorization, transactional safety, OpenID, and external API consumption with rate limiting and caching.

### Database & manual CRUD

- [ ] Design the `games` schema: `id`, `user_id`, `title`, `platform`, `genre`, `status` (enum: playing/backlog/completed/dropped), `hours_played`, `last_played_at`, `steam_app_id` (nullable), `source` (enum: manual/steam), `cover_url`, `metadata_status` (enum: pending/ok/missing), timestamps. FK on `user_id` cascade-deletes.
- [ ] Eloquent model with `User hasMany Games` relationship.
- [ ] 4 RESTful endpoints under `/api/games`: index (paginated), store, update, destroy. All scoped to the authenticated user. (Dropping `show` — `index` returns enough for the list view, individual game detail isn't needed yet.)
- [ ] Form Request validation classes.
- [ ] PHPUnit **authorization-boundary tests**: User A cannot read, update, or delete User B's games. **IDOR test**: User A passing User B's `game_id` to any endpoint returns 403/404, never 200.
- [ ] React UI: paginated list, search/filter/sort controls, status dropdown, add/edit/delete forms.

### Steam OpenID login + Web API integration

- [ ] Register for a free Steam Web API key at `steamcommunity.com/dev`. Store in `.env` and `.env.example`.
- [ ] Add `steam_id` (nullable) and `steam_id_resolved_at` columns to `users` table.
- [ ] **Implement Steam OpenID login as the primary connection flow.** `GET /api/steam/login` redirects to Steam's OpenID endpoint; `GET /api/steam/callback` validates the OpenID response, extracts the SteamID64, persists it on the user. Use `xPaw/SteamOpenID` or hand-roll (~50 lines). Document the choice in `DECISIONS.md`.
- [ ] **Implement direct SteamID64 entry as a manual fallback** (`POST /api/steam/connect-id`): user pastes their SteamID64, backend validates the identifier locally and persists it directly. This is the fallback for users who can't complete the OpenID redirect during the interview demo (it happens).
- [ ] Build a `SteamClient` service class (`app/Services/SteamClient.php`) wrapping the Steam API. Methods: `getOwnedGames($steamId)`, `getPlayerSummary($steamId)`.
- [ ] **Specify the `getOwnedGames` call:** `?include_appinfo=1&include_played_free_games=1`. Without `include_appinfo=1`, titles and `img_icon_url` are absent and the `cover_url` column stays empty. Cover-art URL pattern: `https://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{img_icon_url}.jpg` — no extra API call needed.
- [ ] **Cache Steam API responses in Redis** with a 1-hour TTL. Justification in `DECISIONS.md`: Steam's API has rate limits (100k calls/day per key) and the data changes slowly; caching reduces latency and protects against quota exhaustion.
- [ ] Build `POST /api/steam/sync` endpoint — calls `getOwnedGames`, wraps the bulk-insert + playtime-update in a **single DB transaction** so partial failures don't leave the library in an inconsistent state. Marks new games with `metadata_status = 'pending'` for the Phase 4 metadata enrichment.
- [ ] **Handle the private-profile edge case gracefully.** Pre-flight check on `communityvisibilitystate == 3` from `getPlayerSummary`; if private, return a 422 with a structured error pointing to the **two specific Steam privacy toggles** (Profile + Game Details — both must be Public). UI surfaces both with a screenshot guide. Document in `TROUBLESHOOTING.md`.
- [ ] **Schedule a daily background sync.** Add a Laravel scheduled task in `app/Console/Kernel.php` that re-syncs every connected user nightly. The scheduler service from Phase 0 picks this up.
- [ ] PHPUnit tests for the Steam integration with HTTP fakes (Laravel's `Http::fake()`). Test cases: OpenID happy path, direct SteamID64 fallback, private-profile rejection with structured error, transactional rollback on mid-sync failure.
- [ ] End-of-phase: update `DECISIONS.md` (OpenID choice, Redis caching rationale, transactional sync rationale), `TROUBLESHOOTING.md` (private profile + the two Steam privacy toggles).

**Resume bullet (earn before claiming):** *"Designed and implemented a paginated game library REST API with Steam OpenID authentication and Web API integration for automatic library import and scheduled playtime sync, including Redis caching to respect external rate limits, transactional bulk-insert for sync atomicity, graceful handling of private-profile edge cases with structured error responses, and authorization-boundary + IDOR feature tests."*

---

## Phase 3 — Session tracking & history

**Time-box: 3–5 days. Fallback if overrun: ship sessions without the history visualization; a list view is enough.**

**Why:** Connects the library to actual usage data. Gives the project a real product workflow (track what you play) instead of a static CRUD demo.

- [ ] `sessions` table schema: `id`, `user_id`, `game_id`, `started_at`, `ended_at` (nullable while in-progress), `duration_seconds`, timestamps. FK on both `user_id` and `game_id`.
- [ ] Endpoints: `POST /api/sessions/start` (body: `game_id`, returns the in-progress session), `POST /api/sessions/{id}/end` (computes duration, increments `games.hours_played`).
- [ ] **Wrap session-end in a DB transaction**: insert/update the session row + increment the parent game's `hours_played` atomically. Without this, a crash between the two writes silently desyncs the totals.
- [ ] **Constraint: a user can have at most one in-progress session at a time.** Enforce at the application layer with a clear error response. Verify via a unique partial index where supported, or an explicit `SELECT ... FOR UPDATE` check inside the transaction.
- [ ] React UI: a "Start Session" button next to each game in the library, a persistent in-progress session indicator (shows elapsed time, "End session" button) in the header.
- [ ] History page: list of past sessions grouped by game, with date, duration, and total per-game playtime.
- [ ] PHPUnit tests: starting a session, ending a session, "only one in-progress session" constraint, authorization boundary, **IDOR via crafted `game_id` belonging to another user** (must reject).
- [ ] End-of-phase: update `DECISIONS.md` with the application-layer constraint rationale (vs. DB-level partial index).

**Resume bullet (earn before claiming):** *"Built a session tracking system with start/end lifecycle endpoints, transactional per-game playtime aggregation, application-level constraints (one active session per user with row-level locking), authorization-boundary tests, and a session history view."*

---

## Phase 4 — Hardware database & game settings data pipeline

**Time-box: 6 days (1-day spike + 5-day build). Fallback if overrun: skip PCGamingWiki ingestion entirely, fall through to the heuristic engine with anchor-set metadata. The anchor dataset is the load-bearing piece; PCGamingWiki is the nice-to-have layer on top.**

**Why:** This is the project's actual hard problem. The recommender logic is easy; the data is the engineering.

### Phase 4.0 — Spike (1 day, before committing to the rest of Phase 4)

- [x] **Verify PCGamingWiki's actual rate-limit policy.** Read [https://www.pcgamingwiki.com/wiki/PCGamingWiki:API](https://www.pcgamingwiki.com/wiki/PCGamingWiki:API) and record the verified policy in `DECISIONS.md`. If no published per-minute number, document the conservative throttle chosen (e.g., 30/min, half a connection-pool's worth).
- [x] **Test the Steam app ID → wiki page mapping for 10 random Steam app IDs** spanning AAA, indie, and old titles. PCGamingWiki pages are keyed by game name, not Steam app ID directly. The lookup uses a Cargo query against the `Infobox_game` table where `Steam_AppID` matches. Record the hit rate (expect ~70–90%). If under 70%, switch to plan B for Phase 4.
- [x] **Decision gate.** If both checks pass (verified rate limit, ≥70% lookup hit rate), proceed with the PCGamingWiki integration as planned. If either fails, fall through to plan B: anchor dataset + heuristic engine fed by hand-curated graphics-options metadata for the 10 anchor games, with an honest README note.
- [x] Update `DECISIONS.md` with the spike findings and the chosen path.

### Hardware tier database

- [x] **Hand-curate `gpus.json`** — ~60 GPUs released 2018 onward (Pascal-and-later NVIDIA, Polaris-and-later AMD, plus modern integrated like Iris Xe and Radeon 700M). Each row: `name`, `manufacturer`, `g3d_mark`, `released_year`; `tier` is derived at seed time. Tier assignment uses **absolute thresholds**, not percentiles:
  - **Low:** G3D Mark < 8,000 (GTX 1050 Ti, 1060 3GB, integrated graphics)
  - **Mid:** 8,000–13,999 (GTX 1660, RTX 2060, RX 5600 XT, RTX 3050)
  - **High:** 14,000–21,999 (RTX 3060/3070, RTX 4060, RX 6700 XT, RX 7600)
  - **Enthusiast:** ≥ 22,000 (RTX 3080/3090, RTX 4070+, RX 6900/7900 XT)
- [x] **Hand-curate `cpus.json`** — ~40 CPUs released 2018 onward (Ryzen 2000+, Intel 8th gen+) with the same 4-tier shape using single-thread benchmark thresholds.
- [x] Document tier-threshold rationale in `DECISIONS.md`: *"Absolute thresholds (not percentiles) chosen because a percentile cut across all PassMark history would put a GTX 1060 in 'high tier' (half the dataset is 15-year-old hardware). Modern users see modern tiers."*
- [x] Schemas: `gpus` and `cpus` tables matching the JSON shape.
- [x] Laravel seeders ingest the JSON files. Run them as part of the deployment process.
- [x] Build `GET /api/hardware/gpus?search=...` and `GET /api/hardware/cpus?search=...` — typeahead endpoints for the hardware-selection UI.
- [x] React: hardware input form with autocomplete dropdowns. Order results by `g3d_mark` desc within filter matches.

### Browser-side hardware auto-detect (best-effort)

- [x] Use `navigator.hardwareConcurrency`, `navigator.deviceMemory`, and the WebGPU API (where supported) to pre-fill what the browser knows.
- [x] **Be honest about the limits.** The browser cannot identify the exact GPU model. Show a UI message: *"Auto-detected: 16 GB RAM, 12 cores. Please select your GPU manually — browsers don't expose the GPU model."* This is interview gold — it shows you understand the security model.

### PCGamingWiki integration (only if Phase 4.0 spike passed)

- [x] Build a `PcGamingWikiClient` service class wrapping the MediaWiki **Cargo API** (`?action=cargoquery&format=json&tables=Infobox_game,API,Video&fields=...&where=Infobox_game.Steam_AppID=...`). Cargo returns the structured graphics-options table directly — far cleaner than scraping article markup.
- [x] **Respect the verified rate limit strictly** with a token-bucket throttle in `app/Services/RateLimiter/PcGamingWikiLimiter.php`. Custom user-agent string including contact email per their etiquette.
- [x] Cache responses aggressively in Redis (7-day TTL).
- [x] Schema: `game_metadata` table — `id`, `game_id` (FK), `direct3d_versions`, `vulkan_supported`, `hdr_supported`, `ultrawide_supported`, `dlss_supported`, `fsr_supported`, `ray_tracing_supported`, `raw_response` (JSON for future fields), timestamps.
- [x] Scheduled job: for each game with `metadata_status = 'pending'`, look up via PCGamingWiki, persist the result, flip `metadata_status` to `ok` or `missing`. The React library UI shows a small status icon per game so the user understands metadata enrichment is in progress.
- [x] PHPUnit tests with HTTP fakes: cache hit path, rate-limit-respect path, no-match → `missing` status.

### Anchor settings dataset

- [x] Pick the **10 anchor games** spanning genres and engines: Cyberpunk 2077, CS2, Elden Ring, Valorant, Baldur's Gate 3, Fortnite, Minecraft Java, GTA V, Red Dead Redemption 2, Helldivers 2.
- [x] Hand-curate the anchor settings JSON: 10 games × 3 goals × **1 representative tier per goal** (low/perf, mid/balanced, high/quality) = ~30 anchor records. Each record cites its source (Tom's Hardware, Digital Foundry, PCGamingWiki) in the `notes` field — the discipline that turns "I made up numbers" into "I curated against published guides."
- [x] Commit `setting_presets.json` to the repo. Document the methodology in `DECISIONS.md` (specifically: anchors are calibration for the heuristic engine, not a lookup table).
- [x] Laravel seeder ingests `setting_presets.json` into a `setting_presets` table.

### Heuristic recommender (primary path)

- [x] Build `HeuristicRecommender` — for any (game, gpu_tier, cpu_tier, ram_bucket, goal) tuple, construct a settings JSON using GPU tier × goal as the primary axis, masked by PCGamingWiki metadata (e.g., don't recommend DLSS for a game that doesn't support it; don't recommend ray tracing if `ray_tracing_supported = false`).
- [x] **Anchor calibration check (compile-time):** for every (anchor_game, anchor_tier, anchor_goal) tuple, the heuristic recommender's output is checked against the anchor record for semantic capability contradictions. Drift triggers a test failure. This is the interview answer to "how do you know the heuristic is right?" — *"I have anchor regression tests against curated ground truth for the most common cases."*

**Resume bullet (earn before claiming):** *"Built a multi-source data pipeline for hardware classification and game-settings recommendations: hand-curated a tier database of ~60 modern GPUs and ~40 CPUs with absolute benchmark thresholds, integrated rate-limit-compliant PCGamingWiki Cargo queries with Redis caching for game metadata, hand-curated a 30-record anchor dataset for calibration, and implemented a heuristic recommender masked by per-game capabilities with anchor regression tests."*

---

## Phase 5 — AI-assisted optimizer + Stripe premium tier

**Time-box: 5–7 days. Fallback if overrun: ship the optimizer without Stripe gating (one full experience for all users); add billing after deployment.**

**Why:** This is the differentiating feature and the LLM integration that JD-level "current technologies" claims rest on.

### Forward-mode recommendation engine

- [x] `RecommendationEngine` service. Inputs: `game_id`, `gpu_id`, `cpu_id`, `ram_gb`, `goal`. Algorithm:
  1. Look up GPU tier, CPU tier, bucket RAM (`< 16GB`, `16-31GB`, `≥ 32GB`).
  2. If `setting_presets` has an anchor for `(game_id, gpu_tier, goal)`, use it directly.
  3. Else invoke `HeuristicRecommender` with PCGamingWiki metadata (or anchor-game metadata if PCGamingWiki was skipped).
  4. Apply CPU/RAM bottleneck adjustments (e.g., if RAM < 16GB, force lower texture pool).
  5. Return the structured settings JSON.
- [x] Endpoint: `POST /api/recommend` taking the inputs, returning the settings JSON plus an `explanation` field.
- [x] PHPUnit tests for the engine: known inputs → expected outputs. The recommendation engine is deterministic.

### Reverse mode (settings diff)

- [x] `SettingsDiffEngine` service. Inputs: pasted settings JSON, plus the same hardware/goal inputs as forward mode. Algorithm:
  1. Run `RecommendationEngine` to get the canonical preset for `(game, hardware, goal)`.
  2. Diff the pasted JSON against the canonical preset. Output: `{texture_quality: "high → medium", ray_tracing: "on → off", ...}`.
  3. Return the structured diff to the caller.
- [x] Endpoint: `POST /api/reverse` taking pasted JSON + hardware/goal, returning the diff + a deterministic static explanation fallback. LLM prose lands in the separate explanation section below.
- [x] **Architectural note documented in `DECISIONS.md`**: *"Reverse mode is rule-based, not LLM-driven. The LLM explains the structured diff in prose but never judges settings directly. This preserves the 'LLM cannot affect the recommendation' safety story across both modes."*
- [x] PHPUnit tests for the diff engine: known pasted JSON + canonical preset → expected diff.

### LLM-generated explanation

- [x] Choose provider: **Gemini API** (pinned model ID via `GEMINI_MODEL=gemini-3.5-flash` in `.env.example`). Document the choice in `DECISIONS.md`: fast, cost-effective, good at structured-explanation tasks.
- [x] Build an `ExplanationGenerator` service used by both modes. Forward mode: input is the recommendation + hardware/goal; output is 3–4 sentences explaining why those settings make sense. Reverse mode: input is the diff + hardware/goal; output is 3–4 sentences explaining each change in the diff.
- [x] **Prompt design — the LLM never decides settings; it only explains.** State this constraint explicitly in `DECISIONS.md`.
- [x] **Cache LLM responses in Redis.** Forward-mode cache key: `(game_id, gpu_tier, cpu_tier, ram_bucket, goal)`. Reverse-mode cache key: `hash(diff_structure, hardware_tier, goal)` — most pasted-JSON inputs map to the same diff shape, so cache hit rate is still high. **Unit-test the cache key construction** to prevent timestamp-in-key bugs (a single accidental timestamp can multiply LLM cost 1000×).
- [x] **Handle LLM API failures gracefully.** Timeouts, content filtering, rate limits — return the structured recommendation/diff with a fallback static explanation rather than failing the whole request. Document in `TROUBLESHOOTING.md`.
- [x] **Decide sync vs async for the LLM call.** Sync (simpler, ~2–3s response on cache miss, acceptable for cold path). Async via queue with UI polling (more code, snappier UX). Pick sync for v1 with a UI loading state; document the tradeoff in `DECISIONS.md`.
- [x] React UI: hardware-selection form, game-search field, goal selector (performance/balanced/quality), submit button, results card showing the settings table and the LLM explanation. Reverse mode: structured current-settings form (chosen over a paste box — see DECISIONS.md) + same hardware/goal selectors + results showing the diff table + explanation. **Delivered via `docs/superpowers/plans/2026-07-06-phase-5-optimizer-frontend.md` as the dedicated optimizer-UI slice.**

### Stripe premium gating (rolling 30-day window)

- [x] Add `is_premium`; use Cashier's existing `stripe_id` customer column instead of adding duplicate `stripe_customer_id`. **Do not add a counter column** - use the rolling-window count below.
- [x] Add one `usage_events` table with a `type` column - every successful optimizer call logs a row. Free-tier quota check: `count where user_id = ? and type = 'recommend' and created_at >= now() - interval 30 day`. Rolling window, no reset job.
- [x] Install Laravel Cashier.
- [x] `POST /api/checkout` creates a Stripe Checkout Session for a $5/month "Cortex Premium" subscription.
- [x] Stripe webhook handler at `/api/stripe/webhook` flipping `is_premium` on subscription create/cancel events. **Verify webhook signatures** - most-asked Stripe interview topic.
- [x] **CSRF-exempt the webhook route** (`VerifyCsrfToken` middleware excluded for `stripe/webhook`).
- [x] **Free tier (all games supported):**
  - 3 recommendations per rolling 30 days
  - 5 reverse-mode calls per rolling 30 days
  - No catalog restriction
- [x] **Premium tier:**
  - Unlimited recommendations
  - Unlimited reverse-mode calls
- [x] Two explicit Stripe webhook test cases: wrong-signature rejection, subscription-cancellation flips `is_premium` to false.
- [x] React UI: usage counters on the dashboard ("2 / 3 recommendations used in the last 30 days"), upgrade button, soft-locked state for free users at quota.
- [x] End-of-phase: update `DECISIONS.md` (rolling-window choice, LLM-safety pattern, sync explanation choice). Update `TROUBLESHOOTING.md` (Stripe CLI test-mode walkthrough: `stripe listen --forward-to localhost/api/stripe/webhook`, `stripe trigger checkout.session.completed`).

**Resume bullet (earn before claiming):** *"Built a hybrid recommendation engine combining a deterministic rule-based core with LLM-generated natural-language explanations (Gemini API, pinned model ID), reverse-mode settings-diff feedback also rule-based to preserve the no-hallucination guarantee, Redis caching with unit-tested cache-key construction for cost control, graceful LLM-failure fallback, and a Stripe-gated freemium model enforcing rolling 30-day quotas via event-table counts (no thundering-herd reset job)."*

---

## Phase 6 — AWS deployment + NATIVE_AGENT_CONTRACT.md

**Time-box: 4–5 days. Hard live-deployment window: 48 hours, then tear down. Fallback if overrun: deploy backend only (no CloudFront), screenshot what you have, tear down.**

### Write `NATIVE_AGENT_CONTRACT.md` (Day 1, before any AWS work)

- [ ] Write a 1-page artifact describing the hypothetical native-agent telemetry payload schema. Sections: scope (what the agent observes vs what the web layer owns), authentication (mTLS or signed JWTs), payload schema (hardware snapshot, running-game detection, session events), update cadence, privacy considerations (opt-in, no PII to LLM, local-first caching), security boundaries (agent never executes web-layer code, web layer never trusts unsigned payloads).
- [ ] **Why this artifact matters:** it directly answers the interview question *"why didn't you build the agent?"* with an engineering document instead of a verbal hand-wave. Demonstrates system-boundary thinking — the half you didn't build is as legible as the half you did.

### ⚠️ AWS cost reality (post-July 2025 accounts)

If your AWS account was created on or after July 15, 2025: no 12-month per-service free tier exists. You get **$200 in credits expiring after 6 months**, shared across all services. RDS + EC2 + data transfer + a NAT Gateway (~$33/month if accidentally created) all spend the same credits.

**Mandatory cost protections:**

- [ ] Check your AWS account creation date.
- [ ] Set an **AWS Budgets alert at $20 spend, hard threshold, email notification**, before creating any resource.
- [ ] **Never create a NAT Gateway.** Put EC2 in a public subnet with a tight security group.
- [ ] **48-hour live-deployment rule.** Spin up, screenshot, demo, tear down within 48 hours. Calendar reminder.

### Architecture

- [ ] **EC2 t3.small** in a public subnet. Runs the Docker Compose stack (app + nginx + scheduler + queue). At ~$0.02/hr, 48 hours ≈ $1, well within the $200 credit pool. Document the choice in `DECISIONS.md`: *"t3.small (2 GB RAM) chosen over t2.micro because PHP-FPM + nginx + Redis + scheduler + queue on a 1 GB box will OOM during the demo, particularly when a Steam sync job and an LLM call land together. The $1 cost over 48 hours is negligible against the $200 credit pool."*
- [ ] **RDS MySQL db.t4g.micro** in the same VPC. Security group permits port 3306 only from the EC2 security group.
- [ ] **Redis in-container on EC2**, NOT ElastiCache. State the tradeoff openly in `DECISIONS.md`: *"For this workload, in-container Redis on EC2 is the right call. ElastiCache would be correct at multi-instance scale where Redis state must outlive any single EC2 instance — that's not the case here."*
- [ ] **ECR** for Docker image storage.
- [ ] **Parameter Store (Systems Manager)** for all secrets — Stripe keys, Steam API key, Gemini API key, DB password. Injected at container start. **No `.env` files on disk in production.**
- [ ] **CloudFront in front of EC2** using the free default `*.cloudfront.net` domain. **HTTPS is non-optional.**
- [ ] **CloudFront cache behavior carve-out for `/api/stripe/webhook`:**
  - Behavior pattern: `/api/stripe/webhook`
  - Caching: disabled (TTL 0, no cache key)
  - Forward: all headers, all query strings, request body unmodified
  - Allowed methods: POST only
  - Document in `TROUBLESHOOTING.md`: *"CloudFront default behavior strips Stripe-Signature and can modify the request body, breaking webhook signature verification. The carve-out is non-negotiable."*
- [ ] **CloudWatch Logs** for structured application logs.

### Deployment steps

- [ ] Provision EC2 with security group: inbound 80/443 from anywhere, SSH from your IP only.
- [ ] Provision RDS in the same VPC.
- [ ] Push Docker images to ECR (verify `.dockerignore` is in effect — `docker image inspect` should show <500 MB images).
- [ ] SSH into EC2, install Docker + Docker Compose, pull images.
- [ ] Entry-point script fetches secrets from Parameter Store on container start.
- [ ] Run `docker-compose up -d`. Verify `docker stats` shows headroom before declaring the deploy live.
- [ ] Run the database migrations and the hardware/anchor seeders on the live RDS.
- [ ] **Seed a demo account** (`demo@cortex-lite.example`, password documented in the README's "evaluator quick-start" section) with a populated game library tied to a known-public Steam profile so an evaluator can walk the full flow without setting up their own Steam privacy settings. A nightly scheduled job resets the demo account state.
- [ ] Provision CloudFront distribution pointing at the EC2 public DNS. HTTPS-only viewer protocol. Apply the webhook cache-behavior carve-out before testing.
- [ ] **Test Stripe webhook against the live URL with `stripe trigger checkout.session.completed`** before declaring the deploy demoable. Catch CloudFront mangling here, not during the interview.
- [ ] Test the full flow over HTTPS: register → connect Steam (OpenID) → import library → start/end a session → request a recommendation → use reverse mode → upgrade via Stripe → confirm `is_premium` flipped.
- [ ] **Screenshot everything:** EC2 console, RDS dashboard, ECR repos, CloudWatch logs (structured JSON), security group rules, CloudFront distribution and cache behaviors, Parameter Store entries (values masked), the running app at its CloudFront URL, an example recommendation result, a reverse-mode result.
- [ ] Record a 2–3 minute demo video walking through the live app.
- [ ] Document everything in `ARCHITECTURE.md` (Infrastructure section): architecture diagram, every AWS service used and why, security model, cost breakdown, teardown procedure.
- [ ] **Tear down all paid resources** post-screenshot. Keep all IaC scripts / config in the repo. Cancel any active Stripe test subscriptions.
- [ ] Verify the AWS bill is back to $0/day post-teardown. Document the teardown verification command (`aws ce get-cost-and-usage --time-period Start=...,End=... --granularity DAILY --metrics UnblendedCost`) in `TROUBLESHOOTING.md`.

**Resume bullet (earn before claiming):** *"Deployed a multi-service Dockerized application to AWS using EC2 t3.small, RDS MySQL, ECR, CloudFront (HTTPS, with a carved-out cache behavior for the Stripe webhook to preserve signature verification), Parameter Store (secrets), and CloudWatch (structured logging), with security-group network isolation, documented infrastructure architecture, a 48-hour live-deployment window with budget alerts, and a documented teardown procedure with cost verification."*

---

## Phase 7 (Stretch) — Real-time Steam sync progress via WebSockets

**Only attempt if Phases 0–6 finish on time. If you skip this, document the gap openly: "WebSocket support is a planned future enhancement; the current Steam sync uses HTTP polling for progress (`GET /api/steam/sync/status` every 2 seconds, with the `metadata_status` column on `games` driving the per-row indicator)."**

**Time-box: 3–4 days.**

- [ ] Install Laravel Reverb. Add the `reverb` service to `docker-compose.yml`.
- [ ] Configure nginx WebSocket proxy headers (`proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "Upgrade";`).
- [ ] Configure Sanctum stateful domains and the broadcasting auth endpoint.
- [ ] `routes/channels.php` authorization callback for a `sync.{userId}` private channel.
- [ ] Modify the Steam sync job to dispatch broadcast events on each game processed (`SteamSyncProgressEvent`).
- [ ] React: subscribe to the channel via Laravel Echo, render a live progress bar during sync.
- [ ] Implement client-side reconnect with exponential backoff.
- [ ] Test two failure modes: kill the Reverb container mid-sync (reconnect should recover), token expiry mid-sync (re-auth should reconnect).
- [ ] Document everything in `TROUBLESHOOTING.md`.

**Resume bullet (earn before claiming):** *"Added real-time progress reporting for long-running Steam library sync jobs using Laravel Reverb WebSockets, private-channel authorization via Sanctum, exponential-backoff reconnection, and documented failure-mode handling."*

---

## Final deliverables checklist

- [ ] Working `docker-compose.yml` (6 services; 7 if you ship stretch goal 19)
- [ ] Working CI pipeline (green badge in README)
- [ ] Multi-stage `Dockerfile` (React build → nginx serve)
- [ ] `.dockerignore` mandated
- [ ] `README.md` — what it is, how to run locally (`make up`), sprint changelog, screenshots of live deployment, demo video link, evaluator quick-start with seeded demo account
- [ ] `ARCHITECTURE.md` — system diagram, AWS infrastructure section, cost breakdown, security model
- [ ] `DECISIONS.md` (ADR-style) — Sanctum SPA auth, Redis justifications, CloudFront-vs-Caddy, ElastiCache-vs-local Redis, GPU tier thresholds, percentile-vs-absolute rationale, LLM-vs-rule-based separation, reverse-mode-is-rule-based, anchor-dataset methodology, rolling-window vs reset-job, sync-vs-async LLM call, t3.small-vs-t2.micro
- [ ] `TROUBLESHOOTING.md` — Sanctum SPA cookie gotchas, Steam private-profile + the two privacy toggles, PCGamingWiki rate-limit handling, LLM API failures, Stripe CLI test-mode walkthrough, CloudFront webhook cache-behavior gotcha, container OOM diagnosis, AWS teardown verification
- [ ] `NATIVE_AGENT_CONTRACT.md` — hypothetical agent payload schema, auth, privacy, security boundaries
- [ ] PHPUnit test suite — auth flows, throttle (+ `Retry-After`), CSRF rejection, mass-assignment protection, SQL injection attempts, authorization boundaries + IDOR, Steam integration with HTTP fakes, transactional sync rollback, sessions constraints with locking, recommendation engine determinism, anchor regression tests, settings-diff engine, LLM cache-key construction, Stripe webhook signature + cancellation
- [ ] Curated `gpus.json`, `cpus.json`, `setting_presets.json` (anchor dataset) committed to the repo
- [ ] Demo video (2–3 min, unlisted YouTube)
- [ ] Clean Git history with feature branches and sprint-tagged commits
- [ ] All AWS resources torn down post-demo; IaC/config preserved in repo

---

## JD coverage matrix

| JD requirement | Phase that covers it |
|---|---|
| PHP/Laravel | All phases |
| Python or C# (2nd backend language) | Already covered by your FYP / Foxy Tales |
| React frontend | Phase 1 onward |
| MySQL | Phase 2 onward |
| Redis | Phases 2, 4, 5 (Steam caching, PCGamingWiki caching, LLM response caching, rate-limit state) |
| Git | All phases |
| CI/CD | Phase 0 (GitHub Actions) |
| Agile/Scrum signal | Sprint-tagged commits + README changelog |
| AWS | Phase 6 (EC2, RDS, ECR, CloudFront, Parameter Store, CloudWatch) |
| Docker | Phase 0 + Phase 6 (multi-service Compose, multi-stage Dockerfile, ECR deployment) |
| **AI integration** | Phase 5 (Gemini API for explanations — both forward and reverse modes) |
| **External API integration** | Phases 2, 4 (Steam OpenID + Web API, PCGamingWiki Cargo) |
| Payment gateway | Phase 5 (Stripe + Cashier + webhook signature + CloudFront carve-out) |
| Unit & feature testing | Phases 1, 2, 3, 4, 5 (PHPUnit, including security-flavored tests and anchor regression tests) |
| Technical documentation | Four focused `*.md` files + `NATIVE_AGENT_CONTRACT.md` |
| Brute-force/auth security | Phase 1 (throttle middleware + `Retry-After`) |
| HTTPS/transport security | Phase 6 (CloudFront mandatory) |
| Secrets management | Phase 6 (Parameter Store) |
| Sockets (preferred) | Phase 7 stretch — already covered in your existing Quiplash portfolio if not built here |
| System-boundary thinking | `NATIVE_AGENT_CONTRACT.md` (Phase 6) |

---

## Top 7 risks and mitigations

| # | Risk | Mitigation |
|---|------|------------|
| 1 | AWS billing surprise from $200 credit pool | $20 Budgets alert before any provisioning. No NAT Gateway. t3.small (not larger). Tear down within 48 hours of going live. |
| 2 | Phase 4 consumes more time than budgeted | The 1-day spike at the start of Phase 4 makes the PCGamingWiki keep/skip decision early. If skipped, the anchor dataset + heuristic engine ship on their own — fully demoable. |
| 3 | LLM API costs unexpected spike | Redis-cache identical-input requests aggressively. Unit-test cache key construction (timestamp-in-key is the classic bug). Cap per-user usage via the rolling-window check. Use a cost-effective Gemini Flash model, not a flagship model. |
| 4 | Stripe webhook silent failure | CloudFront cache-behavior carve-out applied before testing. `stripe trigger` against the live URL during the 48h window, not after. CSRF-exempt the webhook route explicitly. |
| 5 | Demo Steam connection fails because interviewer's profile is private | OpenID handles auth cleanly; the seeded demo account with a known-public profile is the fallback path. Private-profile error message points to the two specific toggles. |
| 6 | Container OOM during demo | t3.small (2 GB) instead of t2.micro. `docker stats` check in the pre-demo checklist. Queue worker can be dropped from the live deploy as a last resort. |
| 7 | Anchor dataset quality challenged in interview | Each anchor cites its source (Tom's Hardware, Digital Foundry, PCGamingWiki) in `notes`. Anchor regression tests demonstrate the heuristic is calibrated against ground truth. *"This is a generalizable engine calibrated against curated anchors, not a 240-entry lookup table"* is the honest answer. |

---

## What v1, v2, v3, and v4 collectively taught us

Worth keeping for future portfolio plans:

- **AWS pricing assumptions need to be re-verified, not remembered.** Free tier changed in mid-2025; tutorials older than that are wrong about new accounts.
- **Features added "for the resume" undermine the resume.** Redis "to put on the stack list" was a bad answer; Redis for LLM-response caching and external-API rate-limit protection is a strong answer.
- **Hidden scope kills timelines.** Anything that requires a long-running service or external API needs to be scoped in Phase 0, not discovered mid-build.
- **Premium gates need a real product story AND need to leave the free demo path intact.** "Free users only get 20 games" was a v3 mistake — the import flow showed 200 games and 180 were dead-ends. Cap usage, don't restrict the catalog.
- **Time-boxes with defined fallbacks > open-ended phases.** Every phase here can be cut to a shipping minimum if it overruns; the project always reaches a deployable state.
- **The browser security model is a feature constraint, not a bug to hide.** Saying "the browser cannot read the GPU model; users select it manually" is stronger than faking auto-detection.
- **LLM integration is most defensible when it can't break the core feature.** Rule-based recommendation AND rule-based diff with LLM explanation prose means hallucination can never affect the actual advice — a defensible interview answer that holds across both modes.
- **Percentile tiering across a long-tail dataset is almost always wrong.** Use absolute thresholds calibrated to current-gen hardware. Document the cutoffs.
- **Auth pattern choices have a "right answer" for the framework you're using.** Sanctum SPA mode for first-party React, not API tokens. "Either is defensible" is a weak interview answer when one is the documented intent.
- **Spikes are cheap insurance.** A 1-day Phase 4.0 spike that verifies PCGamingWiki rate limit + Steam app ID lookup hit rate is the difference between a phase that ships and a phase that spirals.
- **The half you didn't build deserves an artifact.** `NATIVE_AGENT_CONTRACT.md` is engineering evidence that you've thought about the system boundary — far stronger than a verbal hand-wave at the same question.
