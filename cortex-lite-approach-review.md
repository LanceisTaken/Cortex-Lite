# Project Approach Review: Cortex Lite

## TL;DR

The plan is fundamentally sound — phase ordering, testing strategy, and feature scope are all correct for a portfolio project. Three concrete gaps need fixing before you start: Reverb is a **fifth Docker service** (not a Phase 3 surprise), the "30-day history" premium gate implies a new MySQL write path that isn't planned, and the React dev server lives outside Docker in a way that should be explicitly documented. Fix those three and the plan is ready to execute.

---

## Project Summary

A portfolio SaaS targeting a Razer software engineering JD. Solo developer, no production users, no scale requirement. Goal is interview evidence across: Laravel, MySQL, Redis, React, WebSockets, Stripe, Docker, AWS. The repo currently has one commit (the plan file). No feature code exists yet. This review is pre-build advice from the plan document.

---

## Evidence Reviewed

- **Files inspected:** `cortex-lite-build-plan.md` (full contents)
- **External references:** 3 searches + 2 page fetches (AWS ElastiCache pricing, AWS RDS pricing, Laravel Reverb docs, Laravel Reverb ecosystem search)
- **Evidence status:** Plan document + verified external sources
- **Inspection scope:** Full plan reviewed; AWS pricing and Reverb architecture verified against primary sources

---

## Decision Methodology

**Constraints:** Solo developer, portfolio not production, interview deadline, AWS Free Tier budget sensitivity, must demonstrate specific JD skills. Not a scalability project — depth of implementation matters more than throughput.

**Decision criteria (ranked for this project):** Interviewability > build speed > correctness > operational simplicity > cost.

**Comparable influence:** Comparable portfolio projects in the Laravel ecosystem follow the same phase ordering used here. The Reverb Docker architecture finding came from the official docs, not comparables. No comparable changed the stack recommendation — the chosen stack is well-matched to the JD.

---

## What Is Working

- **Phase ordering is correct.** Auth before features, features before infra. This is exactly how real sprints work and gives you a coherent git history.
- **Authorization boundary tests in Phase 2 are the right call.** "User A cannot read User B's games" is exactly the test a mid-level reviewer looks for. Most junior portfolios skip this entirely.
- **Stripe scope is disciplined.** One product, one webhook, one flag. Overbuild here and Phase 4 becomes a distraction.
- **TROUBLESHOOTING.md as a deliberate artifact is smart.** Writing down failure modes and reconnect logic before an interview is more valuable than having a clean codebase.
- **The JD coverage matrix is complete.** Every line item maps to a specific deliverable. No phantom skill claims.

---

## Comparable Projects and References

1. **Laravel Reverb (official docs, laravel.com/docs/12.x/reverb)** — Active, maintained by the Laravel core team, production-ready as of 2026. What transfers: Reverb is a long-running separate process (`php artisan reverb:start`), not part of PHP-FPM. What should not be copied: the Supervisor/production tuning (open file limits, uv event loop) is irrelevant for a local Docker dev environment.

2. **AWS Free Tier — ElastiCache (aws.amazon.com/elasticache/pricing, verified June 2026)** — For accounts created **before July 15 2025**: 750 hours/month of `cache.t3.micro` free for 12 months. For accounts created **after July 15 2025**: $100 in credits instead of instance-hour grants. What transfers: ElastiCache IS free-tier eligible. The plan is correct that ElastiCache is an option. What does not transfer: `cache.t3.micro` only has ~0.5 GB memory — sufficient for mock metric data but worth knowing.

3. **AWS RDS MySQL db.t4g.micro (aws.amazon.com/rds/free, verified June 2026)** — Free tier eligible: 750 hours/month, 20 GB storage, for 12 months. The plan is correct. After 12 months: ~$11.68/month.

---

## Gap Analysis

### Gap 1 — Reverb is a fifth Docker service (hidden scope in Phase 0)

**Evidence:** The official Laravel Reverb docs explicitly state: *"Reverb is a long-running process"* and must be started with `php artisan reverb:start` separately from PHP-FPM. It listens on its own port (default 8080) and must be proxied through nginx.

