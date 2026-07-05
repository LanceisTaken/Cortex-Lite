# Architecture

System design and infrastructure. Update when adding or removing services, changing the AWS setup, making schema changes that affect system topology, or adding a new external API integration.

## Stack overview

## Docker services

## Database schema (high-level)

- `users` own account/auth state, including Cashier columns installed in Phase 1 plus nullable Steam linkage fields: `steam_id` (unique SteamID64 string) and `steam_id_resolved_at`.
- `games` is a user-scoped library table for both manual and Steam-imported entries. Steam sync keys rows by `(user_id, steam_app_id)` so repeat syncs update the same Steam-owned row without touching manual rows with null `steam_app_id`.
- `play_sessions` stores manual tracking history for both manual and Steam games. Open rows have `ended_at = null`; end-session writes are transactional and only increment cached `games.playtime_minutes` for manual-sourced games.
- `gpus` and `cpus` are small reference tables seeded from `database/data/*.json`. JSON stores raw benchmark values only; `GpuTierClassifier` and `CpuTierClassifier` materialize the tier column at seed time using absolute thresholds.
- `game_metadata` stores one PCGamingWiki enrichment row per game. It is keyed by unique `game_id`, cascade-deletes with the game, stores structured graphics capability columns, and keeps a capped `raw_response` JSON copy for forward-compatible field additions.
- `setting_presets` stores the 30 curated anchor settings records seeded from `database/data/setting_presets.json`. The natural tuple `(game, goal, gpu_tier)` is unique; `settings` remains a flexible JSON blob because each game exposes different option names.
- Game library list queries are indexed by `(user_id, status)` and `(user_id, last_played_at)`.
- Session queries are indexed by `(user_id, ended_at)` for active lookup and `(user_id, started_at)` for history ordering.
- Hardware typeahead queries are indexed by `(tier, g3d_mark)` for GPUs, `(tier, single_thread_mark)` for CPUs, plus unique indexed names.

## AWS infrastructure (Phase 6+)

## External integrations

- Steam OpenID connects an already-authenticated Cortex Lite user to a SteamID64. It does not replace Sanctum.
- Steam Web API traffic is wrapped behind `App\Services\SteamClient` for `GetPlayerSummaries` and `GetOwnedGames`.
- Steam API responses are cached in Redis: owned games for 1 hour, player summaries for 60 seconds so privacy-setting fixes can recover quickly.
- PCGamingWiki Cargo API traffic is wrapped behind `App\Services\PcGamingWikiClient`. Requests use a required Cortex-Lite User-Agent with `PCGAMINGWIKI_CONTACT_EMAIL`, a Redis token-bucket limiter, and a 7-day AppID-only cache key.
- The scheduler runs `games:enrich-metadata` every five minutes with overlap protection. The command consumes `games.metadata_status = pending`, writes `game_metadata`, and flips each attempted game to `ok` or `missing`; rows blocked by PCGamingWiki rate limiting remain `pending`.
- `App\Services\HeuristicRecommender` is deterministic. It constructs the default settings schema from GPU tier, goal, and PCGamingWiki capability flags; no LLM participates in settings selection.

## Security model

- First-party auth remains Sanctum SPA cookies plus CSRF. Steam is an attached external identity only.
- Steam OpenID verification always posts `check_authentication` to the hard-coded Steam endpoint, never to a request-supplied URL.
- Steam sync writes are transactional and only update server-owned Steam fields on `games`.
- PCGamingWiki raw JSON is capped before persistence to avoid oversized or deeply nested payloads feeding later recommendation code.

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
| GET  | /api/steam/login | auth:sanctum | redirect to Steam OpenID |
| GET  | /api/steam/callback | auth:sanctum | attach verified SteamID64, then redirect to `/dashboard` |
| POST | /api/steam/connect-id | auth:sanctum | manual Steam fallback via direct SteamID64 entry |
| POST | /api/steam/sync | auth:sanctum | transactional Steam library sync |
| POST | /api/sessions/start | auth:sanctum, throttle:30,1 | start a user-scoped play session |
| POST | /api/sessions/{session}/end | auth:sanctum, throttle:30,1 | end own session, transactional duration/playtime update |
| GET | /api/sessions/active | auth:sanctum | current open session with game summary |
| GET | /api/sessions | auth:sanctum | paginated ended-session history |
| GET | /api/hardware/gpus | auth:sanctum | top 20 GPU typeahead results ordered by G3D Mark |
| GET | /api/hardware/cpus | auth:sanctum | top 20 CPU typeahead results ordered by single-thread PassMark |

**Notification URL rewriting:**
`VerifyEmail::createUrlUsing` and `ResetPassword::createUrlUsing` in `AppServiceProvider::boot()` rewrite the notification URLs to point at the frontend routes. The SPA verification page POSTs the preserved signed URL back to the backend to complete the flow.

**Cashier installed early.** Only `Billable` trait, migrations, and the `subscription()` API surface land in Phase 1 — no Stripe routes, no webhook, no checkout UI. Those arrive in Phase 5.
