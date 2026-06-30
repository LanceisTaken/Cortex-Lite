# Cortex Lite — Build Plan (v3)

**Goal:** A portfolio web application demonstrating the backend, cloud, and AI-integration skills required by the Razer Software Engineer JD, scoped around a coherent Cortex-shaped product story.

**Product concept:** A web companion app for PC gamers. Connects to Steam to auto-import your library, lets you track play sessions, and provides AI-assisted graphics settings recommendations based on your hardware and goal (performance vs quality). Premium tier unlocks unlimited recommendations and reverse-mode feedback.

**Stack:** Laravel · MySQL · Redis · React · Stripe · Docker · AWS · Anthropic/OpenAI API · Steam Web API · PCGamingWiki API

**Time-boxed effort:** ~7 phases, ~6–8 weeks part-time. Per-phase budgets below with defined fallbacks if a phase overruns.

> **Changelog from v2:**
> - **Removed:** Live performance dashboard, WebSockets/Reverb, queue worker (broadcasting), CSV export premium gate. The live dashboard had no honest product justification — it was simulated data dressed as a feature. Cut cleanly rather than carried.
> - **Added:** Steam Web API auto-import (library + playtime), manual session tracking, hardware benchmark database, PCGamingWiki integration, AI-assisted settings optimizer (rule-based + LLM explanation), revised premium tier.
> - **Kept from v2:** AWS Free Tier corrections, mandatory CloudFront HTTPS, Parameter Store for secrets, GitHub Actions CI, rate-limited login, authorization-boundary tests, time-boxes with fallbacks, three-file documentation model.
>
> **The honest interview narrative:** *"This is the web/cloud companion layer for a Cortex-style product. The native agent that would collect real hardware telemetry and detect game launches is out of scope — the browser security model makes it impossible to build that in the web layer. What I built is the backend, data pipeline, AI integration, and cloud infrastructure that a native agent would feed into."*

---

## Guiding principles

- **Build for depth, not feature count.** Every feature must be one you can defend in an interview.
- **Code literacy first.** Read, understand, and explain every line. If a phase generates code you can't walk through, stop and study before moving on.
- **Honest justifications.** No "Redis for the resume." Every architectural choice has a real reason stated openly in `ARCHITECTURE.md`.
- **Commit like you're in a sprint.** Feature branches, PR-style merges, sprint-tagged commits (`[Sprint 3] add Steam library sync`). This is your Agile evidence.
- **Three docs, not five.** `README.md` (incl. sprint changelog), `ARCHITECTURE.md` (incl. AWS deployment section), `TROUBLESHOOTING.md` (failure modes + fixes).
- **Time-box ruthlessly.** Every phase has a budget. Overruns trigger pre-defined scope cuts, not extensions.

---

## Final feature set (locked)

### Core user-facing features

1. **Account & authentication.** Register, login, logout. Rate-limited login. Sanctum token auth from React SPA.
2. **Steam-connected game library.** User connects their Steam account (via Steam ID). Backend auto-imports their owned games, playtime, and metadata. Manual add/edit/delete also supported for non-Steam games.
3. **Library management UI.** Paginated game list with search, filter (by platform/genre/status), and sort. Per-game status: Currently Playing / Backlog / Completed / Dropped.
4. **Manual session tracking.** Start session → pick game from library → end session. Backend timestamps both events, persists a record, increments the game's total playtime.
5. **Steam playtime backfill.** A scheduled job re-syncs Steam-connected libraries periodically, picking up newly purchased games and aggregate playtime changes.
6. **AI-assisted settings optimizer.** User inputs hardware (or uses browser-detected partial specs as a starting point) and selects a goal (performance / balanced / quality) and a game. Backend produces a recommended settings configuration with a natural-language explanation.
7. **Premium subscription (Stripe).** Free tier: 3 recommendations per month, top-20 curated games only. Premium tier ($5/month): unlimited recommendations, all games (including heuristic fallback), reverse-mode "paste your current settings and get feedback."

### Backend / infrastructure features (not user-visible but built)

