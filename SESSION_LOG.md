# Session Log

Most recent first.

## [2026-07-01] Cortex Lite — Phase 1 shipped

Sanctum SPA auth stack: register, login (throttled with Retry-After), logout, /me, email verification (signed URL forwarded through the SPA), password reset (enumeration-safe), and delete-account with Cashier subscription teardown via App\Actions\Auth\DeleteAccountAction. React client wired with Vite same-origin proxy, Axios CSRF flow, Tailwind v4. Custom `verified` middleware returns 409 (not 403) for JSON so the frontend can distinguish "unverified" from "forbidden". Phase-close pass folded in ledger findings from Tasks 5, 6, 8, 13: `EnsureEmailIsVerified` 409 override + `Auth::forgetUser()` logout quirk documented in DECISIONS/TROUBLESHOOTING, unused imports trimmed (`EmailVerificationRequest`, `Button` in Dashboard/Account), and a clarifying comment added to `CsrfTest.php`.

34 feature + 6 unit tests, all green — grew from the planned 23+6 as scope expanded (CashierInstallTest, CsrfTest baseline, expanded LoginTest). Cashier pulled forward from Phase 5 to make the delete endpoint fully functional (no live Stripe surface).

→ branch `Phase-1` off `main`

---

## [2026-07-01] Cortex Lite — Phase 0 shipped

Scaffolded Laravel 13 + React 19 into a 6-service Docker Compose stack (app/nginx/mysql/redis/scheduler/queue), wrote multi-stage prod Dockerfile, `.env.example` covering all 7 phases, Makefile, GitHub Actions CI (PHPUnit + SQLite in-memory), and moved docs under `docs/`. Verified all services healthy, migrations run, Redis reachable, PHPUnit passes. Stack drifted from spec: Laravel 13 (not 11), PHP 8.4 (not 8.3), React 19 (not 18), FPM runs as root in dev container — all documented in `docs/DECISIONS.md`.

→ commit `220379e` on branch `Phase-0`

---
