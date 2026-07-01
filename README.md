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

## Screenshots

_Added in Phase 6 after live deployment._

## Demo video

_Added in Phase 6 after live deployment._

## Evaluator quick-start

_Added in Phase 6 with the seeded demo account credentials._