8. **Hardware benchmark database.** Ingested from public PassMark CSV. GPUs and CPUs classified into 4 performance tiers via benchmark score buckets.
9. **PCGamingWiki integration.** Scheduled ingestion of game graphics-settings metadata (which APIs supported, available video options, etc.) with strict rate-limit compliance and Redis caching.
10. **Curated settings dataset.** Hand-curated optimal settings for the top 20 games × 4 GPU tiers × 3 goals. Used as the rule-based recommendation source.
11. **Heuristic fallback engine.** For games outside the curated 20, a generic GPU-tier-based ruleset filtered by the game's available settings (from PCGamingWiki).
12. **LLM integration layer.** Recommendations are generated by the rule-based engine; the LLM only writes the explanation prose, grounded in the structured recommendation. No hallucinated settings.
13. **Six-service local dev stack.** Docker Compose: `app`, `nginx`, `mysql`, `redis`, `scheduler` (for Laravel scheduled jobs), and `queue` (for background sync jobs).
14. **CI pipeline.** GitHub Actions running `php artisan test` on every push.
15. **AWS deployment.** EC2 (Dockerized), RDS MySQL, ECR, CloudFront (HTTPS), Parameter Store (secrets), CloudWatch (logs).

### Stretch goals (only if Phases 0–6 finish on time)

16. **Real-time Steam sync progress via WebSockets.** Steam library sync for a 100+ game library can take 30+ seconds. WebSocket-pushed progress events give a live progress bar. This is the only honest justification for WebSockets in the project; if you can't get there, document the gap openly.
17. **Deal alerts.** Integration with IsThereAnyDeal or CheapShark APIs to show price drops on games in the user's library.

---

## Phase 0 — Setup, Dockerization, and CI

**Time-box: 3–5 days. Fallback if overrun: drop the scheduler service stub, add it in Phase 4.**

**Why:** Get the entire local dev environment working before writing any feature code. Scaffold every service you'll eventually need now — including the scheduler and queue services — so that adding the features that depend on them is a code change, not an infrastructure change.

- [ ] Initialise Git repo, push to GitHub (public). Add a placeholder `README.md`.
- [ ] Scaffold Laravel project (`composer create-project laravel/laravel cortex-lite`).
- [ ] Scaffold React frontend in a `client/` subdirectory (`npm create vite@latest client -- --template react`).
- [ ] **Write `docker-compose.yml` with all six services from day one:**
  - `app` — PHP-FPM + Laravel
  - `nginx` — web server (no WebSocket headers needed in v3 unless you pursue stretch goal 16)
  - `mysql` — version 8.x
  - `redis` — version 7.x
  - `scheduler` — same image as `app`, runs `php artisan schedule:work`
  - `queue` — same image as `app`, runs `php artisan queue:work`
- [ ] Create `.env.example` with every variable across all phases. Stripe keys, Steam API key, Anthropic API key, PCGamingWiki user-agent string, DB creds — all listed (values blank).
- [ ] Document the React/Docker split: comment in `docker-compose.yml` and a note in `README.md`. *"Vite dev server runs on the host (`npm run dev`) for hot reload performance. In production, the build output is served as static files by the `nginx` container."*
- [ ] Configure Laravel `.env` to point at the Docker MySQL and Redis services.
- [ ] Run Laravel migrations against the Dockerized MySQL.
- [ ] **Add GitHub Actions CI** — workflow runs `php artisan test` on every push. Green badge in README.
- [ ] **Add a `Makefile`** with `make up`, `make down`, `make migrate`, `make test`, `make logs`, `make shell` (drop into the `app` container).
- [ ] Commit: `[Sprint 0] scaffold project, dockerize dev environment, add CI`.

**Deliverable:** `docker-compose up` brings all six services healthy. CI runs green on every push.

---

## Phase 1 — Auth & user management

**Time-box: 3–4 days. Fallback if overrun: skip the React register page polish; functional ugly UI is fine.**

