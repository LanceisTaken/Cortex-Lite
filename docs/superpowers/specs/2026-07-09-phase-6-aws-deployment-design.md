# Phase 6 — AWS Deployment + Native Agent Contract (Design)

**Date:** 2026-07-09
**Branch:** Phase-6
**Status:** Approved, ready for implementation planning

## Goal

Ship everything Phase 6 of `docs/cortex-lite-build-plan.md` requires: the
`NATIVE_AGENT_CONTRACT.md` portfolio artifact, production-ready Docker images and
compose topology, a Parameter-Store secrets mechanism, a self-resetting demo
account, a precise AWS Console deployment runbook, helper scripts, and the
phase-ending documentation updates.

## Scope boundary — build vs. execute

This plan produces **all codeable artifacts + a step-by-step console runbook**.
The actual AWS provisioning, live-flow smoke test, screenshots, demo video, and
teardown are **operator tasks the user executes** against their own AWS account
by following the runbook — they cannot be done by the agent (no console access,
no custody of credentials). The plan marks that boundary explicitly; the
"agent-buildable" work is complete and mergeable independent of any live deploy.

Decisions locked during brainstorming:
- **Provisioning:** manual AWS Console + runbook + helper scripts (not Terraform).
- **Secrets:** IAM instance role + in-container PHP entrypoint fetch from
  Parameter Store; no `.env` on disk in production.
- **Demo reset:** nightly **full reseed** (wipe + repopulate), not quota-only.
- **Demo library:** deterministic **static fixture**, not a live Steam pull.

## Components

### 1. `NATIVE_AGENT_CONTRACT.md` (repo root)
One-page artifact, no AWS dependency — build first. Sections:
- **Scope** — what the native agent observes vs. what the web layer owns.
- **Authentication** — signed JWT payloads over an mTLS transport; web layer
  never trusts an unsigned payload.
- **Payload schema** — hardware snapshot, running-game detection, session
  events (concrete JSON examples).
- **Update cadence** — snapshot vs. event push intervals.
- **Privacy** — opt-in, no PII to the LLM, local-first caching.
- **Security boundaries** — agent never executes web-layer code; web layer
  validates signatures before trusting any field.

### 2. Production Docker image hardening
Current `docker/app/Dockerfile` is dev-only (bind-mount, root FPM). Add a
production build path that:
- `composer install --no-dev --optimize-autoloader`.
- **Bakes application code into the image** (no bind mount available on EC2).
- Reverts FPM to a **non-root** user.
- Runs `php artisan config:cache` + `route:cache` at build (or first boot).
- Ships `entrypoint.prod.sh`.
- Stays under 500 MB (alpine base). The nginx multi-stage image already bakes
  the built SPA and is reused as-is.

Open implementation choice for the plan: a separate `Dockerfile.prod` vs. a
multi-stage target in the existing Dockerfile. Either is acceptable; keep dev
behaviour untouched.

### 3. Secrets — IAM role + PHP entrypoint fetch
- Add `aws/aws-sdk-php` as a composer dependency (keeps the image lean vs.
  baking the full `aws-cli` binary).
- New artisan command `ssm:export`: reads Parameter Store params by path
  `/cortex-lite/*` with decryption, using credentials **auto-discovered from the
  EC2 instance role via IMDSv2**. Emits shell `export KEY='value'` lines to
  stdout. Values are single-quote-escaped safely.
- `docker/app/entrypoint.prod.sh`: `eval "$(php artisan ssm:export)"` then
  `exec "$@"`. Secrets exist only in process memory — never written to disk.
- Used by the `app`, `scheduler`, and `queue` services.

### 4. `docker-compose.prod.yml`
Production topology, overriding dev:
- Services: `app`, `nginx` (multi-stage image), `redis` (in-container),
  `scheduler`, `queue`.
- **No `mysql` service** — production uses RDS.
- No bind mounts; ECR image references.
- `restart: unless-stopped`.
- Prod entrypoint on the three PHP services.

### 5. Parameter Store layout
`SecureString` parameters under `/cortex-lite/`:
`APP_KEY`, `APP_URL`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
`STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_PREMIUM`,
`STEAM_API_KEY`, `GEMINI_API_KEY`, `GEMINI_MODEL`, `SANCTUM_STATEFUL_DOMAINS`,
`SESSION_DOMAIN`, `CASHIER_CURRENCY`. The runbook enumerates each one the
operator creates in the console. `ssm:export` maps param leaf-name → env var.

