# Cortex Lite — Build Plan (v2)

**Goal:** A single portfolio project covering every unmet Razer JD requirement.
**Stack:** Laravel · MySQL · Redis · React · Stripe · Docker · AWS · Laravel Reverb (WebSockets)
**Time-boxed effort:** ~5–7 weeks part-time. Per-phase budgets below with defined fallbacks if a phase overruns.

> **Changelog from v1:** AWS cost model corrected for post-July-2025 accounts. Reverb + queue worker moved into Phase 0 (they're not a Phase 3 surprise — they need to exist from the start). Redis justification rewritten honestly. Premium gate changed from "30-day history" (which would have needed a new MySQL write path) to "CSV export" (no new storage). Time-boxes and fallback scope cuts added. CI added. HTTPS made non-optional.

---

## Guiding principles

- **Build for depth, not feature count.** A reviewer will ask "explain how X works." Every feature must be one you can defend in an interview.
- **Code literacy first.** Per your own standard: read, understand, and explain every line. If a phase generates code you can't walk through, stop and study before moving on.
- **Commit like you're in a sprint.** Feature branches, PR-style merges to `main`, sprint-tagged commits (`[Sprint 1] add user auth`). This *is* your Agile evidence.
- **Document as you go, but don't over-document.** Three files: `README.md` (incl. sprint changelog), `ARCHITECTURE.md` (incl. AWS deployment section), `TROUBLESHOOTING.md` (sockets + deployment issues). Three files is enough for a solo project — five was overkill.
- **Time-box ruthlessly.** Every phase has a budget. If you overrun, cut scope per the defined fallback — don't silently let the project stretch indefinitely.

---

## Phase 0 — Setup, Dockerization, and CI

**Time-box: 3–5 days. Fallback if overrun: drop the queue stub and add it in Phase 3 (slightly worse but recoverable).**

**Why:** Get the local dev environment fully working before writing any feature code. Critically, **scaffold every service you'll eventually need now**, even if some are stubs. Reverb and the queue worker are *not* Phase 3 surprises — they need to exist in `docker-compose.yml` from the start.

- [ ] Initialise Git repo, push to GitHub (public). Add a placeholder `README.md`.
- [ ] Scaffold Laravel project (`composer create-project laravel/laravel cortex-lite`).
- [ ] Scaffold React frontend in a `client/` subdirectory (`npm create vite@latest client -- --template react`).
- [ ] **Write `docker-compose.yml` with all six services from day one:**
  - `app` — PHP-FPM + Laravel
  - `nginx` — web server, with WebSocket `Upgrade` headers configured in the nginx config (see below)
  - `mysql` — version 8.x
  - `redis` — version 7.x
  - `reverb` — same image as `app`, entrypoint `php artisan reverb:start` on port 8080
  - `queue` — same image as `app`, entrypoint `php artisan queue:work`
- [ ] **Add the nginx WebSocket proxy config now**, not in Phase 3. The two header lines that cause 90% of WebSocket failures:
  ```nginx
  proxy_set_header Upgrade $http_upgrade;
  proxy_set_header Connection "Upgrade";
  ```
- [ ] Create `.env.example` with every variable you'll need across all 5 phases (Stripe keys blank, Reverb keys blank, DB creds, etc.). This becomes the running record of required configuration.
- [ ] Document the React/Docker split explicitly: add a comment in `docker-compose.yml` and a note in `README.md` — *"Vite dev server runs on the host (`npm run dev`) for hot reload performance. In production, the build output is served as static files by the `nginx` container."* Prepare to defend this in an interview.
- [ ] Configure Laravel `.env` to point at the Docker MySQL and Redis services.
- [ ] Run Laravel migrations against the Dockerized MySQL — confirm `users` table exists.
- [ ] **Add GitHub Actions CI:** a single workflow that runs `php artisan test` on every push. Green badge in README. This is a 30-minute job and gives you a CI/CD bullet the original plan missed entirely.
- [ ] **Add a `Makefile`** with `make up`, `make down`, `make migrate`, `make test`, `make logs`. Five minutes; saves you typing the same commands 200 times.
- [ ] Commit: `[Sprint 0] scaffold project, dockerize dev environment, add CI`.

**Deliverable:** `docker-compose up` brings all six services healthy. Reverb is listening on its port (even though no channels are defined yet). CI runs on every push.

---

## Phase 1 — Auth & user management (Sprint 1)

**Time-box: 3–4 days. Fallback if overrun: skip the React register page polish; functional ugly UI is fine.**

**Why:** Every later feature needs an authenticated user. This is where you first integrate React with Laravel as an API client.

- [ ] Install Laravel Sanctum for SPA token authentication.
- [ ] Build register/login/logout API endpoints (`POST /api/register`, `POST /api/login`, `POST /api/logout`).
- [ ] **Apply Laravel's built-in throttle middleware to `/api/login`** — `throttle:5,1` (5 attempts per minute). This is a one-line addition that closes a brute-force gap.
- [ ] Build React login + register pages, store auth token, attach to subsequent requests via Axios interceptor.
- [ ] **Decide and document Sanctum token storage in React.** `localStorage` is the easy choice; `httpOnly` cookies are more secure. Pick one, note the tradeoff in `ARCHITECTURE.md`. Either is defensible — what's not defensible is not having an answer.
- [ ] Build a protected `/api/me` endpoint and a React `Dashboard` page that fetches and displays the logged-in user.
- [ ] Write PHPUnit feature tests: register success, login success, login failure with bad credentials, **throttle middleware triggers after 5 failed attempts**.
- [ ] CI must pass on the PR. Merge to `main` with a clear merge commit.

**Deliverable:** Real auth working end-to-end through Docker. CI green. Login rate-limited.

**Resume bullet (earn before claiming):** *"Implemented token-based authentication with Laravel Sanctum, rate-limited login endpoints, and PHPUnit feature tests covering registration, login, authorization middleware, and brute-force throttling."*

---

## Phase 2 — Game library CRUD + MySQL design (Sprint 2)

**Time-box: 4–5 days. Fallback if overrun: cut the React edit form; ship create + delete + list only.**

**Why:** This is your "real backend" feature. Demonstrates database schema design, REST API patterns, validation, and authorization.

- [ ] Design the schema: `games` table (`id`, `user_id`, `title`, `platform`, `genre`, `hours_played`, `last_played_at`, timestamps). Foreign key on `user_id` with cascade delete.
- [ ] Create Laravel migration, Eloquent model with relationships (`User hasMany Games`).
- [ ] Build 5 RESTful endpoints under `/api/games`: index, store, show, update, destroy. All scoped to the authenticated user.
- [ ] **Paginate the index endpoint** with `paginate(20)`. Closes the "what happens at 10,000 games" interview gap with a 10-minute change.
- [ ] Add Laravel Form Request classes for validation (title required, platform enum, etc.).
- [ ] Write PHPUnit tests for the **authorization boundary**: User A cannot read, update, or delete User B's games. This is the test class reviewers actually look for.
- [ ] Build React UI: paginated game list, add-game form, edit, delete with confirmation.
- [ ] **Add a database seeder** that creates a demo user + 5 demo games. The AWS deployment in Phase 5 needs *something* to show.

**Deliverable:** Full CRUD on your own game library, paginated, with enforced ownership, validation, and seed data.

**Resume bullet (earn before claiming):** *"Designed and implemented a paginated 5-endpoint REST API for game library management with Eloquent ORM, Form Request validation, per-user authorization enforced at the route layer, and authorization-boundary feature tests."*

---

## Phase 3 — Live performance dashboard with WebSockets + Redis (Sprint 3)

**Time-box: 5–7 days. This is the riskiest phase. Fallback if overrun by >2 days: drop WebSockets and use polling (3-second interval). Document the tradeoff in `ARCHITECTURE.md` — "considered WebSockets via Laravel Reverb, fell back to polling due to time constraints; the channel auth boilerplate is the gating factor." This is a better interview answer than a half-broken WebSocket setup.**

**Why:** This is the JD's "network applications using sockets" + "troubleshooting socket-related issues" requirement.

### Prerequisites (do these *first* — they block everything else)

- [ ] **Configure Reverb broadcasting auth.** Three specific config touchpoints, all of which are common silent failure points:
  - `routes/channels.php` — authorization callback for the `performance.{userId}` private channel
  - `config/sanctum.php` — `stateful` domains array must include your dev URL
  - `config/cors.php` — must permit the broadcasting auth route with credentials
- [ ] Set `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` in `.env` and `.env.example`. Confirm the React client uses Laravel Echo with matching keys.
- [ ] **Verify the queue worker is running.** Laravel's broadcast system dispatches jobs to the queue by default. If the queue worker isn't running, no WebSocket events fire — and the silent-failure debugging trap is brutal. Test by dispatching a single broadcast event before building anything else.

### Then the feature work

- [ ] Build a backend job that emits mock performance data (CPU %, RAM %, FPS) every 1 second on a **private channel** `performance.{userId}`.
- [ ] **The real justification for Redis (state it openly in `ARCHITECTURE.md`):** PHP-FPM serves requests across multiple stateless worker processes. An in-memory PHP array can't survive across requests or workers. Redis gives you a shared, fast store for the rolling metric buffer — and survives worker restarts. *This* is the honest reason Redis is in the stack, not "to put Redis on my resume."
- [ ] Use a **Redis sorted set** keyed by `metrics:user:{userId}`, scored by Unix timestamp, with `ZADD` on write and `ZREMRANGEBYSCORE` to evict entries older than 60 seconds. Specify the exact data structure in `ARCHITECTURE.md` — "Redis is in the stack" without naming the data structure is a label, not a skill.
- [ ] Build React dashboard: subscribe to the WebSocket channel via Laravel Echo, render live charts (Recharts or Chart.js).

### Deliberate troubleshooting work (this is the interview ammunition)

- [ ] Implement client-side reconnect logic with exponential backoff.
- [ ] Handle stale connections: if no message received in 5s, mark connection degraded in the UI.
- [ ] **Test two distinct failure modes**, not just one:
  - Kill the Reverb container mid-session; confirm client recovers.
  - Force a Sanctum token expiry mid-session; confirm the broadcasting auth re-handshake or graceful logout works.
- [ ] Write `TROUBLESHOOTING.md` documenting: how WebSocket reconnection works, what the failure modes are (server down, network drop, auth token expired mid-session, queue worker stopped, missing nginx `Upgrade` headers, missing `channels.php` authorization callback), and how each is detected and handled. **This file is interview gold.**

**Deliverable:** A live dashboard surviving connection drops gracefully, with a documented troubleshooting writeup covering at least 5 distinct failure modes.

**Resume bullet (earn before claiming):** *"Built a real-time performance dashboard using Laravel Reverb WebSockets, private-channel authorization via Sanctum, and a Redis sorted-set sliding window for sub-second metric history shared across PHP-FPM workers — including documented reconnect logic, exponential backoff, and graceful handling of token expiry and worker disconnects."*

---

## Phase 4 — Stripe payment integration (Sprint 4)

**Time-box: 3–4 days. Fallback if overrun: skip the webhook test cases, ship with manual testing only. Don't skip the signature verification — that's the part interviewers actually ask about.**

**Why:** Covers "payment gateway integration" directly. One product, one webhook, one flag.

- [ ] Add `is_premium` boolean and `stripe_customer_id` column to `users` via migration.
- [ ] Install Laravel Cashier (Laravel's Stripe wrapper).
- [ ] Configure Stripe test-mode keys via `.env`. Never commit real keys.
- [ ] Build `/api/checkout` endpoint creating a Stripe Checkout Session for a $5/month "Cortex Premium" subscription.
- [ ] Build a Stripe webhook handler (`/api/stripe/webhook`) that flips `is_premium` to true on successful subscription and false on cancellation. **Verify webhook signatures** — this is the most-asked Stripe interview topic.
- [ ] **Premium gate (revised from v1):** Premium users unlock **"Export last 60 seconds of metrics as CSV."** No new storage required, the data is already in Redis, and it's a concrete demonstrable feature for the live demo. Free users see a locked button.
- [ ] Test the full flow with Stripe test card `4242 4242 4242 4242` and the Stripe CLI to forward webhooks locally.
- [ ] **Write two explicit webhook test cases** (the silent-failure trap the original plan flagged but didn't mitigate):
  - Webhook delivered with a deliberately wrong signature — must be rejected with 400.
  - Webhook for a `customer.subscription.deleted` event — must flip `is_premium` to false.

**Deliverable:** Working test-mode subscription flow. Demo path: click upgrade → Stripe checkout → return to app → CSV export button unlocked.

**Resume bullet (earn before claiming):** *"Integrated Stripe Checkout and webhook-driven subscription state management via Laravel Cashier, including signature verification, an entitlement-gated CSV export feature, and test cases for signature rejection and subscription cancellation."*

---

## Phase 5 — AWS deployment (Sprint 5)

**Time-box: 4–5 days. Hard live-deployment window: 48 hours, then tear down. Fallback if overrun: deploy backend only (no CloudFront), screenshot what you have, tear down. A deployed-but-imperfect AWS environment beats a perfect plan that never deployed.**

### ⚠️ Critical AWS cost reality (v1 was wrong about this)

If your AWS account was created **on or after July 15, 2025**, the 12-month free tier no longer exists. You instead get up to **$200 in credits, expiring after 6 months or whenever spent — whichever comes first**. Every service draws from this same pool. RDS + ElastiCache + EC2 + data transfer all spend the same credits, and a NAT Gateway alone is ~$33/month if you accidentally create one.

**Mandatory cost protections before provisioning anything:**

- [ ] **Check your AWS account creation date.** If pre-July 2025, you have the old per-service free hours. If post, you're on the $200 credit pool.
- [ ] **Set an AWS Budgets alert at $20 spend, hard threshold, email notification.** Do this before creating any resource.
- [ ] **Never create a NAT Gateway.** Put EC2 in a *public* subnet with a tight security group. "Private subnet with NAT" looks more enterprise but burns your credits before you blink.
- [ ] **48-hour live deployment rule.** Spin up, screenshot, demo, tear down within 48 hours. Set a calendar reminder.

### Architecture (cost-conscious version)

- [ ] **EC2 t2.micro or t3.micro** — runs your Docker Compose stack (app + nginx + reverb + queue).
- [ ] **RDS MySQL db.t4g.micro** — managed database.
- [ ] **Redis in the same docker-compose on EC2**, NOT ElastiCache. The v1 plan said the quiet part out loud — ElastiCache was there for screenshot value, not architectural necessity. Run Redis next to your app on EC2. State the tradeoff openly in `ARCHITECTURE.md`: *"For this workload (single-user mock metrics, ~1KB/sec write), in-container Redis on EC2 is the right call. ElastiCache would be the right call at multi-instance scale where Redis state must outlive any single EC2 instance — that's not the case here."* This is a better interview answer than the original plan's "ElastiCache for impressive screenshot."
- [ ] **ECR** for Docker image storage.
- [ ] **Parameter Store (AWS Systems Manager)** for secrets — Stripe keys, DB password, Reverb secret. Injected at container start. **Do not** scp a `.env` file to EC2.
- [ ] **CloudFront in front of EC2 — non-optional.** Use the free default `*.cloudfront.net` domain (no custom domain or Route 53 needed). This gives you HTTPS for free. Sanctum tokens and Stripe Checkout returns over plain HTTP is a bad demo signal for a JD that cares about payment security.
- [ ] **CloudWatch Logs** for structured application logs from the Cloud Run-style container output.

### Deployment steps

- [ ] Provision EC2 in a public subnet with a security group: inbound 80/443 from anywhere, SSH from your IP only.
- [ ] Provision RDS in the same VPC. Security group allows port 3306 *only* from the EC2 security group.
- [ ] Push Docker images to ECR.
- [ ] SSH into EC2, install Docker + Docker Compose, pull images.
- [ ] Configure environment via Parameter Store fetch on container start (a small shell script in your entrypoint).
- [ ] Run `docker-compose up -d`.
- [ ] Provision CloudFront distribution pointing at the EC2 public IP/DNS, with HTTPS-only viewer protocol.
- [ ] Test the full flow over HTTPS: register → login → add games → live dashboard → upgrade to premium via Stripe → CSV export.
- [ ] **Screenshot everything**: EC2 console, RDS dashboard, ECR repos, CloudWatch log stream with your structured JSON logs, security group rules, CloudFront distribution, Parameter Store entries (with values masked), the running app at its CloudFront URL.
- [ ] Record a 2–3 minute demo video walking through the live app.
- [ ] Document everything in `ARCHITECTURE.md` (Infrastructure section): architecture diagram, every AWS service used and why, security model, cost breakdown, teardown procedure.
- [ ] **Tear down all paid resources**: terminate EC2, delete RDS (final snapshot optional), delete CloudFront distribution, delete ECR images if you want to be thorough. Keep all IaC scripts / config in the repo. Cancel any active Stripe test-mode subscriptions (harmless if not, but tidy).
- [ ] Verify your AWS bill is back to $0/day post-teardown.

**Deliverable:** A documented, screenshotted, demoed AWS deployment. Repo contains all configuration. Live deployment can be re-spun for an interview demo.

**Resume bullet (earn before claiming):** *"Deployed the application to AWS using EC2 (Dockerized), RDS MySQL, ECR, CloudFront (HTTPS), and Parameter Store (secrets), with security-group network isolation, CloudWatch structured logging, and documented infrastructure architecture and teardown procedures."*

---

## Final deliverables checklist

- [ ] Working `docker-compose.yml` for local dev (6 services)
- [ ] Working CI pipeline (green badge in README)
- [ ] `README.md` — what it is, how to run locally (`make up`), sprint changelog at the bottom, screenshots of live deployment
- [ ] `ARCHITECTURE.md` — diagram, technology decisions with stated tradeoffs (Redis choice, ElastiCache-vs-local, CloudFront HTTPS, React/Docker split, Sanctum token storage), AWS infrastructure section, cost breakdown
- [ ] `TROUBLESHOOTING.md` — sockets failure modes + deployment issues encountered
- [ ] PHPUnit test suite — auth tests, throttle test, authorization boundary tests, Stripe webhook tests
- [ ] Demo video (2–3 min, unlisted YouTube)
- [ ] Clean Git history with feature branches and sprint-tagged commits
- [ ] All AWS resources torn down post-demo; IaC/config preserved in repo

---

## JD coverage matrix

| JD requirement | Phase that covers it |
|---|---|
| PHP/Laravel | All phases |
| Python *or* C# *or* VB.NET (2nd language) | Already covered by your FYP / Foxy Tales |
| React frontend | Phase 1 onward |
| MySQL | Phase 2 |
| Redis | Phase 3 (with honest architectural justification) |
| Git | All phases |
| **CI/CD** | Phase 0 (GitHub Actions) |
| Agile/Scrum signal | Sprint-tagged commits + README changelog section |
| AWS | Phase 5 |
| Docker | Phase 0 + Phase 5 |
| Sockets | Phase 3 |
| Socket troubleshooting | Phase 3 + `TROUBLESHOOTING.md` |
| Payment gateway | Phase 4 |
| Unit testing | Phase 1 + Phase 2 + Phase 4 (PHPUnit) |
| Technical documentation | Three focused `*.md` files |
| **Brute-force/auth security** | Phase 1 (throttle middleware) |
| **HTTPS/transport security** | Phase 5 (CloudFront mandatory) |
| **Secrets management** | Phase 5 (Parameter Store) |

---

## Top 5 risks and mitigations

| # | Risk | Mitigation |
|---|---|---|
| 1 | AWS billing surprise from $200 credit pool burning faster than expected | Set $20 Budgets alert before any provisioning. Avoid NAT Gateway. Tear down within 48 hours of going live. |
| 2 | Phase 3 (Reverb private channels + Sanctum + queue + CORS) stalls for days on undocumented config | Prerequisites checklist at top of Phase 3 must be completed and tested with a single broadcast event before feature work begins. Polling fallback defined if Reverb can't be made stable in 2 days. |
| 3 | Time-box overrun causing the project to miss interview deadlines | Per-phase time-boxes with defined scope-cut fallbacks. If a phase runs over, cut features per the fallback, don't extend the timeline. |
| 4 | Stripe webhook silent failure (signature, local forwarding, missed events) | Two explicit webhook test cases (Phase 4), plus Stripe CLI local forwarding validated before deploy. |
| 5 | HTTPS missing on live demo, undermining Stripe security story | CloudFront with default `*.cloudfront.net` domain is non-optional in Phase 5. No custom domain needed. |

---

## What the v1 plan got wrong (lessons for future plans)

The original v1 of this plan failed two adversarial reviews on roughly the same dimensions. Worth keeping in mind:

- **AWS pricing assumptions need to be re-verified, not remembered.** The free tier model changed in mid-2025 and any tutorial older than that is wrong about new accounts.
- **Architectural decisions framed as "for the resume" undermine the resume.** Redis "to put Redis on the stack list" is a worse interview answer than not having Redis. The honest justification (PHP-FPM worker statelessness) was available and stronger.
- **Hidden scope kills timelines.** Reverb being "added in Phase 3" was wrong — it's a long-running service that needs to exist in Phase 0's Docker Compose. Discovering this mid-Phase 3 is a debugging nightmare.
- **Premium gates implying new write paths must be designed in advance**, not discovered when you reach the feature.
- **Time-boxes with defined fallbacks > open-ended phases.** "A focused chunk of work" is not an estimate.