- [ ] Install Laravel Sanctum for SPA token authentication.
- [ ] Build register/login/logout API endpoints (`POST /api/register`, `POST /api/login`, `POST /api/logout`).
- [ ] **Apply Laravel's throttle middleware to `/api/login`** — `throttle:5,1` (5 attempts/min). One-line change closing the brute-force gap.
- [ ] Build React login + register pages, store auth token, attach to subsequent requests via Axios interceptor.
- [ ] **Decide and document Sanctum token storage in React.** `localStorage` vs `httpOnly` cookies. Pick one, note the tradeoff in `ARCHITECTURE.md`. Either is defensible — what isn't defensible is having no answer when asked.
- [ ] Build protected `/api/me` endpoint and a React `Dashboard` page displaying the logged-in user.
- [ ] Write PHPUnit feature tests: register success, login success, login failure with bad credentials, throttle middleware triggers after 5 failed attempts.
- [ ] CI must pass on the PR. Merge to `main` with a clear merge commit.

**Resume bullet (earn before claiming):** *"Implemented token-based authentication with Laravel Sanctum, rate-limited login endpoints, and PHPUnit feature tests covering registration, login, authorization middleware, and brute-force throttling."*

---

## Phase 2 — Game library + Steam Web API integration

**Time-box: 5–7 days. Fallback if overrun: ship Steam auto-import without the manual CRUD UI; add manual UI later. The Steam integration is the differentiator here, not the form fields.**

**Why:** This is your "real backend" feature and your first external API integration. Demonstrates database schema design, REST patterns, validation, authorization, and external API consumption with rate limiting and caching.

### Database & manual CRUD

- [ ] Design the `games` schema: `id`, `user_id`, `title`, `platform`, `genre`, `status` (enum: playing/backlog/completed/dropped), `hours_played`, `last_played_at`, `steam_app_id` (nullable), `source` (enum: manual/steam), `cover_url`, timestamps. FK on `user_id` cascade-deletes.
- [ ] Eloquent model with `User hasMany Games` relationship.
- [ ] 5 RESTful endpoints under `/api/games`: index (paginated), store, show, update, destroy. All scoped to the authenticated user.
- [ ] Form Request validation classes.
- [ ] PHPUnit **authorization-boundary tests**: User A cannot read, update, or delete User B's games. This is the test class reviewers actually look for.
- [ ] React UI: paginated list, search/filter/sort controls, status dropdown, add/edit/delete forms.

### Steam Web API integration

