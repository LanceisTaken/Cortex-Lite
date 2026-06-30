# Cortex Lite — Build Plan

**Goal:** A single portfolio project covering every unmet Razer JD requirement.
**Stack:** Laravel · MySQL · Redis · React · Stripe · Docker · AWS · WebSockets
**Total estimated effort:** 5 phases, each a focused chunk of work.

---

## Guiding principles

- **Build for depth, not feature count.** A reviewer will ask "explain how X works." Every feature in this plan is one you must be able to defend in an interview.
- **Code literacy first.** Per your own standard: read, understand, and explain every line. If a phase generates code you can't walk through, stop and study before moving on.
- **Commit like you're in a sprint.** Feature branches, PR-style merges to `main`, sprint-tagged commits (`[Sprint 1] add user auth`). This *is* your Agile evidence.
- **Document as you go.** A `README.md`, a `SPRINTS.md` (changelog by sprint), an `ARCHITECTURE.md` (diagram + decisions), and a `TROUBLESHOOTING.md` (sockets, deployment issues). These four files are interview ammunition.

---

## Phase 0 — Setup & scaffolding

**Why:** Get the local dev environment fully working before writing any feature code. If Docker Compose doesn't come up cleanly here, nothing later works.

- [ ] Initialise Git repo, push to GitHub (public). Add a placeholder `README.md`.
- [ ] Scaffold Laravel project (`composer create-project laravel/laravel cortex-lite`).
- [ ] Scaffold React frontend in a `client/` subdirectory (`npm create vite@latest client -- --template react`).
- [ ] Write `docker-compose.yml` with four services: `app` (PHP-FPM + Laravel), `nginx`, `mysql` (8.x), `redis` (7.x). Verify `docker-compose up` brings all four healthy.
- [ ] Configure Laravel `.env` to point at the Docker MySQL and Redis services.
- [ ] Run Laravel migrations against the Dockerized MySQL — confirm `users` table exists.
- [ ] Commit: `[Sprint 0] scaffold project, dockerize dev environment`.

**Deliverable:** `docker-compose up` brings the whole stack live locally. You can hit a Laravel "hello world" route through nginx and the React dev server renders.

---

## Phase 1 — Auth & user management (Sprint 1)

**Why:** Every later feature needs an authenticated user. This is also where you first integrate React with Laravel as an API client.

- [ ] Install Laravel Sanctum for SPA token authentication.
- [ ] Build register/login/logout API endpoints (`POST /api/register`, `POST /api/login`, `POST /api/logout`).
- [ ] Build React login + register pages, store auth token, attach to subsequent requests via Axios interceptor.
- [ ] Build a protected `/api/me` endpoint and a corresponding React `Dashboard` page that fetches and displays the logged-in user.
- [ ] Write at least 3 PHPUnit feature tests: register success, login success, login failure with bad credentials.
- [ ] Commit progressively on a `feature/auth` branch; merge to `main` with a clear merge commit.

**Deliverable:** Real auth working end-to-end through Docker. You can register, log in, see your own profile page.

**Resume bullet you're earning:** *"Implemented token-based authentication with Laravel Sanctum, including PHPUnit feature tests covering registration, login, and authorization middleware."*

---

## Phase 2 — Game library CRUD + MySQL design (Sprint 2)

**Why:** This is your "real backend" feature. Demonstrates database schema design, REST API patterns, validation, and authorization.

- [ ] Design the schema: `games` table (`id`, `user_id`, `title`, `platform`, `genre`, `hours_played`, `last_played_at`, timestamps). Foreign key on `user_id` with cascade delete.
- [ ] Create Laravel migration, Eloquent model with relationships (`User hasMany Games`).
- [ ] Build 5 RESTful endpoints under `/api/games`: index, store, show, update, destroy. All scoped to the authenticated user — a user can never read or mutate another user's games.
- [ ] Add Laravel Form Request classes for validation (title required, platform enum, etc.).
- [ ] Write PHPUnit tests for the authorization boundary: User A cannot read or delete User B's games (this is the kind of test reviewers look for).
- [ ] Build React UI: game list page, add-game form, edit, delete with confirmation.
- [ ] Commit on `feature/game-library`.