**Impact:** Phase 0 plans four services. Phase 3 adds Reverb. But Reverb's Docker service definition, nginx WebSocket proxy config, and `.env` variables (`REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`) all need to exist before Phase 3 can start. Discovering this mid-Phase 3 means editing Docker Compose and nginx config while also trying to write WebSocket code — a debugging nightmare.

**The practical Docker pattern:** create a `reverb` service that uses the **same image** as `app` but with entrypoint `php artisan reverb:start`. Both containers mount the same codebase volume. This keeps your image count down.

**Also missing:** A `queue` service running `php artisan queue:work` will be needed in Phase 3 to process broadcast jobs. That is a sixth service. Add both in Phase 0 and stub them — even if they do nothing yet, they will be wired up and ready.

### Gap 2 — "30-day historical metrics" premium gate requires a new write path

**Evidence:** Phase 3 stores a rolling 60-second Redis buffer. Redis is not durable storage; this data is gone on container restart. Phase 4 gates "30-day historical metrics" behind `is_premium`, but no part of the plan writes metrics to MySQL.

**Impact:** Either the premium gate is hollow (shows nothing meaningful), or you have to design and build a MySQL metrics write path mid-Phase 4 when you are already dealing with Stripe webhooks.

**Recommendation:** Change the premium gate to something that requires no new storage. Good options:
- "Export last 60 seconds as CSV" (premium only) — trivial to build, demonstrates feature gating clearly
- "Real-time metrics on 3 channels simultaneously" vs "1 channel for free users" — no new storage needed

If you want real historical storage, add a `metrics` table and a queue job that persists each datapoint in Phase 3, then the Phase 4 gate is just a query filter. But plan it explicitly, not as a mid-Phase 4 discovery.

### Gap 3 — React dev server split from Docker is undocumented

**Impact:** `docker-compose up` brings up the backend stack, but the React frontend runs locally with `npm run dev` on the host. An interviewer looking at `docker-compose.yml` will ask "where's the frontend?" and if you haven't prepared the answer, it looks like an oversight.

**Recommendation:** Add a comment in `docker-compose.yml` and a note in `README.md`: "The React client runs on the host via `npm run dev` (Vite hot reload). In production, the build output is served as static files by the `nginx` container." This is also a real architectural decision worth defending: keeping the dev server on the host avoids Docker volume mount performance issues on Windows/Mac.

### Gap 4 — The Reverb "relatively new" risk flag is outdated

**Evidence:** Reverb is documented under Laravel 12.x and 13.x (both current as of June 2026), with production guides, Pulse integration, and horizontal scaling support from the Laravel core team. It is not experimental.

**Recommendation:** Replace this risk flag with the real Reverb risk: **nginx WebSocket proxy configuration**. The WebSocket `Upgrade` headers must be explicitly forwarded in nginx config:

```nginx
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "Upgrade";
```

This is the most common Phase 3 failure point for developers new to WebSockets. Add it to `TROUBLESHOOTING.md` pre-emptively.

### Gap 5 — No `.env.example` in Phase 0

**Impact:** When you add Reverb credentials, Stripe keys, and MySQL passwords across 5 phases, `.env.example` becomes the running record of every required environment variable. It also makes Phase 5 AWS deployment cleaner — you fill in the example file with real values rather than hunting through code for what env vars exist.

---

## Recommended Changes

### High Priority

1. **Add Reverb and queue as Docker services in Phase 0, not Phase 3.** Define both in `docker-compose.yml` now. Stub their env vars in `.env` and `.env.example`. Wire up nginx WebSocket proxy config even though there are no channels yet. This prevents a painful mid-Phase 3 infrastructure rework.

2. **Replace "30-day historical metrics" with a premium gate that requires no new write path.** "Export to CSV" is the cleanest option — testable, demonstrable, and requires zero additional storage. If you want real persistence, explicitly plan the `metrics` table and queue job as part of Phase 3, not Phase 4.

