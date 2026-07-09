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

### Production containers boot without secrets or `ssm:export` fails
**Cause:** The EC2 instance role is missing `ssm:GetParametersByPath` / `ssm:GetParameters`, KMS decrypt permission, or the containers cannot reach IMDSv2 at `169.254.169.254` to obtain role credentials.
**Fix:** Confirm the EC2 instance profile is attached, the policy allows `/cortex-lite/*`, and IMDSv2 is enabled/reachable. Then run `docker compose -f docker-compose.prod.yml logs app` to read the AWS SDK error. `SSM_SKIP=1` is only for local image smoke tests; never use it for production.

### `config('app.key')` blank in the running app even though `ssm:export` works
**Cause:** A *stale* container that was first brought up while the SSM fetch was still failing (IAM/params not yet in place), combined with a silent-failure bug in the boot entrypoint. `entrypoint.prod.sh` ran `eval "$(php artisan ssm:export)"` under `set -e`, but a failure *inside* a command substitution does not trip `set -e` — the substitution's exit status is discarded. So when the fetch failed, `eval` evaluated an empty string, no secrets were exported, and the very next line `php artisan config:cache` baked a **blank `APP_KEY`** (and blank `DB_*`, etc.) into `bootstrap/cache/config.php`. Because the services use `restart: unless-stopped` and nobody force-recreated them after the SSM path started working, the blank cached config kept serving. `docker restart` / `docker compose restart` re-runs the entrypoint but that only helps *after* the fetch itself works — restarting while it was still broken re-baked blank config, which is why "update APP_KEY + restart" appeared to do nothing.

Note: `docker compose exec app printenv` will **not** show the SSM secrets even on a healthy container — `exec` spawns a fresh process that does not inherit the entrypoint shell's runtime-exported env (and php-fpm's `clear_env` default). Diagnose using the **cached config** value, not the env: `config('app.key')`.
**Fix (diagnostic order — do these before changing anything):**
1. Prove the fetch works *now*, from inside the container (the decisive test), without leaking values:
   `docker compose -f docker-compose.prod.yml exec -T app sh -c 'php artisan ssm:export >/dev/null 2>/tmp/e; echo exit=$?'` — exit 0 = fetch OK.
   Count what it emits (names only): `php artisan ssm:export 2>/dev/null | grep -c '^export '` (expect 24), and list names with `sed -E 's/^export ([A-Z_]+)=.*/\1/'`. Never print the values.
   - Non-zero exit or an `AccessDenied` / `kms` / region error → real IAM/region problem (see entries above), not this one.
   - exit 0 with 24 export lines → the mechanism is healthy; the running container is just stale.
2. Confirm the running container is stale: `config('app.key')` reads EMPTY while `ssm:export` succeeds.
3. **Persist the compose interpolation vars first** so a recreate doesn't reintroduce a blank region (a present-but-empty `AWS_DEFAULT_REGION` shadows the config default): write `/home/ec2-user/.env` with `AWS_DEFAULT_REGION=...` and `ECR_REGISTRY=...`. Compose auto-loads a `.env` in the compose-file's directory for interpolation; this also silences the `"AWS_DEFAULT_REGION"/"ECR_REGISTRY" variable is not set` warnings.
4. Force-recreate the PHP services so the entrypoint re-runs the now-working fetch and re-caches config: `docker compose -f docker-compose.prod.yml up -d --force-recreate app scheduler queue`.
5. Verify per service (all three share the image/entrypoint): `config('app.key')` prints `base64:...`.

The entrypoint has since been hardened to fail-fast: it captures `ssm:export` output separately (so a failed fetch aborts boot) and asserts `${APP_KEY:?...}` before `config:cache`, so a future silent-empty fetch crash-loops loudly instead of quietly baking blank config. Takes effect on the next image rebuild/push.

### Login blocked over CloudFront: `Set-Cookie` has `domain=https://<host>` (scheme in cookie Domain)
**Cause:** `SESSION_DOMAIN` in Parameter Store was set to a full URL (`https://d19sj8kntr2jlc.cloudfront.net`). Laravel copies `config('session.domain')` verbatim into the cookie `Domain` attribute, which must be a bare host (`d19sj8kntr2jlc.cloudfront.net`) or unset — a value containing a scheme/slashes is an invalid Domain, so browsers reject the `XSRF-TOKEN` and `laravel-session` cookies. The SPA then has no XSRF token to echo and login POSTs fail with `419 CSRF token mismatch`, even though `/sanctum/csrf-cookie` returns 204.
**Fix:** Set `SESSION_DOMAIN` to the **bare** CloudFront host (no scheme, no trailing slash), matching `SANCTUM_STATEFUL_DOMAINS`, then restart app/scheduler/queue. Verify the cookie is valid without a browser: `curl -sS -D - -o /dev/null https://<host>/sanctum/csrf-cookie | grep -io 'domain=[^;]*'` — must print a bare host. Also set `SESSION_SECURE_COOKIE=true` for the HTTPS-only CloudFront origin.

