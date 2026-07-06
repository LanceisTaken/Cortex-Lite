# Cortex Lite

## What it is

A portfolio web app for PC gamers: Steam-connected game library, manual session tracking, AI-assisted graphics-settings optimizer (deterministic rule-based engine + Gemini API for prose explanations only), and Stripe freemium tier. Built to demonstrate the backend, cloud, and AI-integration skills required by the Razer Software Engineer JD.

**Stack:** Laravel 13 · PHP 8.4 · MySQL 8 · Redis 7 · React 19 (Vite) · Stripe · Docker · AWS · Gemini API · Steam Web API · PCGamingWiki API

## How to run it locally

```bash
make up                 # start all 6 services (app, nginx, mysql, redis, scheduler, queue)
make migrate            # run Laravel migrations
cd client && npm run dev   # start Vite dev server on http://localhost:5173
```

Then:
- Backend: http://localhost:8080
- Frontend: http://localhost:5173
- MySQL: `localhost:3306` (user `cortex`, password `cortex`, db `cortex_lite`)
- Redis: `localhost:6379`

Common tasks: `make test`, `make shell`, `make logs`, `make artisan CMD="route:list"`, `make composer CMD="require ..."`.

## Sprint changelog

- **Sprint 0 — Setup, Docker, CI.** Laravel 13 + React 19 scaffolded. Six-service Docker Compose stack (app, nginx, mysql, redis, scheduler, queue). Multi-stage prod Dockerfile for the React → nginx image. GitHub Actions CI running PHPUnit on every push. Four docs (`ARCHITECTURE.md`, `DECISIONS.md`, `TROUBLESHOOTING.md`, this README) live under `docs/`.
- **Phase 1** — Sanctum SPA auth (register/login/logout/me), password reset, email verification, throttled login with Retry-After, delete-account with Cashier subscription teardown. 34 feature + 6 unit tests (grew from the planned 23+6 as scope expanded with CashierInstallTest, CsrfTest, and expanded LoginTest coverage). Cashier installed early; no live Stripe surface yet.

- **Sprint 2 - Games library CRUD.** Added the user-scoped `games` table, manual CRUD API, IDOR-safe 404 behavior, wildcard-safe search, and a protected React `/library` page with filters, sorting, pagination, create/edit modal flow, and guarded delete. Steam OpenID/import remains the next Phase 2 sub-phase.
- **Sprint 2b - Steam OpenID + Web API integration.** Added Steam account linking via OpenID and direct SteamID64 fallback, transactional `/api/steam/sync`, nightly `steam:sync-all`, Redis-backed Steam response caching, and a dashboard Steam panel with connect/sync actions plus targeted privacy-settings guidance for the "Profile" and "Game Details" toggles.
- **Sprint 3 - Session tracking.** Added the play-session lifecycle (start/end/active/history), race-safe one-active-per-user invariant, manual-only playtime aggregation, persistent React active-session banner, and `/history` page grouped by game.
- **Sprint 4a - Hardware tier database.** Hand-curated `gpus.json` (~60 rows) and `cpus.json` (~40 rows) seeded via idempotent Laravel seeders. Tier is derived by pure PHP classifiers using absolute benchmark thresholds (GPU G3D Mark and CPU single-thread PassMark), making the classifier the single source of truth for boundary rules. Two auth-gated typeahead endpoints (`GET /api/hardware/gpus`, `GET /api/hardware/cpus`) power a reusable React `HardwareAutocomplete` component and a `/hardware` demo page that also surfaces browser-detected CPU cores, device memory, and WebGPU adapter presence with an explicit note that browsers cannot identify the GPU model.
- **Sprint 4b - PCGamingWiki metadata pipeline.** Added the `game_metadata` table, `PcGamingWikiClient`, Redis token-bucket limiter, AppID-only 7-day metadata cache, scheduled `games:enrich-metadata` command, and library-row metadata status badge. Pending Steam imports are enriched through PCGamingWiki Cargo and flipped to `ok` or `missing` for Phase 5's recommender masking.

## Screenshots

_Added in Phase 6 after live deployment._

## Demo video

_Added in Phase 6 after live deployment._

## Evaluator quick-start

_Added in Phase 6 with the seeded demo account credentials._