3. **Document the React/Docker split explicitly in Phase 0.** One sentence in `README.md` and one comment in `docker-compose.yml`. Prepare the interview answer: "Vite runs on the host for hot reload performance; in production, the build output is served by nginx."

### Medium Priority

4. **Add `.env.example` to the Phase 0 checklist.** Populate it as each phase adds new env vars. By Phase 5 it becomes your deployment configuration reference.

5. **Update the Reverb risk flag.** Replace "Reverb is relatively new" with "nginx WebSocket proxy config (`Upgrade` headers) is the most likely Phase 3 failure point." Add the nginx config block to `TROUBLESHOOTING.md` pre-emptively.

6. **Check your AWS account creation date against July 15 2025.** Accounts created after that date get $100 in credits instead of 750 hours/month of ElastiCache. Credits burn faster than expected under load testing. Plan Phase 5 accordingly.

### Low Priority

7. **Add a `Makefile` with `make up`, `make migrate`, `make test`.** Three commands, five minutes of work. Every time an interviewer asks "how do I run this?" you say "run `make up`."

8. **Name the premium gate feature concretely before Phase 4.** "30-day historical metrics" is a placeholder. Decide now what it actually shows, because the Stripe demo requires clicking through to a gated feature — if that feature is vague, the demo is flat.

---

## Stack and Architecture Verdict

**Keep everything.** Laravel + MySQL + Redis + React + Stripe + Docker + AWS + Reverb is exactly the right stack for this JD. No swaps recommended.

**One architectural note:** Reverb runs on port 8080 inside Docker. nginx must proxy `/app` and `/apps` paths to Reverb with WebSocket headers. This is a two-service orchestration the plan currently treats as a single-service problem. Fix in Phase 0.

---

## Cost and Vendor Reality

| Service | Free Tier | After Free Tier |
|---|---|---|
| RDS MySQL db.t4g.micro | 750 hrs/month, 12 months | ~$11.68/month |
| ElastiCache cache.t3.micro | 750 hrs/month, 12 months (pre-Jul 2025 accounts) or $100 credit (post-Jul 2025) | ~$12–15/month |
| EC2 t2.micro | 750 hrs/month, 12 months | ~$8.50/month |
| ECR | 500 MB/month free | $0.10/GB after |
| CloudFront + Route 53 | Minimal for low traffic | ~$1–3/month |

**Prototype cost:** $0 if within free tier and Phase 5 is completed within 12 months of account creation.
**Launch cost:** ~$35–40/month if kept running. Tear down as the plan recommends.
**Lock-in:** None. Docker Compose means the whole stack can run anywhere.

*Pricing verified from aws.amazon.com/elasticache/pricing and aws.amazon.com/rds/free, June 2026. Verify before committing to Phase 5.*

---

## Risks, Assumptions, and Unknowns

- **This review is from the plan document only.** No code has been written yet. File-level findings will be possible once Phase 0 is committed.
- **The free-tier window is 12 months from account creation**, not from when you start Phase 5. If your AWS account is old, some services may already be out of free tier.
- **Queue worker design for Phase 3 broadcasting is unspecified.** Laravel's broadcast system dispatches jobs to a queue by default. If the queue worker is not running, no WebSocket events fire. This is the most common "why isn't my dashboard updating?" debugging trap.
- **Sanctum token storage in the React client is unspecified.** `localStorage` is the easy choice; `httpOnly` cookies are more secure. For a portfolio project either is defensible, but prepare to explain the tradeoff in an interview.

---

## References

- [Laravel Reverb Docs (12.x)](https://laravel.com/docs/12.x/reverb)
- [Amazon ElastiCache Pricing](https://aws.amazon.com/elasticache/pricing/)
- [AWS RDS Free Tier](https://aws.amazon.com/rds/free/)
- [Redis Pricing Compared 2026 — Upstash Blog](https://upstash.com/blog/redis-pricing-comparison-every-major-provider-in-2026-with-numbers)
- [AWS RDS MySQL Pricing](https://aws.amazon.com/rds/mysql/pricing/)