### 6. Demo account — seeder + nightly full reseed
- `DemoAccountSeeder`: creates `demo@cortex-lite.example` with a documented
  password (surfaced in README evaluator quick-start).
- `database/data/demo_library.json`: static fixture (~15 games: titles,
  playtime, cover URLs, statuses) so reseed is deterministic and independent of
  Steam API availability.
- `ResetDemoAccount` console command: within a transaction, delete the demo
  user's games / sessions / usage_events / recommendations, reseed the library
  + a few sample sessions, set `is_premium = false`. Idempotent.
- Wired into the nightly scheduler (the existing `scheduler` service runs it).

### 7. Helper scripts
- `scripts/ecr-push.sh` — build prod images, ECR login, tag, push. Verifies
  `.dockerignore` is in effect (image size check).
- `scripts/ec2-bootstrap.sh` — on the EC2 host: install Docker + Compose, ECR
  login, pull, `docker compose -f docker-compose.prod.yml up -d`.

### 8. `docs/DEPLOYMENT.md` — console runbook
Numbered, copy-pasteable, in order:
1. Check AWS account creation date (credit-pool rules).
2. **Create the $20 Budgets alert first**, before any resource.
3. Security groups: 80/443 from anywhere, SSH from operator IP only, RDS 3306
   from the EC2 security group only. **No NAT Gateway.**
4. Provision RDS MySQL `db.t4g.micro` in the VPC.
5. Provision EC2 `t3.small` in a public subnet + IAM instance profile with
   read-only SSM access.
6. Create ECR repos; run `scripts/ecr-push.sh`.
7. SSH in; run `scripts/ec2-bootstrap.sh`.
8. Create the Parameter Store entries (§5).
9. `docker compose -f docker-compose.prod.yml up -d`; `docker stats` headroom
   check.
10. Run migrations + hardware/anchor seeders + demo seed on live RDS.
11. Create CloudFront distribution (default `*.cloudfront.net`, HTTPS-only) with
    the **`/api/stripe/webhook` cache-behavior carve-out** (TTL 0, all headers
    forwarded, body unmodified, POST allowed).
12. `stripe trigger checkout.session.completed` against the live URL.
13. Full-flow smoke test over HTTPS (register → Steam → session → recommend →
    reverse → upgrade → confirm `is_premium`).
14. Screenshot checklist (EC2, RDS, ECR, CloudWatch, security groups,
    CloudFront behaviors, Parameter Store masked, running app + results).
15. Teardown steps + `aws ce get-cost-and-usage` cost verification.

### 9. Documentation updates (phase-ending requirement)
- **ARCHITECTURE.md** — infra diagram, each AWS service + rationale, security
  model, cost breakdown, teardown procedure.
- **DECISIONS.md** — t3.small vs t2.micro; in-container Redis vs ElastiCache;
  IAM-role SSM fetch vs `.env`-on-disk; manual-console vs Terraform; CloudFront
  default domain; demo full-reseed vs quota-only.
- **TROUBLESHOOTING.md** — CloudFront webhook carve-out; IMDS/SSM fetch failure;
  container OOM (`docker stats`); teardown cost verification.
- **README.md** — evaluator quick-start (demo creds + `4242 4242 4242 4242`
  test card + CloudFront URL placeholder); Sprint 6 changelog line.

## Testing

Agent-buildable code is covered by PHPUnit:
- `ssm:export` command — mocked SSM client → asserts correct param-path query,
  decryption flag, and safely-escaped `export` output.
- `ResetDemoAccount` — asserts full wipe + reseed + `is_premium=false`,
  idempotency, and that it only touches the demo user (no cross-user impact).
- `DemoAccountSeeder` — creates the expected user + fixture library.

Runbook/console steps are not unit-testable; they are validated by the operator
during the live 48h window per the runbook's smoke-test step.

## Out of scope
- Terraform / IaC (explicitly chosen against).
- Any live AWS provisioning by the agent.
- Phase 7 WebSockets stretch goal.

## Operator prerequisites (surfaced in the runbook)
AWS account (+ creation-date check), real Stripe **test-mode** keys, Steam API
key, Gemini API key to paste into Parameter Store, and a ~48h deploy window.
None of these block building the artifacts above.