### `DB FAIL: SQLSTATE[HY000] [2002] Operation timed out` connecting to RDS from the app container
**Cause:** Network-level block, not credentials. A `[2002] Operation timed out` (as opposed to "Connection refused" or "Access denied") means the SYN to RDS:3306 got no response — the `cortex-rds-sg` inbound rule doesn't actually allow 3306 from `cortex-ec2-sg`, the EC2 instance isn't in `cortex-ec2-sg`, the RDS instance is stopped, or the endpoint in Parameter Store is stale. (The container egresses via the host ENI through Docker SNAT, so the source is the EC2's SG — an SG-to-SG rule is the intended, IP-stable config.)
**Fix:** Test the exact path from inside the container reading the *cached* config host (not `getenv`, which is empty under `exec`): `docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute='$h=config("database.connections.mysql.host"); $s=@fsockopen($h,3306,$e,$es,6); echo $s?"OK":"FAIL $es";'`. If it times out, fix in the AWS Console: confirm the RDS instance is **Available** (start it if stopped), and that `cortex-rds-sg` has inbound TCP 3306 sourced from `cortex-ec2-sg` (the security group, not a CIDR). The EC2 instance role only has SSM+ECR permissions, so this can't be diagnosed with `aws` from the host — use the Console or a workstation with credentials. Do **not** widen the rule to `0.0.0.0/0`.

### Testing Stripe webhooks locally
**Cause:** Not an error — this is the setup for local webhook testing when the endpoint isn't yet publicly reachable.
**Fix:** Install the Stripe CLI. In one terminal: `stripe login` (once), then `stripe listen --forward-to localhost/api/stripe/webhook`. The CLI prints a signing secret starting with `whsec_...` — put that in `.env` as `STRIPE_WEBHOOK_SECRET` and restart the app container. In another terminal, fire events: `stripe trigger checkout.session.completed`, `stripe trigger customer.subscription.deleted`, etc. The `stripe listen` process must be running throughout — it's the tunnel.

Wrong-signature requests should return HTTP 400. With no webhook secret configured, local/testing direct-handler requests skip signature verification; production must never run that way, and the controller returns HTTP 400 if the secret is empty outside `local`/`testing`.

### Container OOM during a live demo (app or queue killed)
**Cause:** PHP-FPM + nginx + Redis + scheduler + queue worker on a 1 GB instance (t2.micro) exceeds available RAM the first time a Steam sync job and an LLM call overlap. The OOM killer takes down the biggest process, usually the queue worker or PHP-FPM master.
**Fix:** Diagnose with `docker stats` — the killed container will have hit its memory limit. For prod, use t3.small (2 GB) not t2.micro; this is already the documented decision. As an emergency in-demo mitigation, stop the queue worker (`docker compose stop queue`) — Steam sync will fall back to a direct call within the request path but the demo survives. Long-term: t3.small is the answer.

### LLM (Gemini) call times out or returns a rate-limit error mid-request
**Cause:** Gemini API transient failure (timeout, 429 rate limit, 5xx). If we surface this as a 500 to the user, we've broken the core feature because of a dependency that has nothing to do with settings recommendations.
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

### Games stay at `metadata_status = pending`
**Cause:** The PCGamingWiki enrichment scheduler is not running, Redis is unavailable, the app is repeatedly hitting the PCGamingWiki rate limiter, or `PCGAMINGWIKI_CONTACT_EMAIL` is missing and the client fails fast.
**Fix:** Check the scheduler logs with `make logs`, confirm Redis is healthy, and confirm `.env` sets `PCGAMINGWIKI_CONTACT_EMAIL`. Run a manual tick with `make artisan CMD="games:enrich-metadata"` after fixing the environment. Rate-limited rows intentionally remain pending for the next scheduled tick.

### Games show `metadata_status = missing` but should be retried
**Cause:** The previous enrichment attempt found no Cargo row, received malformed metadata, or hit a hard upstream failure that the portfolio-scope retry policy treats as durable.
**Fix:** In `make shell`, reset the affected rows to pending and let the scheduler retry: `App\Models\Game::where('metadata_status', 'missing')->update(['metadata_status' => 'pending']);`.

### Recommendation/reverse `explanation` is the terse static string, not AI prose
**Cause:** `ExplanationGenerator` failed open. Either `GEMINI_API_KEY` is unset, or the Gemini API timed out, returned a non-2xx, or returned no candidate text. All of these are caught and logged as `Gemini explanation failed; serving static fallback.`, and the deterministic static explanation is returned by design.
**Fix:** Confirm `GEMINI_API_KEY` is set and `GEMINI_MODEL` is valid. Check `storage/logs` for the `Gemini explanation failed` warning and its `message`. Verify egress to `generativelanguage.googleapis.com`. Once a call succeeds it is cached in Redis for 30 days under `llm:explain:*`; flush that prefix if you need to re-test after fixing a bad key.

### Gemini returns HTTP 429 (`RESOURCE_EXHAUSTED`) and every explanation falls back
**Cause:** The Gemini free tier caps `gemini-3.5-flash` at 20 `generateContent` requests per project per day (`GenerateRequestsPerDayPerProjectPerModel-FreeTier`). Once spent, every optimizer call logs `Gemini explanation failed; serving static fallback.` and serves the static string until the quota resets (midnight Pacific). The UI cannot distinguish this from any other Gemini failure because the endpoint fails open by design.
**Fix:** Probe Gemini directly from the app container, bypassing the fail-open path: `make artisan CMD="tinker --execute=\"echo app('App\Services\GeminiClient')->generate('Reply with the single word OK.');\""` — prose back means Gemini works; `GeminiApiException: Gemini returned HTTP 429` means quota. Read the raw quota detail by calling the endpoint with `Http::` in tinker and printing `->body()`. Mitigate by relying on the 30-day Redis prose cache (each unique recommendation tuple costs exactly one request), warming demo caches early in the day, or enabling billing on the Google AI Studio project for higher limits.

### "Could not start checkout." on the Upgrade to Premium button
**Cause:** `POST /api/checkout` returned 500 `stripe_not_configured` because `config('services.stripe.price')` is blank — `config/services.php` reads `env('STRIPE_PRICE_PREMIUM')`, and the frontend collapses every checkout failure into the same generic message. The original instance of this was a naming mismatch: `.env` held the price ID under `CORTEX_PREMIUM_PRICE_ID`, so the key existed but the app never saw it.
**Fix:** Ensure `.env` sets `STRIPE_PRICE_PREMIUM=price_...` under exactly that name (plus `STRIPE_KEY`/`STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET`). Verify what the running container actually resolves with `make artisan CMD="tinker --execute=\"var_dump(config('services.stripe.price'));\""` — no container restart is needed since `.env` is read per request. The price object in Stripe defines the real charge (currently RM20.00/month, MYR); keep `CASHIER_CURRENCY` and the hardcoded button labels in `Dashboard.jsx`/`Optimizer.jsx` in sync with it.

### Live app times out connecting to RDS even though the instance is `available`
**Cause:** The RDS instance was attached to the VPC **default** security group instead of the planned `cortex-rds-sg`. The default group's only inbound rule is self-referencing (allows traffic from members of the same group), and the EC2 host is in `cortex-ec2-sg` — so every packet to 3306 was silently dropped. "Available" in the RDS console says nothing about network reachability.
**Fix:** Add an inbound rule to the RDS security group allowing TCP 3306 **from the EC2 security group** (SG-to-SG reference, never a CIDR): `aws ec2 authorize-security-group-ingress --group-id <rds-sg> --ip-permissions 'IpProtocol=tcp,FromPort=3306,ToPort=3306,UserIdGroupPairs=[{GroupId=<ec2-sg>}]'`. Diagnose with a plain TCP probe from the host — `timeout 5 bash -c '</dev/tcp/<rds-endpoint>/3306'` — timeout means security group/network, an immediate response means the network path is fine and the problem is auth.

### RDS reachable but Laravel gets `SQLSTATE[HY000] [1045] Access denied for user 'cortex'`
**Cause:** Provisioning drift. The RDS instance was created with master user `admin` and **no initial database**, while Parameter Store holds `DB_USERNAME=cortex` / `DB_DATABASE=cortex_lite`. Nothing in the runbook ever created that MySQL user or database on the server, so the app's (correct) credentials matched nothing.
**Fix:** Connect as the master user and create both: `CREATE DATABASE IF NOT EXISTS cortex_lite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`, `CREATE USER IF NOT EXISTS 'cortex'@'%' IDENTIFIED BY '<value of /cortex-lite/DB_PASSWORD>';`, `GRANT ALL PRIVILEGES ON cortex_lite.* TO 'cortex'@'%';`. If the master password is unknown, reset it without downtime via `aws rds modify-db-instance --master-user-password ... --apply-immediately` (status briefly shows `resetting-master-credentials`); it is now stored at `/cortex-lite/RDS_MASTER_PASSWORD`. A quick way to distinguish "wrong password" from "user doesn't exist" is that both return 1045 — check `select user, host from mysql.user` as admin.

### `php artisan db:seed --force` fails in production with `Call to undefined function Database\Factories\fake()`
**Cause:** The production image is built with `composer install --no-dev`, so `fakerphp/faker` is absent, and `DatabaseSeeder` ended by creating a Test User via `User::factory()`. The real seeders (`GpuSeeder`, `CpuSeeder`, `SettingPresetSeeder`) run first and complete fine — only the trailing factory call explodes.
**Fix:** `DatabaseSeeder` now guards the factory user with `if (! app()->environment('production'))`. On live, the demo account comes from `DemoAccountSeeder` (which uses `Hash::make`, not Faker) and is unaffected.
