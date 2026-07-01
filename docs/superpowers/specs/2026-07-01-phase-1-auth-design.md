# Phase 1 — Auth & User Management (design)

**Date:** 2026-07-01
**Branch target:** `Phase-1` off `main`
**Owner:** Goh Yin Xu
**Scope:** Executes Phase 1 of `docs/cortex-lite-build-plan.md`.

## 1. Goal

Ship a Laravel Sanctum SPA authentication surface for the Cortex Lite React client — registration, login (throttled with `Retry-After`), logout, session/CSRF plumbing, email verification, password reset, and account deletion with active-subscription teardown — and the PHPUnit test suite that proves each piece.

Deliverable state at merge: `docker-compose up` running, an unauthenticated user can register, receive a verification email in the log, verify, log in, log out, forgot/reset password, and delete their account. CI green.

## 2. Non-goals

- Game library, sessions, recommendations (Phases 2–5).
- Live Stripe subscriptions, webhooks, or checkout UI (Phase 5) — Cashier is installed only to make the delete-account cancel path functional.
- 2FA, password confirmation for sensitive ops, remember-me toggle.
- Steam OpenID (Phase 2).
- SQL-injection tests on user-facing search (deferred to Phase 2 — no search field exists in Phase 1). Documented in DECISIONS.md.
- Authorization-boundary / IDOR tests (deferred to Phase 2 — no user-owned resources exist in Phase 1).

## 3. Scope decisions (locked)

| Decision | Choice | Reason |
|---|---|---|
| Dev-time client → API topology | Vite proxy `/api` and `/sanctum` → nginx | Same origin, matches prod, eliminates the #1 Sanctum SPA gotcha in the dev loop |
| Stripe cancel path in `DELETE /api/account` | Install Cashier in Phase 1 | Endpoint ships fully working; no half-shipped resume bullet |
| Password reset | Included | Explicitly requested outside plan checklist |
| Email verification | Included, gate-model (`verified` middleware on protected routes; login allowed while unverified) | Standard SaaS pattern; verification banner on dashboard until email confirmed |
| Dev mail transport | `MAIL_MAILER=log` (writes MIME to `storage/logs/laravel.log`) | Zero infra; verification and reset URLs copy-paste from log during test |
| Password rule chain | `Password::min(8)->mixedCase()->numbers()->uncompromised()` | HIBP k-anonymity lookup, no PII leaked; portfolio signal |
| Delete-account extraction | `App\Actions\Auth\DeleteAccountAction`; other flows stay in-controller | Only endpoint with non-trivial branching; Actions pattern demonstrated where it earns its keep |

## 4. Architecture & wiring

### 4.1 Bootstrap
- Run `php artisan install:api` to scaffold `routes/api.php` and register Sanctum's `EnsureFrontendRequestsAreStateful` on the `api` group.
- `bootstrap/app.php` gains `api: __DIR__.'/../routes/api.php'` under `->withRouting(...)`.
- CORS middleware is registered globally via the framework default (`HandleCors` in `withMiddleware`).

### 4.2 Sanctum SPA config
- `SANCTUM_STATEFUL_DOMAINS=localhost:5173`
- `SESSION_DOMAIN=localhost`, `SESSION_SAME_SITE=lax`, `SESSION_SECURE_COOKIE=false` in dev (`true` in prod)
- `config/cors.php`: `paths=[api/*, sanctum/csrf-cookie, login, logout]`, `supports_credentials=true`, `allowed_origins=[http://localhost:5173]`
- `FRONTEND_URL=http://localhost:5173` in `.env` and `.env.example`