**Deliverable:** Full CRUD on your own game library, with enforced ownership and validation.

**Resume bullet:** *"Designed and implemented a 5-endpoint REST API for game library management with Eloquent ORM, Form Request validation, and per-user authorization enforced at the route layer."*

---

## Phase 3 — Live performance dashboard with WebSockets + Redis (Sprint 3)

**Why:** This is the JD's "network applications using sockets" + "troubleshooting socket-related issues" requirement. It's also where Redis earns its place.

- [ ] Install Laravel Reverb (Laravel's first-party WebSocket server, replaces Pusher in modern Laravel).
- [ ] Build a backend job that emits mock performance data (CPU %, RAM %, FPS) every 1 second on a channel scoped to the authenticated user (`performance.{userId}`).
- [ ] Use Redis to store a rolling 60-second history of these metrics. Reads from the dashboard's "history" view hit Redis, not MySQL. This is what justifies Redis being in your stack.
- [ ] Build React dashboard: subscribe to the WebSocket channel via Laravel Echo, render live charts (Recharts or Chart.js).
- [ ] **Deliberate troubleshooting work:**
  - Implement client-side reconnect logic with exponential backoff.
  - Handle a "stale connection" case: if no message received in 5s, mark connection degraded in the UI.
  - Test by killing the Reverb container mid-session and confirming the client recovers cleanly.
- [ ] Write a `TROUBLESHOOTING.md` documenting: how WebSocket reconnection works, what the failure modes are (server down, network drop, auth token expired mid-session), and how each is handled. **This file is interview gold for the JD's "troubleshoot socket issues" line.**
- [ ] Commit on `feature/realtime-dashboard`.

**Deliverable:** A live dashboard showing real-time metrics, surviving connection drops gracefully, with a documented troubleshooting writeup.

**Resume bullet:** *"Built a real-time performance dashboard using Laravel Reverb WebSockets and Redis for sub-second metric history caching, including documented reconnect logic with exponential backoff and graceful degradation on connection loss."*

---

## Phase 4 — Stripe payment integration (Sprint 4)

**Why:** Covers "payment gateway integration" directly. Don't overbuild — one product, one webhook, one premium flag on the user.

- [ ] Add a `is_premium` boolean and `stripe_customer_id` column to `users` via migration.
- [ ] Install Laravel Cashier (Laravel's Stripe wrapper).
- [ ] Configure Stripe test-mode keys via `.env`. Never commit real keys.
- [ ] Build a `/api/checkout` endpoint that creates a Stripe Checkout Session for a $5/month "Cortex Premium" subscription.
- [ ] Build a Stripe webhook handler (`/api/stripe/webhook`) that flips `is_premium` to true on successful subscription and false on cancellation. Verify webhook signatures.
- [ ] Gate one dashboard feature behind `is_premium` — e.g., "30-day historical metrics" instead of just the 60-second live window.
- [ ] Test the full flow with Stripe's test card (`4242 4242 4242 4242`) and the Stripe CLI to forward webhooks locally.
- [ ] Commit on `feature/stripe-payments`.

**Deliverable:** A working test-mode subscription flow. You can demo: click upgrade → Stripe checkout → return to app → premium feature unlocked.

**Resume bullet:** *"Integrated Stripe Checkout and webhook-driven subscription state management via Laravel Cashier, including signature verification and an entitlement-gated premium feature tier."*

---

## Phase 5 — AWS deployment (Sprint 5)

**Why:** The final, most visible piece. You said "showcases I know how to use AWS systems" — so the goal is deploy it for real once, take screenshots, then tear down.

Recommended path (cheapest, most realistic):

- [ ] Create AWS Free Tier account if you don't have one.
- [ ] Provision **RDS MySQL** (db.t4g.micro — free-tier eligible). Get the connection string.
- [ ] Provision **ElastiCache Redis** *or* run Redis on the same EC2 instance to save cost. For portfolio purposes, ElastiCache is the more impressive evidence — note this tradeoff in your `ARCHITECTURE.md`.
- [ ] Provision an **EC2 instance** (t2.micro / t3.micro). SSH in, install Docker + Docker Compose.
- [ ] Push your Docker images to **ECR** (Elastic Container Registry).
- [ ] On EC2, pull the images and run via `docker-compose up -d` with production env vars pointing at RDS and ElastiCache.
- [ ] Configure a **security group** allowing inbound 80/443 from anywhere, MySQL/Redis only from the EC2 instance's SG.
- [ ] (Optional, strongly recommended) Put **CloudFront + Route 53 + ACM** in front for HTTPS on a real domain. If you don't own a domain, skip this and use the raw EC2 public IP.
- [ ] Take a full set of screenshots: RDS dashboard, EC2 instance, ECR repos, CloudWatch logs showing your app's structured log output, security groups, the live app running.
- [ ] Write `DEPLOYMENT.md` documenting every step, with the architecture diagram showing: Browser → CloudFront → EC2 (nginx + app + Reverb) → RDS / ElastiCache.
- [ ] **Tear down** all paid resources once you have your screenshots and recorded a demo video. Keep the IaC / CLI scripts in the repo.
- [ ] Commit on `feature/aws-deploy`.

**Deliverable:** A documented, screenshotted, demoed AWS deployment. Repo contains all configuration. Live deployment can be re-spun if needed for an interview.

**Resume bullet:** *"Deployed the application to AWS using EC2 (Docker), RDS MySQL, ElastiCache Redis, and ECR, with security-group network isolation and CloudWatch log aggregation — including documented infrastructure architecture and teardown procedures."*

---

## Final deliverables checklist

When all 5 phases are done, your repo should contain:

- [ ] Working `docker-compose.yml` for local dev
- [ ] `README.md` — what it is, how to run locally, screenshots of the live deployment
- [ ] `ARCHITECTURE.md` — diagram + technology decisions + tradeoffs
- [ ] `SPRINTS.md` — sprint-by-sprint changelog (your Agile evidence)
- [ ] `TROUBLESHOOTING.md` — sockets + deployment issues encountered and how you solved them
- [ ] `DEPLOYMENT.md` — AWS deployment steps with screenshots
- [ ] PHPUnit test suite — at minimum auth tests + authorization boundary tests
- [ ] Demo video (2–3 min, unlisted YouTube) walking through the live app
- [ ] Clean Git history with feature branches and meaningful commit messages

---

## What this gives you on the resume

One project block. Three to four bullets. Every required and preferred JD skill demonstrably present.

| JD requirement | Phase that covers it |
|---|---|
| PHP/Laravel | All phases |
| Python *or* C# *or* VB.NET (2nd language) | Already covered by your FYP / Foxy Tales |
| React frontend | Phase 1 onward |
| MySQL | Phase 2 |
| Redis | Phase 3 |
| Git | All phases |
| Agile/Scrum signal | Sprint-tagged commits + `SPRINTS.md` |
| AWS | Phase 5 |
| Docker | Phase 0 + Phase 5 |
| Sockets | Phase 3 |
| Socket troubleshooting | Phase 3 + `TROUBLESHOOTING.md` |
| Payment gateway | Phase 4 |
| Unit testing | Phase 1 + Phase 2 (PHPUnit) |
| Technical documentation | All `*.md` files |

---

## Risk flags to watch

- **Don't let Phase 5 (AWS) be the first time you think about production config.** Set production-style env vars and secrets management in Phase 0 so you're not refactoring at the end.
- **Stripe webhooks are the most common silent failure point.** Test with the Stripe CLI locally before deploying.
- **Laravel Reverb is relatively new.** If you hit issues, Pusher (paid, but has a free tier) is the well-trodden fallback.
- **AWS Free Tier has time limits.** Don't start Phase 5 unless you can finish it within a week or two — RDS at full price gets expensive fast.
