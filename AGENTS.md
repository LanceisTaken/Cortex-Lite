# AGENTS.md — Cortex Lite

Read at the start of every session. This file is the persistent context for this project.

**Session start:** Before doing anything else, read the most recent entry (topmost, under "Most recent first") in `SESSION_LOG.md` to see what the previous agent did and pick up where it left off.

## Project identity
Cortex Lite — a Laravel/React/MySQL/Redis/Docker/AWS portfolio web app. PC gaming companion: Steam-connected game library, manual session tracking, AI-assisted graphics settings optimizer (rule-based engine + Codex Haiku for prose explanations only), and Stripe freemium tier. Built to demonstrate skills for a Razer Software Engineer JD.

## Repo layout
The repo root is the Laravel project. Key non-standard paths:
- `client/` — React frontend (Vite). Dev server runs on the host (`npm run dev` from `client/`). Production build output is served by the nginx container via the multi-stage Dockerfile at `docker/nginx/Dockerfile`.
- `docker/app/Dockerfile` — PHP-FPM 8.4 image. Also used (as-is) for the scheduler and queue services in docker-compose.
- `docker/nginx/Dockerfile` — multi-stage: node20 builds the React app, then nginx:alpine serves `/api` → php-fpm and `/` → static build.
- `docker/nginx/default.conf` — nginx routing config.
- `.github/workflows/ci.yml` — runs `php artisan test` on every push.

## Development commands (always use these, never raw docker/php calls)
- `make up` — start all 6 services (app, nginx, mysql, redis, scheduler, queue)
- `make down` — stop all services
- `make migrate` — run Laravel migrations inside the app container
- `make test` — run PHPUnit inside the app container
- `make logs` — tail all container logs
- `make shell` — drop into a bash shell in the app container
- `make artisan CMD="..."` — run any artisan command, e.g. `make artisan CMD="route:list"`
- `make composer CMD="..."` — run any composer command inside the app container
- Frontend: `cd client && npm run dev` (host) or `npm run build` (production)

## Documentation map — keep these updated

All project docs (except this file and `README.md`) live under `docs/`. Paths below are relative to the repo root.

### docs/ARCHITECTURE.md
System design and infrastructure. Update when: adding or removing services, changing the AWS setup, making schema changes that affect system topology, or adding a new external API integration. Sections to maintain: Stack overview, Docker services, Database schema (high-level), AWS infrastructure (Phase 6+), External integrations, Security model.

### docs/DECISIONS.md
ADR-style tradeoff log. Every non-obvious architectural or implementation choice gets an entry here. Format per entry:

```
### [Decision title]
**Date:** YYYY-MM-DD
**Decision:** What was chosen.
**Rationale:** Why.
**Alternatives considered:** What was rejected and why.
**Consequences:** Any tradeoffs or follow-on effects.
```

Pre-seeded decisions that must already be documented here: Sanctum SPA cookie auth over API tokens; Redis justifications (Steam API caching for rate-limit protection, PCGamingWiki caching for rate compliance, LLM response caching for cost control, quota state); rolling 30-day window over reset job; LLM scoped to prose only (never decides settings); reverse mode as rule-based diff (SettingsDiffEngine) not LLM judgment; GPU tier absolute thresholds over percentile; t3.small over t2.micro for the live deploy; CloudFront cache-behavior carve-out for Stripe webhook; sync LLM call over async queue for v1.

### docs/TROUBLESHOOTING.md
Failure modes and fixes. Add an entry every time you hit a non-obvious error. Format:

```
### [Symptom]
**Cause:** ...
**Fix:** ...
```

Pre-seeded entries: Sanctum SPA CSRF cookie not sent (check `withCredentials: true` and CORS `Access-Control-Allow-Credentials`); Steam private-profile 422 (both Profile AND Game Details must be Public — two separate Steam privacy toggles); Stripe webhook signature failure (CloudFront default behavior strips Stripe-Signature — apply the cache-behavior carve-out for `/api/stripe/webhook`); Stripe CLI test-mode walkthrough (`stripe listen --forward-to localhost/api/stripe/webhook`, then `stripe trigger checkout.session.completed`); container OOM diagnosis (`docker stats` — scheduler + queue + php-fpm need t3.small not t2.micro); LLM API timeout fallback (return recommendation with static explanation, never fail the whole request); AWS teardown verification (`aws ce get-cost-and-usage ...`).