- [ ] Register for a free Steam Web API key at `steamcommunity.com/dev`. Store in `.env` and `.env.example`.
- [ ] Add `steam_id` (nullable) column to `users` table.
- [ ] Build a `SteamClient` service class (`app/Services/SteamClient.php`) wrapping the Steam API. Methods: `getOwnedGames($steamId)`, `getPlayerSummary($steamId)`.
- [ ] **Cache Steam API responses in Redis** with a 1-hour TTL. Justification (state in `ARCHITECTURE.md`): Steam's API has rate limits (100k calls/day per key) and the data changes slowly; caching reduces latency and protects against quota exhaustion.
- [ ] Build `POST /api/steam/connect` endpoint — user submits their Steam ID, backend validates by calling `getPlayerSummary`, persists `steam_id` on the user.
- [ ] Build `POST /api/steam/sync` endpoint — calls `getOwnedGames`, bulk-inserts new games tagged `source: 'steam'`, updates playtime on existing Steam games.
- [ ] **Handle the private-profile edge case gracefully.** Steam returns an empty array for private profiles. Show a friendly UI message with a link to the Steam privacy settings page. Document this in `TROUBLESHOOTING.md`.
- [ ] **Schedule a daily background sync.** Add a Laravel scheduled task in `app/Console/Kernel.php` that re-syncs every connected user nightly. The scheduler service from Phase 0 picks this up.
- [ ] PHPUnit tests for the Steam integration with HTTP fakes (Laravel's `Http::fake()` is the right tool here).

**Resume bullet (earn before claiming):** *"Designed and implemented a paginated game library REST API with Steam Web API integration for automatic library import and scheduled playtime sync, including Redis caching to respect external rate limits, graceful handling of private-profile edge cases, and authorization-boundary feature tests."*

---

## Phase 3 — Session tracking & history

**Time-box: 3–5 days. Fallback if overrun: ship sessions without the history visualization; a list view is enough.**

**Why:** Connects the library to actual usage data. Gives the project a real product workflow (track what you play) instead of a static CRUD demo.

- [ ] `sessions` table schema: `id`, `user_id`, `game_id`, `started_at`, `ended_at` (nullable while in-progress), `duration_seconds`, timestamps. FK on both `user_id` and `game_id`.
- [ ] Endpoints: `POST /api/sessions/start` (body: `game_id`, returns the in-progress session), `POST /api/sessions/{id}/end` (computes duration, increments `games.hours_played`).
- [ ] Constraint: a user can have at most one in-progress session at a time. Enforce at the application layer with a clear error response.
- [ ] React UI: a "Start Session" button next to each game in the library, a persistent in-progress session indicator (shows elapsed time, "End session" button) in the header.
- [ ] History page: list of past sessions grouped by game, with date, duration, and total per-game playtime.
- [ ] PHPUnit tests: starting a session, ending a session, "only one in-progress session" constraint, authorization boundary.

**Resume bullet (earn before claiming):** *"Built a session tracking system with start/end lifecycle endpoints, per-game playtime aggregation, application-level constraints (one active session per user), and a session history view."*

---

## Phase 4 — Hardware database & game settings data pipeline

**Time-box: 5–7 days. Fallback if overrun: skip PCGamingWiki ingestion, hand-curate settings options for the top 20 games manually. The curated dataset is the load-bearing piece; PCGamingWiki is the nice-to-have layer on top.**

**Why:** This is the project's actual hard problem. The recommender logic is easy; the data is the engineering.

### Hardware benchmark database

- [ ] Download the public PassMark GPU benchmark dataset from Kaggle (CSV).
- [ ] Schema: `gpus` table — `id`, `name`, `manufacturer`, `g3d_mark`, `tier` (enum: low/mid/high/enthusiast), `created_at`.
- [ ] Schema: `cpus` table — same shape with CPU benchmark score.
- [ ] **Tier-assignment logic** — buckets based on benchmark percentile (e.g., bottom 25% = low, 25–50% = mid, 50–80% = high, top 20% = enthusiast). Document the exact thresholds in `ARCHITECTURE.md` so an interviewer can ask "why this cutoff" and you have an answer.
- [ ] Laravel seeder ingests the CSV. Run it as part of the deployment process.
- [ ] Build `GET /api/hardware/gpus?search=...` and `GET /api/hardware/cpus?search=...` — typeahead endpoints for the hardware-selection UI.
- [ ] React: hardware input form with autocomplete dropdowns.

### Browser-side hardware auto-detect (best-effort)

- [ ] Use `navigator.hardwareConcurrency`, `navigator.deviceMemory`, and the WebGPU API (where supported) to pre-fill what the browser knows.
- [ ] **Be honest about the limits.** The browser cannot identify the exact GPU model. Show a UI message: *"Auto-detected: 16 GB RAM, 12 cores. Please select your GPU manually — browsers don't expose the GPU model."* This is interview gold — it shows you understand the security model.

### PCGamingWiki integration

- [ ] Build a `PcGamingWikiClient` service class wrapping their MediaWiki API.
- [ ] **Respect their rate limit strictly: 30 requests/minute max, with a proper custom user-agent string including contact email.** This is non-negotiable per their published API policy — failure to comply gets your IP blocked.
- [ ] Cache responses aggressively in Redis (7-day TTL).
- [ ] Schema: `game_metadata` table — `id`, `game_id` (FK), `direct3d_versions`, `vulkan_supported`, `hdr_supported`, `ultrawide_supported`, `dlss_supported`, `fsr_supported`, `ray_tracing_supported`, `raw_response` (jsonb for future fields), timestamps.
- [ ] Scheduled job: for each Steam-imported game without metadata, look up via PCGamingWiki by Steam app ID, persist the result. The 30/min rate limit means a 100-game library takes ~3.5 minutes to fully populate — that's fine for a background job.
- [ ] Document the rate-limit-and-backoff handling in `TROUBLESHOOTING.md`.

### Curated settings dataset

- [ ] Schema: `setting_presets` table — `id`, `game_id`, `gpu_tier`, `goal` (enum: performance/balanced/quality), `settings_json`, `notes` (text), timestamps.
- [ ] Pick the top 20 games. Suggested starting list: Cyberpunk 2077, Elden Ring, CS2, Fortnite, Valorant, GTA V, Apex Legends, RDR2, Baldur's Gate 3, Hogwarts Legacy, Starfield, COD MW3, Helldivers 2, Stellar Blade, Black Myth Wukong, Marvel Rivals, Path of Exile 2, Dota 2, League of Legends, Minecraft.
- [ ] For each: 4 tiers × 3 goals = 12 records per game = 240 records total.
- [ ] Reference sources for curation (cite in the dataset's notes): Tom's Hardware, Digital Foundry, PCGamingWiki, the game's own published recommended specs.
- [ ] Build a Laravel seeder that loads the curated JSON file into the database.
- [ ] **Treat the dataset itself as a portfolio artifact** — commit the JSON source file, document the curation methodology in `ARCHITECTURE.md`. "I curated this dataset" is itself an interview talking point.

### Heuristic fallback ruleset

- [ ] For games outside the curated 20, define a `HeuristicRecommender` service. Inputs: GPU tier, CPU tier, RAM bucket, goal, plus PCGamingWiki metadata about available settings. Outputs: same shape as `setting_presets.settings_json`.
- [ ] The rules are explicit (no AI here). Example for `(tier: mid, goal: performance)`: `{resolution: '1080p', resolution_scale: 80, texture_quality: 'medium', shadow_quality: 'low', anti_aliasing: 'FXAA', ray_tracing: false, motion_blur: false, upscaling: 'FSR_QUALITY_IF_AVAILABLE'}`.
- [ ] Document the full rule table in `ARCHITECTURE.md`. An interviewer asking "what would your system recommend for an RTX 3060 + Cyberpunk + performance" should get the same answer as the code.

**Resume bullet (earn before claiming):** *"Built a multi-source data pipeline for hardware classification and game-settings recommendations: ingested a public benchmark dataset into a tiered hardware database, integrated rate-limit-compliant PCGamingWiki API consumption with Redis caching for game metadata, curated a 240-record settings dataset for top-20 titles, and implemented a heuristic fallback engine for long-tail games."*

---

## Phase 5 — AI-assisted optimizer + Stripe premium tier

**Time-box: 5–7 days. Fallback if overrun: ship the optimizer without Stripe gating (one premium-quality experience for all users); add billing after deployment.**

**Why:** This is the differentiating feature and the LLM integration that JD-level "current technologies" claim rests on.

### The recommendation engine

- [ ] `RecommendationEngine` service. Inputs: `game_id`, `gpu_id`, `cpu_id`, `ram_gb`, `goal`. Algorithm:
  1. Look up GPU tier, CPU tier, bucket RAM.
  2. Check if `setting_presets` exists for `(game_id, gpu_tier, goal)`. If yes, use it.
  3. If no, invoke `HeuristicRecommender` with PCGamingWiki metadata.
  4. Apply CPU/RAM bottleneck adjustments (e.g., if RAM < 16GB, force lower texture pool).
  5. Return the structured settings JSON.
- [ ] Endpoint: `POST /api/recommend` taking the inputs, returning the settings JSON plus an `explanation` field (populated next step).
- [ ] PHPUnit tests for the engine: known inputs → expected outputs. The recommendation engine is deterministic, so this is straightforward.

### LLM-generated explanation

- [ ] Choose provider: **Anthropic Claude Haiku** is the recommendation — fast, cheap (~$0.001 per request at expected token volumes), good at structured explanation tasks. OpenAI GPT-4o-mini is the equivalent fallback.
- [ ] Build an `ExplanationGenerator` service that takes the structured recommendation and the user's hardware/goal, sends a prompt to the LLM, and returns prose.
- [ ] **Prompt design — the LLM never decides settings; it only explains.** The prompt template includes the rule-based recommendation as input and asks the LLM to write 3–4 sentences explaining *why* those settings make sense for the given hardware and goal. State this constraint explicitly in `ARCHITECTURE.md` — hallucination cannot affect the recommendation, only the prose.
- [ ] **Cache LLM responses in Redis.** Same `(game_id, gpu_tier, cpu_tier, ram_bucket, goal)` tuple → identical recommendation → cache the explanation. This makes Redis genuinely load-bearing for cost control, not just resume optics.
- [ ] **Handle LLM API failures gracefully.** Timeouts, content filtering, rate limits — return the structured recommendation with a fallback static explanation ("Settings tuned for [goal] on [tier]-tier hardware. See documentation for per-setting details.") rather than failing the whole request. Document this in `TROUBLESHOOTING.md`.
- [ ] React UI: hardware-selection form, game-search field, goal selector (performance/balanced/quality), submit button, results card showing the settings table and the LLM explanation.

### Stripe premium gating

- [ ] Add `is_premium`, `stripe_customer_id`, `monthly_recommendation_count`, `monthly_count_reset_at` columns to `users`.
- [ ] Install Laravel Cashier.
- [ ] `POST /api/checkout` creates a Stripe Checkout Session for a $5/month "Cortex Premium" subscription.
- [ ] Stripe webhook handler at `/api/stripe/webhook` flipping `is_premium` on subscription create/cancel events. **Verify webhook signatures** — most-asked Stripe interview topic.
- [ ] **Free tier limits enforced at the API layer:**
  - Max 3 recommendations per calendar month
  - Restricted to the curated top-20 games (no heuristic fallback)
  - No reverse mode
- [ ] **Premium tier unlocks:**
  - Unlimited recommendations
  - Heuristic fallback for all games
  - Reverse mode: paste current settings JSON, get feedback on what to change for a given goal
- [ ] Two explicit Stripe webhook test cases: wrong-signature rejection, subscription-cancellation flips `is_premium` to false.
- [ ] React UI: usage counter on the dashboard ("2 / 3 recommendations used this month"), upgrade button, locked state on the heuristic-fallback and reverse-mode features for free users.

**Resume bullet (earn before claiming):** *"Built a hybrid recommendation engine combining a deterministic rule-based core with LLM-generated natural-language explanations (Anthropic Claude Haiku), with Redis caching for cost control, graceful LLM-failure fallback, and a Stripe-gated freemium model enforcing per-user monthly quotas and feature tiers."*

---

## Phase 6 — AWS deployment

**Time-box: 4–5 days. Hard live-deployment window: 48 hours, then tear down. Fallback if overrun: deploy backend only (no CloudFront), screenshot what you have, tear down.**

### ⚠️ AWS cost reality (post-July 2025 accounts)

If your AWS account was created on or after July 15, 2025: no 12-month per-service free tier exists. You get **$200 in credits expiring after 6 months**, shared across all services. RDS + EC2 + data transfer + a NAT Gateway (~$33/month if accidentally created) all spend the same credits.

**Mandatory cost protections:**

- [ ] Check your AWS account creation date.
- [ ] Set an **AWS Budgets alert at $20 spend, hard threshold, email notification**, before creating any resource.
- [ ] **Never create a NAT Gateway.** Put EC2 in a public subnet with a tight security group.
- [ ] **48-hour live-deployment rule.** Spin up, screenshot, demo, tear down within 48 hours. Calendar reminder.

### Architecture

- [ ] **EC2 t2.micro or t3.micro** in a public subnet. Runs the Docker Compose stack (app + nginx + scheduler + queue).
- [ ] **RDS MySQL db.t4g.micro** in the same VPC. Security group permits port 3306 only from the EC2 security group.
- [ ] **Redis in-container on EC2**, NOT ElastiCache. State the tradeoff openly in `ARCHITECTURE.md`: *"For this workload, in-container Redis on EC2 is the right call. ElastiCache would be correct at multi-instance scale where Redis state must outlive any single EC2 instance — that's not the case here."*
- [ ] **ECR** for Docker image storage.
- [ ] **Parameter Store (Systems Manager)** for all secrets — Stripe keys, Steam API key, Anthropic API key, DB password. Injected at container start. **No `.env` files on disk in production.**
- [ ] **CloudFront in front of EC2** using the free default `*.cloudfront.net` domain. **HTTPS is non-optional.** Sanctum tokens and Stripe Checkout returns over plain HTTP is a bad demo signal.
- [ ] **CloudWatch Logs** for structured application logs.

### Deployment steps

- [ ] Provision EC2 with security group: inbound 80/443 from anywhere, SSH from your IP only.
- [ ] Provision RDS in the same VPC.
- [ ] Push Docker images to ECR.
- [ ] SSH into EC2, install Docker + Docker Compose, pull images.
- [ ] Entry-point script fetches secrets from Parameter Store on container start.
- [ ] Run `docker-compose up -d`.
- [ ] Run the database migrations and the hardware/curated-settings seeders on the live RDS.
- [ ] Provision CloudFront distribution pointing at the EC2 public DNS. HTTPS-only viewer protocol.
- [ ] Test the full flow over HTTPS: register → connect Steam → import library → start/end a session → request a recommendation → upgrade via Stripe → use a premium-only feature.
- [ ] **Screenshot everything:** EC2 console, RDS dashboard, ECR repos, CloudWatch logs (structured JSON), security group rules, CloudFront distribution, Parameter Store entries (values masked), the running app at its CloudFront URL, an example recommendation result.
- [ ] Record a 2–3 minute demo video walking through the live app.
- [ ] Document everything in `ARCHITECTURE.md` (Infrastructure section): architecture diagram, every AWS service used and why, security model, cost breakdown, teardown procedure.
- [ ] **Tear down all paid resources** post-screenshot. Keep all IaC scripts / config in the repo. Cancel any active Stripe test subscriptions.
- [ ] Verify the AWS bill is back to $0/day post-teardown.

**Resume bullet (earn before claiming):** *"Deployed a multi-service Dockerized application to AWS using EC2, RDS MySQL, ECR, CloudFront (HTTPS), Parameter Store (secrets), and CloudWatch (structured logging), with security-group network isolation, documented infrastructure architecture, and a documented teardown procedure."*

---

## Phase 7 (Stretch) — Real-time Steam sync progress via WebSockets

**Only attempt if Phases 0–6 finish on time. If you skip this, document the gap openly: "WebSocket support is a planned future enhancement; the current Steam sync uses HTTP polling for progress."**

**Time-box: 3–4 days.**

- [ ] Install Laravel Reverb. Add the `reverb` service to `docker-compose.yml`.
- [ ] Configure nginx WebSocket proxy headers (`proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "Upgrade";`).
- [ ] Configure Sanctum stateful domains and the broadcasting auth endpoint.
- [ ] `routes/channels.php` authorization callback for a `sync.{userId}` private channel.
- [ ] Modify the Steam sync job to dispatch broadcast events on each game processed (`SteamSyncProgressEvent`).
- [ ] React: subscribe to the channel via Laravel Echo, render a live progress bar during sync.
- [ ] Implement client-side reconnect with exponential backoff.
- [ ] Test two failure modes: kill the Reverb container mid-sync (reconnect should recover), token expiry mid-sync (re-auth should reconnect).
- [ ] Document everything in `TROUBLESHOOTING.md`: nginx Upgrade headers, channel auth callback, queue worker requirement, common failure modes.

**Resume bullet (earn before claiming):** *"Added real-time progress reporting for long-running Steam library sync jobs using Laravel Reverb WebSockets, private-channel authorization via Sanctum, exponential-backoff reconnection, and documented failure-mode handling."*

---

## Final deliverables checklist

- [ ] Working `docker-compose.yml` (6 services; 7 if you ship stretch goal 16)
- [ ] Working CI pipeline (green badge in README)
- [ ] `README.md` — what it is, how to run locally (`make up`), sprint changelog, screenshots of live deployment, demo video link
- [ ] `ARCHITECTURE.md` — system diagram, technology decisions with stated tradeoffs (Redis justification, ElastiCache-vs-local, CloudFront HTTPS, React/Docker split, Sanctum token storage, GPU tier thresholds, LLM-vs-rule-based separation, curated dataset methodology), AWS infrastructure section, cost breakdown
- [ ] `TROUBLESHOOTING.md` — external API failure modes (PCGamingWiki rate limit, Steam private profiles, LLM API failures), Stripe webhook edge cases, AWS deployment issues, container orchestration failures
- [ ] PHPUnit test suite — auth, throttle, authorization boundaries, Steam integration with HTTP fakes, sessions constraints, recommendation engine determinism, Stripe webhook signature & cancellation
- [ ] Curated `setting_presets.json` dataset committed to the repo
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
| Redis | Phases 2, 4, 5 (Steam caching, PCGamingWiki caching, LLM response caching, rate-limit state) — multiple honest justifications |
| Git | All phases |
| CI/CD | Phase 0 (GitHub Actions) |
| Agile/Scrum signal | Sprint-tagged commits + README changelog |
| AWS | Phase 6 (EC2, RDS, ECR, CloudFront, Parameter Store, CloudWatch) |
| Docker | Phase 0 + Phase 6 (multi-service Compose, ECR deployment) |
| **AI integration** | Phase 5 (Anthropic API for explanations) |
| **External API integration** | Phases 2, 4 (Steam, PCGamingWiki) |
| Payment gateway | Phase 5 (Stripe + Cashier + webhook verification) |
| Unit & feature testing | Phase 1 + Phase 2 + Phase 3 + Phase 5 (PHPUnit) |
| Technical documentation | Three focused `*.md` files + curated dataset documentation |
| Brute-force/auth security | Phase 1 (throttle middleware) |
| HTTPS/transport security | Phase 6 (CloudFront mandatory) |
| Secrets management | Phase 6 (Parameter Store) |
| Sockets (preferred) | Phase 7 stretch — already covered in your existing Quiplash portfolio if not built here |

---

## Top 5 risks and mitigations

| # | Risk | Mitigation |
|---|---|---|
| 1 | AWS billing surprise from $200 credit pool | $20 Budgets alert before any provisioning. No NAT Gateway. Tear down within 48 hours of going live. |
| 2 | Phase 4 (data pipeline) consumes more time than budgeted | Hand-curated dataset is the load-bearing piece. If PCGamingWiki integration overruns, ship without it — the curated dataset alone is enough for the demo. |
| 3 | LLM API costs unexpected spike | Redis-cache identical-input requests aggressively. Cap per-user monthly recommendations (free tier already does this via business rules). Use Haiku-tier model, not flagship. |
| 4 | Stripe webhook silent failure | Two explicit webhook test cases (Phase 5). Stripe CLI local forwarding validated before deploy. |
| 5 | Hand-curated dataset quality is too thin to be defensible | Cite sources per record in the dataset's notes field (Tom's Hardware, Digital Foundry, etc.). Document the methodology openly in `ARCHITECTURE.md`. "I curated this dataset against published guides" is a strong honest answer; "I made up these numbers" is not. |

---

## What v1, v2, and v3 collectively taught us

Worth keeping for future portfolio plans:

- **AWS pricing assumptions need to be re-verified, not remembered.** Free tier changed in mid-2025; tutorials older than that are wrong about new accounts.
- **Features added "for the resume" undermine the resume.** Redis "to put on the stack list" was a bad answer; Redis for LLM-response caching and external-API rate-limit protection is a strong answer.
- **Hidden scope kills timelines.** Anything that requires a long-running service or external API needs to be scoped in Phase 0, not discovered mid-build.
- **Premium gates need a real product story.** "Export 60 seconds of mock data as CSV" was contrived; "3 free recommendations per month, unlimited for premium" is a recognizable freemium model.
- **Time-boxes with defined fallbacks > open-ended phases.** Every phase here can be cut to a shipping minimum if it overruns; the project always reaches a deployable state.
- **The browser security model is a feature constraint, not a bug to hide.** Saying "the browser cannot read the GPU model; users select it manually" is stronger than faking auto-detection.
- **LLM integration is most defensible when it can't break the core feature.** Rule-based recommendation with LLM explanation prose means hallucination can never affect the actual advice — a defensible interview answer.
