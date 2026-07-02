# Cortex Lite

## What it is

A portfolio web app for PC gamers: Steam-connected game library, manual session tracking, AI-assisted graphics-settings optimizer (deterministic rule-based engine + Claude Haiku for prose explanations only), and Stripe freemium tier. Built to demonstrate the backend, cloud, and AI-integration skills required by the Razer Software Engineer JD.

**Stack:** Laravel 13 · PHP 8.4 · MySQL 8 · Redis 7 · React 19 (Vite) · Stripe · Docker · AWS · Anthropic Claude Haiku · Steam Web API · PCGamingWiki API

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

## Screenshots

_Added in Phase 6 after live deployment._

## Demo video

_Added in Phase 6 after live deployment._

## Evaluator quick-start

_Added in Phase 6 with the seeded demo account credentials._