### 4.3 Cashier (Phase 1 install)
- `composer require laravel/cashier`
- Publish + run Cashier migration (`stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at` on `users`; new `subscriptions`, `subscription_items` tables)
- `Billable` trait added to `App\Models\User`
- `.env.example` verified for `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `CASHIER_CURRENCY=usd`
- No Stripe routes, no webhook, no checkout UI — those are Phase 5

### 4.4 Mail
- `MAIL_MAILER=log`, `MAIL_FROM_ADDRESS=hello@cortex-lite.test`, `MAIL_FROM_NAME="Cortex Lite"`
- Prod override deferred to Phase 6 (Parameter Store)

### 4.5 Frontend origin
- `client/vite.config.js` grows a `server.proxy` block:
  - `'/api': { target: 'http://localhost:8080', changeOrigin: true }`
  - `'/sanctum': { target: 'http://localhost:8080', changeOrigin: true }`
- Axios instance in `client/src/lib/api.js` uses `baseURL: '/'` and `withCredentials: true`
- `APP_URL=http://localhost:8080` in `.env` (matches the proxy target so signed URLs verify correctly). `changeOrigin: true` in the Vite proxy ensures Laravel sees `Host: localhost:8080` on incoming requests.

### 4.6 Migration order
1. Existing users table (already migrated in Phase 0).
2. Cashier's `add_stripe_columns_to_users_table`.
3. Cashier's `create_subscriptions_table`.
4. Cashier's `create_subscription_items_table`.

No custom users migration in Phase 1 — the default table already has `email_verified_at`.

### 4.7 Notification URL rewriting (SPA)
In `App\Providers\AppServiceProvider::boot()`:

```php
VerifyEmail::createUrlUsing(function ($notifiable) {
    $backendSignedUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(config('auth.verification.expire', 60)),
        ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())]
    );
    // Pass backend signed query string through to SPA route.
    $qs = parse_url($backendSignedUrl, PHP_URL_QUERY);
    return config('app.frontend_url')
        ."/verify-email/{$notifiable->getKey()}/".sha1($notifiable->getEmailForVerification())
        ."?{$qs}";
});

ResetPassword::createUrlUsing(fn ($user, string $token) =>
    config('app.frontend_url')."/reset-password/{$token}?email=".urlencode($user->email)
);

Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->uncompromised());
```

## 5. Backend surface

### 5.1 Route table (`routes/api.php`)

| Verb | Path | Middleware | Handler | Route name |
|---|---|---|---|---|
| POST | `/api/register` | `guest` | `RegisterController@store` | `register` |
| POST | `/api/login` | `guest`, `throttle:5,1` | `LoginController@store` | `login` |
| POST | `/api/logout` | `auth:sanctum` | `LoginController@destroy` | `logout` |
| GET  | `/api/me` | `auth:sanctum`, `verified` | `UserController@show` | `me` |
| POST | `/api/forgot-password` | `guest`, `throttle:6,1` | `PasswordResetLinkController@store` | `password.email` |
| POST | `/api/reset-password` | `guest`, `throttle:6,1` | `NewPasswordController@store` | `password.update` |
| POST | `/api/email/verify/{id}/{hash}` | `auth:sanctum`, `signed`, `throttle:6,1` | `EmailVerificationController@verify` | `verification.verify` |
| POST | `/api/email/verification-notification` | `auth:sanctum`, `throttle:6,1` | `EmailVerificationController@resend` | `verification.send` |
| DELETE | `/api/account` | `auth:sanctum` | `AccountController@destroy` | `account.destroy` |

Note: `verification.verify` is POST (not GET) because the SPA's `/verify-email/:id/:hash` page proxies the click. The backend route is called via XHR with the signature query preserved.

### 5.2 Controllers

`app/Http/Controllers/Auth/`
- `RegisterController@store` — validates via `RegisterRequest`, creates user, fires `Registered` (triggers verification email), logs in via `Auth::guard('web')->login($user)`, regenerates session, returns 201 + user JSON.
- `LoginController@store` — validates via `LoginRequest`. On success: `Auth::attempt`, session regen, 200 + user JSON. On failure: 422 with `auth.failed` translation (never reveals which field). Throttle at route level.
- `LoginController@destroy` — `Auth::guard('web')->logout()`, `$request->session()->invalidate()`, `regenerateToken()`, 204.
- `EmailVerificationController@verify` — extends framework's `verifyEmail` shape; marks verified, fires `Verified` event, returns 204. (SPA redirects to dashboard client-side.)
- `EmailVerificationController@resend` — resends verification notification, 202.
- `PasswordResetLinkController@store` — `Password::sendResetLink($request->only('email'))`; always returns 200 with generic "if that email exists, we sent a link" message (enumeration guard).
- `NewPasswordController@store` — `Password::reset(...)`; 200 on success, 422 on invalid token.

