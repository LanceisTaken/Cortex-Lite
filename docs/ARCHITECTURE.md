# Architecture

System design and infrastructure. Update when adding or removing services, changing the AWS setup, making schema changes that affect system topology, or adding a new external API integration.

## Stack overview

## Docker services

## Database schema (high-level)

- `users` own account/auth state, including Cashier columns installed in Phase 1.
- `games` is a user-scoped library table. It stores manual entries now and is shaped for the later Steam sync path: nullable `steam_app_id`, `source` (`manual` or `steam`), `metadata_status` (`pending`, `ok`, `missing`), nullable `cover_url`, status (`playing`, `backlog`, `completed`, `dropped`), and `playtime_minutes`. `user_id` cascades on delete.
- Game library list queries are indexed by `(user_id, status)` and `(user_id, last_played_at)`.

## AWS infrastructure (Phase 6+)

## External integrations

## Security model

## Authentication

Cookie-based Sanctum SPA auth. React (dev on Vite `:5173`, prod behind nginx) treats itself as first-party to the API.

**Cookie flow:**
1. Browser GETs `/sanctum/csrf-cookie` (204). Server sets `XSRF-TOKEN` cookie (readable JS) and `laravel_session` cookie (HTTP-only).
2. Every state-changing XHR carries the `XSRF-TOKEN` value in the `X-XSRF-TOKEN` header (Axios does this via `withXSRFToken: true`).
3. `EnsureFrontendRequestsAreStateful` (Sanctum) short-circuits the api group's auth to session-based when the request comes from a stateful domain.

**Auth route table:**

| Verb | Path | Middleware | Notes |
|---|---|---|---|
| POST | /api/register | guest | 201, logs the user in, fires Registered |
| POST | /api/login | guest, throttle:5,1 | 429 + Retry-After after 5 fails |
| POST | /api/logout | auth:sanctum | invalidates session |
| GET  | /api/me | auth:sanctum | returns user (verified flag included) |
| POST | /api/forgot-password | guest, throttle:6,1 | enumeration-safe response |
| POST | /api/reset-password | guest, throttle:6,1 | Password::defaults() applied |
| POST | /api/email/verify/{id}/{hash} | auth:sanctum, signed, throttle:6,1 | SPA re-POSTs the signed URL |
| POST | /api/email/verification-notification | auth:sanctum, throttle:6,1 | resend |
| DELETE | /api/account | auth:sanctum | via DeleteAccountAction (transaction: Cashier cancelNow → delete) |

**Notification URL rewriting:**
`VerifyEmail::createUrlUsing` and `ResetPassword::createUrlUsing` in `AppServiceProvider::boot()` rewrite the notification URLs to point at the frontend routes. The SPA verification page POSTs the preserved signed URL back to the backend to complete the flow.

**Cashier installed early.** Only `Billable` trait, migrations, and the `subscription()` API surface land in Phase 1 — no Stripe routes, no webhook, no checkout UI. Those arrive in Phase 5.
