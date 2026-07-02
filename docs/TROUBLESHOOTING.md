# Troubleshooting

Failure modes and their fixes. Add an entry every time a non-obvious error costs meaningful debugging time.

Format per entry:

```
### [Symptom]
**Cause:** ...
**Fix:** ...
```

---

### `tempnam()` warning / 500 error on every request in dev
**Cause:** PHP-FPM workers can't write to `storage/framework/views/`, `storage/framework/cache/`, or `bootstrap/cache/`. On Windows and macOS Docker Desktop, bind-mounted host files show as root-owned inside the container, so the default `www-data` FPM worker is denied. Blade tries to compile a view, tempnam() fails to find a writable target, Laravel throws a 500.
**Fix:** The dev `docker/app/Dockerfile` overrides the FPM pool user to `root` and starts FPM with `--allow-to-run-as-root`. If you see this error after rebuilding the image, confirm those two lines survived. If they did but the issue persists, `docker compose exec app sh -c "grep '^user' /usr/local/etc/php-fpm.d/www.conf"` should show `user = root`. Prod is unaffected — the ECR image bakes code in, so www-data owns everything.

### Sanctum SPA login succeeds but subsequent requests return 401 / CSRF token mismatch
**Cause:** The CSRF cookie is not being sent with the API request. Almost always one of three things: (1) the frontend Axios instance is missing `withCredentials: true`; (2) the CORS config on the backend does not include `Access-Control-Allow-Credentials: true` (Laravel's `config/cors.php` → `supports_credentials => true`); (3) `SANCTUM_STATEFUL_DOMAINS` does not include the exact host the frontend is being served from.
**Fix:** Verify all three at once. In the browser devtools, confirm the `XSRF-TOKEN` and `laravel_session` cookies exist and are being sent on the failing request (Application → Cookies, then Network → Request Headers). Confirm `withCredentials: true` on the Axios instance. Confirm `config/cors.php` has `supports_credentials => true` AND `paths` includes `sanctum/csrf-cookie` and your API paths. Confirm `SANCTUM_STATEFUL_DOMAINS` in `.env` lists the frontend origin (host:port, no scheme).

### Steam sync returns 422 with a "profile is private" error
**Cause:** Both of Steam's privacy toggles must be Public — most users only flip one. The user's "Profile" is Public but their "Game Details" section is still Private (or vice versa). `GetOwnedGames` requires "Game Details" to be Public specifically; `GetPlayerSummaries` reads the "Profile" visibility. If either is not Public, our pre-flight check on `communityvisibilitystate` and/or the games call itself fails.
**Fix:** Direct the user to Steam → Profile → Edit Profile → Privacy Settings, and set BOTH "My profile" AND "Game details" to Public. Save. Retry the sync. Player-summary visibility is cached for 60 seconds, so if the user fixed the toggles immediately before retrying they may need to wait about a minute before the next attempt. The UI must surface both toggles by name (not just "make your profile public") — this is the single highest-friction step in the Steam connection flow, so the error copy needs to be specific.

### Stripe webhook returns 400 "signature verification failed" in production but works locally
**Cause:** CloudFront's default cache behavior strips several headers (including `Stripe-Signature`) and can modify the request body in transit, both of which break `\Stripe\Webhook::constructEvent()`. The signature was calculated by Stripe against the raw body; any mutation invalidates it.
**Fix:** Add a dedicated cache behavior in CloudFront for the path pattern `/api/stripe/webhook`: caching disabled (TTL 0), forward all headers, forward all query strings, request body unmodified, allowed methods restricted to POST. Redeploy the distribution. Retest with `stripe trigger checkout.session.completed` against the live CloudFront URL. Also ensure the webhook route is CSRF-exempt in Laravel (`VerifyCsrfToken` middleware excludes `stripe/webhook`).

### Testing Stripe webhooks locally
**Cause:** Not an error — this is the setup for local webhook testing when the endpoint isn't yet publicly reachable.
**Fix:** Install the Stripe CLI. In one terminal: `stripe login` (once), then `stripe listen --forward-to localhost/api/stripe/webhook`. The CLI prints a signing secret starting with `whsec_...` — put that in `.env` as `STRIPE_WEBHOOK_SECRET` and restart the app container. In another terminal, fire events: `stripe trigger checkout.session.completed`, `stripe trigger customer.subscription.deleted`, etc. The `stripe listen` process must be running throughout — it's the tunnel.

### Container OOM during a live demo (app or queue killed)
**Cause:** PHP-FPM + nginx + Redis + scheduler + queue worker on a 1 GB instance (t2.micro) exceeds available RAM the first time a Steam sync job and an LLM call overlap. The OOM killer takes down the biggest process, usually the queue worker or PHP-FPM master.
**Fix:** Diagnose with `docker stats` — the killed container will have hit its memory limit. For prod, use t3.small (2 GB) not t2.micro; this is already the documented decision. As an emergency in-demo mitigation, stop the queue worker (`docker compose stop queue`) — Steam sync will fall back to a direct call within the request path but the demo survives. Long-term: t3.small is the answer.

### LLM (Claude) call times out or returns a rate-limit error mid-request
**Cause:** Anthropic API transient failure (timeout, 429 rate limit, 5xx). If we surface this as a 500 to the user, we've broken the core feature because of a dependency that has nothing to do with settings recommendations.
**Fix:** `ExplanationGenerator` MUST catch all upstream failures and return a static fallback string (e.g., "These settings target [goal] performance on your hardware. See the settings table above for details."). The rule-based recommendation itself is unaffected — the user still gets the settings JSON. Log the upstream failure to CloudWatch for observability, but never propagate it to the response status.

### Verify AWS teardown was actually complete (bill is $0/day again)
**Cause:** After a live-deployment window, it's easy to leave a residual resource running — an unattached EBS volume, a CloudFront distribution stuck in "Disabling", an RDS snapshot, an ECR repo with images consuming storage. These bleed credits.
**Fix:** After teardown, run `aws ce get-cost-and-usage --time-period Start=YYYY-MM-DD,End=YYYY-MM-DD --granularity DAILY --metrics UnblendedCost` for a 2-day window centered on the teardown date. Post-teardown days should show near-$0. If not, drill down by service: `--group-by Type=DIMENSION,Key=SERVICE`. Common culprits: NAT Gateway (should never have existed — see architecture rules), unassociated EIPs, CloudFront distributions in "Disabled but not deleted" state, RDS automated snapshots.

### `RuntimeException: Session store not set on request` when hitting a Sanctum-stateful API route in PHPUnit
**Cause:** Sanctum's `EnsureFrontendRequestsAreStateful` only rewrites the auth guard and starts the session when the request looks like it's "from the frontend" — matched via `Origin` or `Referer` header against the `sanctum.stateful` config list. Laravel's default test HTTP client (`$this->postJson`, `$this->get`, etc.) sends neither header, so the session middleware is skipped and any controller call to `$request->session()->regenerate()` (login, register, logout) throws.
**Fix:** Set a default `Origin` header on the test client in the base `tests/TestCase.php`:
```php
protected function setUp(): void
{
    parent::setUp();
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
}
```
This mirrors the SPA's real request pattern (browser sets `Origin` on cross-origin XHR; under our Vite same-origin proxy setup, it's `http://localhost:5173`). It does NOT weaken CSRF checks (CSRF is keyed off the `X-XSRF-TOKEN` header/cookie, independent of `Origin`).