`app/Http/Controllers/`
- `UserController@show` — `return $request->user()->only('id','name','email','email_verified_at','created_at')`.
- `AccountController@destroy` — thin wrapper:

```php
public function destroy(Request $request, DeleteAccountAction $action): Response
{
    $user = $request->user();
    $action->execute($user);
    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return response()->noContent();
}
```

### 5.3 `App\Actions\Auth\DeleteAccountAction`

```php
public function execute(User $user): void
{
    DB::transaction(function () use ($user) {
        if ($user->subscribed('default')) {
            $user->subscription('default')->cancelNow();
        }
        $user->delete();
    });
}
```

- `cancelNow()` (not `cancel()`) — no billing-period trailing state after user deletion.
- The transaction ensures a `cancelNow()` failure does not leave a deleted user; a `delete()` failure does not leave a cancelled subscription behind on an existing user (Cashier's `cancelNow` is idempotent-tolerant and the local subscription row is inside the same transaction).
- No cascade of games/sessions/recommendations yet — those tables land in Phases 2/3/5 with FK on-delete cascade.

### 5.4 Form Requests

- `RegisterRequest`
  - `name: required|string|max:255`
  - `email: required|string|email|max:255|unique:users,email`
  - `password: required|string|confirmed|`\`Password::defaults()\`
- `LoginRequest`
  - `email: required|string|email`
  - `password: required|string`
- `ForgotPasswordRequest`
  - `email: required|string|email`
- `ResetPasswordRequest`
  - `token: required|string`
  - `email: required|string|email`
  - `password: required|string|confirmed|`\`Password::defaults()\`

### 5.5 User model additions
- `use Billable;` (from Cashier)
- `implements MustVerifyEmail` — activates the verification lifecycle
- Fillable/Hidden attributes unchanged from Phase 0.

## 6. Frontend surface

### 6.1 Dependencies
- `react-router-dom` v6
- `axios`
- `tailwindcss` + `@tailwindcss/vite` (installed here; Phase 0 stack ref listed Tailwind but it wasn't wired up — will call out in DECISIONS.md)

### 6.2 Directory shape
```
client/src/
  lib/api.js              # axios instance + csrf helper
  context/AuthContext.jsx # provider + useAuth hook
  pages/
    Login.jsx
    Register.jsx
    ForgotPassword.jsx
    ResetPassword.jsx     # reads :token param + ?email
    VerifyEmail.jsx       # POSTs signed URL back, redirects
    Dashboard.jsx
    Account.jsx           # delete-account with double-confirm modal
  components/
    ProtectedRoute.jsx    # requires auth; optionally requires verified
    VerifiedBanner.jsx
    ui/                   # Button, Input, FormError primitives
  App.jsx                 # router config
  main.jsx                # wraps App in AuthProvider
```

### 6.3 CSRF flow (`lib/api.js`)
- Axios instance created with `baseURL: '/'`, `withCredentials: true`, `xsrfCookieName: 'XSRF-TOKEN'`, `xsrfHeaderName: 'X-XSRF-TOKEN'`.
- A request interceptor: for any non-safe method (POST/PUT/PATCH/DELETE), if the `XSRF-TOKEN` cookie is not present, `await api.get('/sanctum/csrf-cookie')` first.

### 6.4 `AuthContext` contract
```js
const { user, loading, login, register, logout, refresh } = useAuth()
```
- `refresh()` on mount: `GET /api/me`; on 200 set user, on 401/419 clear user.
- `login/register` handle CSRF+call, set user, throw with error payload on failure.
- `logout` clears user + calls API.
- `user.email_verified_at` drives banner + `ProtectedRoute` verification enforcement.

### 6.5 Routes
| Path | Component | Guard |
|---|---|---|
| `/login` | `Login` | guest (redirect authed → `/dashboard`) |
| `/register` | `Register` | guest |
| `/forgot-password` | `ForgotPassword` | guest |
| `/reset-password/:token` | `ResetPassword` | guest, reads `?email=` |
| `/verify-email/:id/:hash` | `VerifyEmail` | authed; POSTs signed URL |
| `/dashboard` | `Dashboard` | authed (banner if unverified) |
| `/account` | `Account` | authed + verified |
| `*` | redirect | authed → `/dashboard`, else `/login` |

### 6.6 Page behavior notes
- Form pages: controlled inputs, Tailwind form styling, inline error surface, 429 handling reads `Retry-After` header and disables submit for that many seconds with a live countdown.
- `Dashboard`: greeting, verification banner + resend button if `!email_verified_at`, link to `/account`.
- `Account`: red delete button opens modal, user types their email verbatim to confirm, calls `DELETE /api/account`, redirects to `/login`.
- `VerifyEmail`: on mount, reads full URL (`{ id, hash }` from path, `signature` and `expires` from `window.location.search`), POSTs to `/api/email/verify/{id}/{hash}?...`, shows success/error, redirects.

### 6.7 Not in scope
- Toast/notification library (inline form messages only).
- Design system beyond primitives.
- Remember-me toggle.

## 7. Testing surface

### 7.1 Framework
- PHPUnit, SQLite in-memory (from Phase 0's `.env.testing`).
- `RefreshDatabase` on every feature test.
- `Notification::fake()`, `Event::fake()`, `Mail::fake()` per test as needed.

### 7.2 Feature tests

**`tests/Feature/Auth/RegisterTest.php`**
- `test_user_can_register_with_valid_data`
- `test_registration_rejects_duplicate_email`
- `test_registration_rejects_weak_password`
- `test_registration_ignores_mass_assigned_fields` (payload includes `is_admin`, `email_verified_at`; assert both unset on persisted user)

**`tests/Feature/Auth/LoginTest.php`**
- `test_valid_credentials_login_succeeds`
- `test_invalid_credentials_return_generic_error`
- `test_login_throttles_after_five_failures` (assert 429 + `Retry-After` header numeric)
- `test_logout_invalidates_session`

**`tests/Feature/Auth/CsrfTest.php`**
- `test_missing_csrf_token_is_rejected` (POST `/api/login` w/o token → 419)
- `test_csrf_cookie_endpoint_sets_xsrf_cookie`

**`tests/Feature/Auth/EmailVerificationTest.php`**
- `test_verify_link_marks_email_verified`
- `test_verify_link_rejects_bad_signature`
- `test_verify_link_rejects_wrong_hash`
- `test_resend_link_throttled` (7th resend → 429)
- `test_me_endpoint_requires_verified` (unverified user → 409 via `verified` middleware)

**`tests/Feature/Auth/PasswordResetTest.php`**
- `test_forgot_password_sends_reset_notification`
- `test_forgot_password_returns_same_response_for_unknown_email`
- `test_reset_password_with_valid_token_changes_password`
- `test_reset_password_rejects_invalid_token`

**`tests/Feature/Account/DeleteAccountTest.php`**
- `test_authed_user_can_delete_account`
- `test_delete_account_no_subscription_no_op`
- `test_unauthenticated_delete_returns_401`

**`tests/Feature/Auth/RouteExposureTest.php`**
- `test_protected_routes_reject_guest` (list: `/api/me`, `/api/account`, `/api/logout`)

### 7.3 Unit tests

**`tests/Unit/NotificationUrlTest.php`**
- `test_verification_url_points_to_frontend` (asserts `http://localhost:5173/verify-email/{id}/{hash}?signature=…&expires=…`)
- `test_reset_url_points_to_frontend` (asserts `http://localhost:5173/reset-password/{token}?email=…`)

**`tests/Unit/Actions/DeleteAccountActionTest.php`**
- `test_deletes_user_when_no_subscription`
- `test_cancels_subscription_then_deletes_user` (mock the subscription; assert `cancelNow()` called before `delete()`)
- `test_rollback_when_delete_fails` (force `$user->delete()` to throw; assert transaction rolled back)
- `test_rollback_when_cancel_fails` (force `cancelNow()` to throw; assert user not deleted)

### 7.4 Stripe test strategy
- No live Stripe calls in Phase 1. Cashier's `Subscription` model is seeded locally and `cancelNow()` is mocked via Mockery in the unit tests. The `test_cancels_subscription_then_deletes_user` action test uses a partial mock on the `User` returning a mock `Subscription`.

### 7.5 Target counts
- 23 feature tests (Register 4 + Login 4 + Csrf 2 + EmailVerification 5 + PasswordReset 4 + DeleteAccount 3 + RouteExposure 1), 6 unit tests (NotificationUrl 2 + DeleteAccountAction 4). All green on CI.

## 8. Docs updates (blocks the phase-close PR)

### 8.1 `docs/DECISIONS.md` — three new entries
1. **"Cashier installed in Phase 1 for `DELETE /api/account`"** — pulled forward from Phase 5 to make the endpoint fully functional; Stripe routes/webhook/UI still deferred.
2. **"Vite proxy for dev instead of separate origins + CORS"** — matches prod topology, eliminates Sanctum's #1 gotcha in the dev loop; CORS config kept for prod symmetry.
3. **"Delete-account extracted to an Action; other auth flows stay in-controller"** — Actions pattern applied where branching earns it, not blanket.

(The Phase 1 scope deferrals — SQL-injection test on search, IDOR tests — are already noted in section 2 non-goals; they land in Phase 2 when the resources they'd test exist.)

### 8.2 `docs/TROUBLESHOOTING.md` — two new entries
1. **"Sanctum SPA login returns 419 CSRF token mismatch"** — three-part checklist: CSRF cookie hit, `withCredentials: true`, stateful domain match. Dev origin under Vite proxy is `localhost:5173`.
2. **"Email verification link 403 in dev"** — the SPA verification page must forward `window.location.search` verbatim on the API call so the signature is preserved.

### 8.3 `docs/ARCHITECTURE.md` — new "Authentication" section
- Sanctum SPA mode overview.
- Cookie flow diagram: browser → `/sanctum/csrf-cookie` → session + XSRF cookies → subsequent XHR with `X-XSRF-TOKEN` header.
- Full auth route table.
- Notification URL rewriting to the SPA (verify + reset).
- Cashier install rationale (early plug for delete-account).

### 8.4 `README.md` — sprint changelog line
```
Phase 1 — Sanctum SPA auth (register/login/logout/me), password reset, email verification, throttled login with Retry-After, delete-account with Cashier subscription teardown, 23 feature + 6 unit tests. Cashier installed early; no live Stripe surface yet.
```

### 8.5 `CLAUDE.md` — phase tracker check `[x] Phase 1 — Auth & user management`.

### 8.6 `SESSION_LOG.md` — new topmost entry summarizing Phase 1.

## 9. Merge PR
- Branch: `Phase-1` → `main`.
- Commit trailer style: `[Sprint 1] auth (Sanctum SPA), password reset, email verification, delete-account with Cashier`.
- CI must be green.
- All eight docs steps complete before merge.

## 10. Time-box + fallback
- **Budget:** 3–4 days.
- **Fallback if overrun:** ship without React polish; functional ugly is fine. If still over: drop email verification enforcement (leave the notifications but remove `verified` middleware gate — record as scope cut in DECISIONS.md, restore in Phase 2 alongside game routes).

## 11. Success criteria (Definition of Done)
- `docker-compose up` clean.
- `make test` green.
- CI green on the PR.
- Manual flow through the browser: register → open verification link from `storage/logs/laravel.log` → verified → log in → forgot password → reset from log → log in with new password → delete account → redirected to login, user gone from `users` table.
- All docs updates present in the merge PR.