### README.md
What the project is, how to run it locally (`make up`), sprint changelog, screenshots of live deployment, demo video link, and an "Evaluator quick-start" section with the seeded demo account credentials. Update the sprint changelog section at the end of every phase.

### docs/NATIVE_AGENT_CONTRACT.md
Created in Phase 6. Describes the hypothetical native-agent telemetry payload schema (scope, auth, payload shape, update cadence, privacy, security boundaries). Answers the interview question "why didn't you build the agent?" with an engineering artifact. Do not create this file until Phase 6.

## Key architectural rules — enforce these in every code change
1. **The LLM never decides settings.** `RecommendationEngine` and `SettingsDiffEngine` are fully deterministic. `ExplanationGenerator` calls Haiku only to write prose from a structured input it receives — it never constructs recommendations.
2. **Redis cache keys must never include timestamps or request-unique values.** Cache keys for LLM responses must be `(game_id, gpu_tier, cpu_tier, ram_bucket, goal)` for forward mode and `hash(diff_structure, hardware_tier, goal)` for reverse mode. A timestamp-in-key bug multiplies LLM cost 1000×. Unit-test cache key construction.
3. **Sanctum SPA auth only.** Cookie-based, CSRF-protected, stateful. No API tokens for the first-party React client.
4. **Free tier gates usage volume, not catalog.** All games get recommendations. Free users get 3 recommendations + 5 reverse-mode calls per rolling 30-day window. Never restrict which games appear.
5. **Rolling 30-day window via event-table count.** `count where user_id = ? and type = 'recommend' and created_at >= now() - interval 30 day`. No counter column, no reset job.
6. **Transactions on multi-write operations.** Steam sync bulk-insert and session start/end must each be wrapped in a DB transaction.
7. **Every phase ends with a doc update.** Before merging any phase-completing PR, update DECISIONS.md with the phase's tradeoff entries and TROUBLESHOOTING.md with any new failure modes found.

## Testing expectations
PHPUnit feature tests must cover: auth flows (register, login, throttle 429 + Retry-After, CSRF rejection, mass-assignment protection), authorization boundaries + IDOR on every resource endpoint (User A cannot touch User B's games/sessions), Steam integration with `Http::fake()` (OpenID happy path, direct SteamID64 fallback, private-profile rejection, transactional rollback), session constraints (one active session per user, locking), recommendation engine determinism (known inputs → expected outputs), anchor regression tests (heuristic output matches curated anchors), SettingsDiffEngine correctness, LLM cache-key construction, Stripe webhook (wrong signature → 400, cancellation → is_premium false).

## Stack reference
Laravel 13, PHP 8.4, MySQL 8.4, Redis 7, React 19 (Vite), Tailwind, Laravel Sanctum (SPA mode), Laravel Cashier (Stripe), Laravel Scheduler, Laravel Queue, Docker Compose (6 services), GitHub Actions CI, AWS (Phase 6: EC2 t3.small, RDS MySQL, ECR, CloudFront, Parameter Store, CloudWatch), Anthropic Codex Haiku (`Codex-haiku-4-5-20251001`), Steam Web API (OpenID + GetOwnedGames with `include_appinfo=1&include_played_free_games=1`), PCGamingWiki Cargo API, Stripe.

## Phase tracker (update this as phases complete)
- [x] Phase 0 — Setup, Docker, CI
- [x] Phase 1 — Auth & user management
- [x] Phase 2 — Game library + Steam integration
- [x] Phase 3 — Session tracking
- [ ] Phase 4 — Hardware database + data pipeline (starts with 1-day PCGamingWiki spike)
- [ ] Phase 5 — AI optimizer + Stripe freemium
- [ ] Phase 6 — AWS deployment + NATIVE_AGENT_CONTRACT.md
- [ ] Phase 7 — WebSockets stretch goal (only if 0–6 finish on time)