### Sanctum SPA login returns 419 CSRF token mismatch
**Cause:** One of (a) client didn't `GET /sanctum/csrf-cookie` before the first state-changing request; (b) Axios missing `withCredentials: true` / `withXSRFToken: true`; (c) `SANCTUM_STATEFUL_DOMAINS` doesn't include the browser's origin.
**Fix:** Three-part checklist. In dev the browser origin is `localhost:5173` (Vite), so `SANCTUM_STATEFUL_DOMAINS=localhost:5173`. Axios client at `client/src/lib/api.js` sets both flags. The CSRF interceptor ensures the cookie call happens before any POST/PUT/PATCH/DELETE.

### Email verification link 403 in dev
**Cause:** The verification link that Laravel emails uses a temporary signed URL. The SPA `/verify-email/:id/:hash` page must POST back to the backend with the full `?signature=…&expires=…` query string intact. Any transformation (URL-encoding it twice, stripping trailing params, changing method) breaks the HMAC.
**Fix:** The SPA verification page forwards `window.location.search` verbatim to `POST /api/email/verify/{id}/{hash}{location.search}`. In tests, mint the URL with `URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)])` and POST to it directly.

### `assertGuest()` returns false after `Auth::guard('web')->logout()` in Sanctum-guarded tests
**Cause:** The `auth:sanctum` middleware calls `Auth::shouldUse('sanctum')` during authentication, and Sanctum's `RequestGuard` caches the resolved user for the lifetime of the request. Logging out only the `web` guard leaves that cached `sanctum` user pointer alive, so a subsequent `Auth::check()` (including the one inside `assertGuest()`) still reports authenticated.
**Fix:** Also call `Auth::forgetUser()` immediately after `Auth::guard('web')->logout()` to clear the cached pointer on the current default guard. Implemented in `LoginController::destroy` and `AccountController::destroy`.

### PHPUnit `withMiddleware(ValidateCsrfToken::class)` doesn't actually enable CSRF checks in tests
**Cause:** Laravel's `PreventRequestForgery::runningUnitTests()` short-circuits CSRF verification whenever `$app['env'] === 'testing'`, which is true for the entire PHPUnit run — `withMiddleware()`/`withoutMiddleware()` never come into play for this check.
**Fix:** For a specific test that must verify CSRF rejection, temporarily set `$this->app['env'] = 'production'` right before the assertion. This is safe because Laravel recreates `$this->app` per test method via `tearDownTheTestEnvironment()`, so the mutation cannot leak into other tests. See `tests/Feature/Auth/CsrfTest.php::test_missing_csrf_token_is_rejected_on_web_login_route`.

### Escaped `%` and `_` game title searches return zero rows or 500 in tests
**Cause:** SQL `LIKE` wildcard escaping is database-specific if the escape character is only implied. Backslash escaping produced different behavior between SQLite test runs and MySQL, and SQLite rejected an `ESCAPE '\\'` clause as a two-character escape expression.
**Fix:** Use an explicit portable escape character that does not need special SQL string handling. The games index search escapes `!`, `%`, and `_`, then queries with `title like ? escape '!'`.

### `POST /api/sessions/start` returns 409 `play_session_already_active`
**Cause:** The user already has a `play_sessions` row with `ended_at IS NULL`. The active-session invariant is application-enforced by a lock-then-check inside `StartPlaySessionAction`.
**Fix:** End the existing session via `POST /api/sessions/{id}/end` (or `GET /api/sessions/active` to find its id), then retry the start. If the UI banner is stale, call `refresh()` on `usePlaySession()` to re-fetch.

### After a Steam re-sync, session-tracked minutes appear to reset on a Steam game
**Cause:** By design. Steam's `playtime_forever` is authoritative for Steam-sourced games and overwrites `games.playtime_minutes` on every scheduled sync. The session record itself is untouched, so the history page still shows the session.
**Fix:** Not a bug. Per-game totals shown on the history page are computed from `sum(duration_seconds)` over ended sessions, not from `games.playtime_minutes`.
