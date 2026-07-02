# Plan: Steam OpenID login + Web API integration (Phase 2b)
**Created:** 2026-07-02
**Status:** draft
**Complexity:** medium
---
## Context
Phase 2 of the Cortex Lite build plan shipped manual game CRUD (see `.code-foundations/plans/2026-07-02-phase-2-games-crud.md`) but left the Steam OpenID + Web API integration outstanding. Users cannot log in with Steam, auto-import their library, or benefit from nightly re-sync. This is the differentiating feature of Phase 2 and blocks the demo narrative for the Razer JD portfolio.

## Constraints
- Sanctum SPA cookie auth remains authoritative for the app; Steam OpenID is a *connection* on the already-authenticated user, not an alternative session.
- Steam Web API responses cached in Redis (1h TTL for owned-games, 60s for player summary — the sync flow re-reads summary on every user click and the two TTLs let a user recover within a minute after fixing privacy toggles). Cache keys must not contain timestamps or request-unique values.
- Steam bulk-insert/update wrapped in a single DB transaction.
- Private-profile rejection returns a structured 422 pointing to the two Steam toggles (Profile + Game Details).
- Hand-rolled OpenID verification (~50 lines). No `xPaw/SteamOpenID` composer dep.
- `include_appinfo=1&include_played_free_games=1` on `GetOwnedGames`.
- Cover-art URL pattern: `https://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{img_icon_url}.jpg`.
- One bundled PR covering backend + frontend + scheduler + tests + docs.

---
## Chosen Approach
**Thin controllers + `SteamClient` (HTTP boundary) + action classes (`SteamOpenIdVerifier`, `SteamLibrarySynchronizer`) + artisan command.** Rationale: build plan explicitly names `SteamClient.php`; `Http::fake()` seam stays clean at one boundary class; scheduler and on-demand sync share the same synchronizer code path (no duplication); hand-rolled OpenID crypto is isolated in one small testable unit rather than inlined in a controller. **Fallback:** if action-class extraction feels heavy mid-build, collapse `SteamLibrarySynchronizer` into the sync controller — the OpenID verifier extraction stays non-negotiable.

## Rejected Approaches
- **Fat controllers + thin HTTP wrapper:** duplicates sync logic across the `/api/steam/sync` endpoint and the nightly artisan command; embeds OpenID crypto next to routing.
- **Single `SteamService` god-class:** grows past 400 lines mixing HTTP, crypto, DB transactions; no precedent in this repo.

---
## Implementation Phases

### Phase 1: Schema + config foundation
**Model:** haiku
**Skills:** none -- pure schema + config edits with no routine or module design surface (data-only change; user model gets 2 columns, games gets 1 unique index)
**Gate:** Standard

**Goal:** Add the `steam_id` + `steam_id_resolved_at` columns to `users`, add a unique composite index on `games(user_id, steam_app_id)` so Phase 4's upsert can dedupe correctly, register the `services.steam` config block (API key), and expose `steam_id` on the `User` model so downstream phases have a persistence target.

**Scope:**
- IN: migration adding `users.steam_id` (nullable string, unique) + `users.steam_id_resolved_at` (nullable timestamp); migration adding unique composite index on `games(user_id, steam_app_id)`; `config/services.php` entry; `.env.example` update; `User` model update.
- OUT: any Steam HTTP calls; any controller code; any UI.

**Constraints:** `steam_id` is a numeric SteamID64 stored as string (17 digits, exceeds 32-bit int range). `unique` index on `users.steam_id` so one Steam account cannot connect to two Cortex accounts. MySQL allows multiple NULLs in a unique index, so the composite index on `games(user_id, steam_app_id)` does NOT affect manual games (all null on `steam_app_id`) — verify this in a test rather than trusting the docs.
**Edge cases:** existing `.env` may already carry `STEAM_API_KEY` from Phase 0 scaffolding — the migration and config both must tolerate that; existing games rows written before this migration must not conflict with the new unique index (they either have distinct `steam_app_id` values or all-null values, which the CRUD phase enforced).
**Depends on:** none | **Unlocks:** Phase 2, Phase 3, Phase 4
**File scope:** `database/migrations/**`, `app/Models/User.php`, `config/services.php`, `.env.example`
**Produces:** `users.steam_id` (nullable string 20, unique) + `users.steam_id_resolved_at` (nullable timestamp); unique index `games_user_id_steam_app_id_unique` on `games(user_id, steam_app_id)`; `config('services.steam.api_key')` (string, sourced from env `STEAM_API_KEY`).

**Approach notes:** Keep the User model's `$fillable` opt-in — do not add `steam_id` to fillable; assign it via explicit property setter after OpenID verification (mass-assignment safety). Steam Web API does not require a custom User-Agent (that requirement belongs to PCGamingWiki in Phase 4 of the build plan) — do not add `STEAM_USER_AGENT`.
**File hints:** `database/migrations/` — Laravel migration pattern per prior migrations. `config/services.php` — extend the existing Stripe/Sanctum services block.

**Rollback:** Both migrations are cleanly reversible via `migrate:rollback` (drops columns + unique index). Verify with DW-1.1.

**Done when:**
- [ ] DW-1.1: `php artisan migrate` adds both column additions and the composite index without dropping existing data; `php artisan migrate:rollback` restores prior state cleanly.
- [ ] DW-1.2: `config('services.steam.api_key')` returns the env value (assert via `config()->get()`).
- [ ] DW-1.3: `.env.example` documents `STEAM_API_KEY` with a blank value.
- [ ] DW-1.4: Unique index on `users.steam_id` rejects a second user inserting the same SteamID64 (integration test).
- [ ] DW-1.5: `User` model does not include `steam_id` in `$fillable` (guarded against mass-assignment).
- [ ] DW-1.6: Composite unique index on `games(user_id, steam_app_id)` rejects a second insert of the same `(user_id, steam_app_id)` pair, while allowing multiple manual (null-appid) rows for the same user (integration test).

**Difficulty:** LOW
**Uncertainty:** None — schema shape is dictated by the build plan.

---

### Phase 2: `SteamClient` service (HTTP boundary)
**Model:** fable
**Skills:** aposd-designing-deep-modules, cc-defensive-programming
**Gate:** Full
**Security-sensitive:** yes

**Goal:** Build the single Steam Web API boundary class wrapping `GetOwnedGames`, `GetPlayerSummaries`, and `ResolveVanityURL` with Redis caching (1h TTL for `getOwnedGames`, 60s TTL for `getPlayerSummary` — see Constraints for why) and cover URL construction, so downstream OpenID and sync phases talk to Steam through one testable seam.

**Scope:**
- IN: `SteamClient` service with three methods + typed exceptions `SteamApiException`, `SteamPrivateLibraryException`; Redis cache; vanity input normalization + validation; cover URL builder; unit tests using `Http::fake()`.
- OUT: OpenID verification (Phase 3); DB writes (Phase 4); any controller/route.

**Constraints:**
- Cache key MUST NOT include timestamps or request-unique values.
- `getOwnedGames` TTL: 3600s. `getPlayerSummary` TTL: 60s — the sync flow re-reads this on every `POST /api/steam/sync`, and a 1h cache would trap a user who fixed both privacy toggles into a locked-out state for up to an hour (documented recovery flow in the pre-seeded TROUBLESHOOTING entry).
- `getOwnedGames` query must include `include_appinfo=1&include_played_free_games=1`.
- **Game Details privacy detection:** a public *Profile* with private *Game Details* returns `GetOwnedGames` = `{"response":{}}` (no `games` key, no `game_count`), distinct from `{"game_count":0,"games":[]}` (public library, empty). `getOwnedGames` MUST throw `SteamPrivateLibraryException` on the missing-key case so the sync path maps to the same 422 as private-profile.
- `resolveVanityUrl` accepts raw vanity handle OR full `https://steamcommunity.com/id/foo/` URL — extract last path segment BEFORE calling. Post-extraction, validate against `^[A-Za-z0-9_-]{1,32}$`; reject invalid with null (do not cache-key raw user input).
- Failure modes: HTTP timeout, non-200 response, malformed JSON → throw `SteamApiException`; `response.success != 1` on vanity → return null.

**Edge cases:**
- Empty `games` array with `game_count:0` (public profile, empty library) → returns empty Collection (NOT throws).
- Missing `games` key entirely (private Game Details) → throws `SteamPrivateLibraryException` (NOT empty Collection).
- Steam returning `success != 1` on vanity → returns null.
- Missing `img_icon_url` on a game row → cover URL omitted for that game only; row still included.
- Missing `playtime_forever` → defaults to 0.
- Vanity input matching invalid regex → return null; do not cache.

**Depends on:** Phase 1 | **Unlocks:** Phase 3, Phase 4
**File scope:** `app/Services/SteamClient.php`, `app/Exceptions/SteamApiException.php`, `app/Exceptions/SteamPrivateLibraryException.php`, `tests/Unit/Services/SteamClientTest.php`
**Produces:**
- `SteamClient::getOwnedGames(string $steamId): \Illuminate\Support\Collection` — each item: `['appid' => int, 'name' => string, 'playtime_forever' => int (minutes), 'cover_url' => ?string]`. Throws `SteamPrivateLibraryException` if the Steam response has no `games` key.
- `SteamClient::getPlayerSummary(string $steamId): array` — raw player summary including `communityvisibilitystate` (int).
- `SteamClient::resolveVanityUrl(string $vanity): ?string` — SteamID64 as string, or null on invalid input / `success != 1`.
- Typed exceptions `SteamApiException` (transport/parse failures) and `SteamPrivateLibraryException` (private Game Details signal).

**Approach notes:** Use Laravel `Http::` facade throughout (enables `Http::fake()`). Cache via `Cache::remember()`; cache keys are `steam:owned_games:{sha1(steamid)}`, `steam:summary:{sha1(steamid)}`, `steam:vanity:{sha1(normalized_handle)}` — hashing the input prevents key-shape abuse and normalizes lengths.
**File hints:** `app/Services/` (new directory) — first service class in the repo; establish the convention. `config/services.php` — read `services.steam.api_key` set in Phase 1.

**Done when:**
- [ ] DW-2.1: `getOwnedGames` normalizes the Steam response into the documented Collection shape with cover URLs constructed from `img_icon_url` (unit test with `Http::fake()`).
- [ ] DW-2.2: Second call to `getOwnedGames` with the same SteamID64 within the TTL window returns the cached value without a second `Http::` call (assert `Http::assertSentCount(1)`).
- [ ] DW-2.3: Cache key is deterministic and does not contain any timestamp or request-unique value — asserted via `Cache::has()` on the predictable hashed key.
- [ ] DW-2.4: `resolveVanityUrl` returns null when Steam response `success != 1`.
- [ ] DW-2.5: `getPlayerSummary` returns raw payload including `communityvisibilitystate`.
- [ ] DW-2.6: Malformed JSON / non-200 on `getOwnedGames` throws `SteamApiException`.
- [ ] DW-2.7: `getOwnedGames` on a Steam response with no `games` key throws `SteamPrivateLibraryException` (distinct from empty-library `game_count:0` which returns empty Collection).
- [ ] DW-2.8: `resolveVanityUrl` returns null on invalid vanity input (fails `^[A-Za-z0-9_-]{1,32}$`) without hitting Steam.
- [ ] DW-2.9: `getPlayerSummary` cache TTL is 60s (asserted via `Cache::store()->getStore()` or equivalent).
- [ ] DW-2.10: Missing `playtime_forever` on a game row defaults to 0 minutes; missing `img_icon_url` produces null `cover_url` without dropping the row.
- [ ] DW-2.11: Vanity input as a full profile URL (`https://steamcommunity.com/id/handle/`) is normalized to the handle before validation and caching.

**Difficulty:** MEDIUM
**Uncertainty:** Steam's actual response shape on `include_played_free_games=1` corner cases (e.g. Steam handing back a game with no `playtime_forever`). Mitigation: default to 0 minutes on missing key; verified by unit test.

---

### Phase 3: OpenID auth flow + vanity fallback
**Model:** fable
**Skills:** aposd-designing-deep-modules, cc-defensive-programming, code-clarity-and-docs
**Gate:** Full
**Security-sensitive:** yes

**Goal:** Ship the primary Steam-connection flow (OpenID login + callback) plus the vanity-URL manual fallback, with hand-rolled OpenID verification and strict input barricades, so an authenticated Cortex user can attach their SteamID64 to their account.

**Scope:**
- IN: `SteamOpenIdVerifier` service implementing the `check_authentication` handshake; `SteamAuthController` with `login`, `callback`, `connectVanity` actions; 3 routes wired under `auth:sanctum`; feature tests with `Http::fake()`; DB persistence of `steam_id` + `steam_id_resolved_at` after verification.
- OUT: fetching the library (Phase 4); any UI (Phase 5).

**Constraints:** `SteamOpenIdVerifier::verify(array $queryParams): ?string` must enforce ALL of the following guards, each returning null on failure (never throw): (a) `openid.ns == http://specs.openid.net/auth/2.0`; (b) `openid.mode == id_res` at input; (c) `openid.op_endpoint == https://steamcommunity.com/openid/login` (reject non-Steam OPs — treat missing param as a failed guard); (d) `openid.return_to` matches our own registered callback URL (route-name-driven, not string-matched to request host); (e) POST all supplied params back to a HARD-CODED `https://steamcommunity.com/openid/login` endpoint constant with `openid.mode` rewritten to `check_authentication` — NEVER dispatch to the request-supplied `op_endpoint` (SSRF / forged-OP hardening); (f) require the response body to contain `is_valid:true`; (g) extract the SteamID64 from `openid.claimed_id` matching `^https://steamcommunity\.com/openid/id/(\d{17})$`. Callback maps a null return to a **302 redirect** to `/dashboard?steam_error=steam_openid_verification_failed`. `SANCTUM_STATEFUL_DOMAINS` + CSRF flow already handled by Phase 1 auth work — do not re-configure. Vanity endpoint maps failures to **422** with structured error body (the two error responses are shaped differently because one path is a browser redirect and the other is an XHR).
**Edge cases:** user hits `/callback` without an active Sanctum session → 401 (not a redirect loop); vanity input is a full profile URL not just the handle → extract last path segment before calling `SteamClient::resolveVanityUrl`; a *different* Cortex user has already claimed the SteamID64 → 409 with error code `steam_id_already_linked`; the same user re-connecting → update `steam_id_resolved_at`, 200 OK.
**Depends on:** Phase 1, Phase 2 | **Unlocks:** Phase 4
**File scope:** `app/Services/SteamOpenIdVerifier.php`, `app/Http/Controllers/SteamAuthController.php`, `app/Http/Requests/Steam/ConnectVanityRequest.php`, `routes/api.php`, `tests/Feature/Steam/OpenIdTest.php`, `tests/Feature/Steam/VanityConnectTest.php`
**Produces:**
- `GET /api/steam/login` → 302 to `https://steamcommunity.com/openid/login?openid.mode=checkid_setup&openid.return_to=<callback>&openid.realm=<origin>&...`
- `GET /api/steam/callback` → 302 to `/dashboard?steam_connected=1` on success, 302 to `/dashboard?steam_error=<code>` on failure; persists `users.steam_id` + `users.steam_id_resolved_at`.
- `POST /api/steam/connect-vanity` (body: `{vanity: string}`) → 200 `{steam_id: string}` on success, 422 `{error_code: string, message: string, help_url?: string}` on failure; persists the same columns.
- All three routes carry `auth:sanctum` middleware.

**Approach notes:** Isolate the `check_authentication` HTTP call and signature-parameter marshalling in `SteamOpenIdVerifier` — do not touch `Http::` from the controller directly. Vanity endpoint uses Form Request validation per existing repo convention (`app/Http/Requests/Games/*`). The DB write is a single `$user->steam_id = ...; $user->steam_id_resolved_at = now(); $user->save();` — no mass-assignment.
**File hints:** `routes/api.php` — extend the existing route file per Phase 2 CRUD pattern. `app/Http/Requests/Games/StoreGameRequest.php` — Form Request convention. `app/Http/Controllers/Auth/LoginController.php` — controller structure convention.

**Rollback:** Migration rollback already covered in Phase 1. No irreversible actions in this phase (persistence is a single-row update).

**Done when:**
- [ ] DW-3.1: Guest hitting `GET /api/steam/login` receives 401 (auth:sanctum enforced).
- [ ] DW-3.2: Authenticated user hitting `GET /api/steam/login` receives 302 to the Steam OpenID endpoint with correct `openid.return_to` and `openid.realm` query params.
- [ ] DW-3.3: `SteamOpenIdVerifier::verify` returns the SteamID64 string when `Http::fake()` is programmed to return `is_valid:true` and all guard params match.
- [ ] DW-3.4: `SteamOpenIdVerifier::verify` returns null when ANY of these hold: `openid.ns` wrong or missing, `openid.mode != id_res` at input, `openid.op_endpoint` wrong or missing, `openid.return_to` does not match our registered callback URL, Steam's `check_authentication` returns `is_valid:false`, `openid.claimed_id` does not match the SteamID64 regex (17 digits).
- [ ] DW-3.4b: The `check_authentication` POST always targets the HARD-CODED `https://steamcommunity.com/openid/login` — asserted by `Http::assertSent` inspecting the URL, regardless of what value was supplied for `openid.op_endpoint` in the input (SSRF hardening test).
- [ ] DW-3.5: Successful callback persists `steam_id` + `steam_id_resolved_at` on the authenticated user and redirects to `/dashboard?steam_connected=1`.
- [ ] DW-3.6: Callback where verifier returns null redirects to `/dashboard?steam_error=steam_openid_verification_failed` and does NOT persist anything.
- [ ] DW-3.7: `POST /api/steam/connect-vanity` with a resolvable vanity persists the SteamID64 and returns 200 with the resolved id.
- [ ] DW-3.8: `POST /api/steam/connect-vanity` with an unresolvable vanity returns 422 with error code `steam_vanity_unresolved` and does NOT persist.
- [ ] DW-3.9: If SteamID64 is already claimed by a different user, both `callback` and `connect-vanity` return / redirect with error code `steam_id_already_linked`.

**Difficulty:** HIGH
**Uncertainty:** Steam's OpenID response occasionally omits `openid.op_endpoint`; the guard should treat "missing" the same as "wrong" (both null). Mitigation: explicit test.

---

### Phase 4: Library sync (endpoint + private-profile guard + transaction)
**Model:** sonnet
**Skills:** aposd-designing-deep-modules, cc-defensive-programming
**Gate:** Full
**Security-sensitive:** yes

**Goal:** Ship `POST /api/steam/sync` which pre-flights profile visibility via `SteamClient::getPlayerSummary`, fetches the library via `SteamClient::getOwnedGames`, and atomically bulk-upserts into the authenticated user's `games` rows using `SteamLibrarySynchronizer` — mapping both privacy paths (private *Profile* via visibility state, private *Game Details* via `SteamPrivateLibraryException`) to a single structured 422.

**Scope:**
- IN: `SteamLibrarySynchronizer` service; `SteamSyncController::store`; DB transaction wrapping the bulk-write; two-path private detection (visibility + missing-games-key); upsert semantics keyed on `(user_id, steam_app_id)` — backed by the composite unique index from Phase 1; feature tests.
- OUT: scheduler command (Phase 5); UI (Phase 5); metadata enrichment (Phase 4 of build plan, separate).

**Constraints:**
- Sync MUST be atomic: `DB::transaction(fn () => ...)` — closure form auto-rolls-back on any throw.
- Existing manual-source games with a matching title but no `steam_app_id` are NOT auto-linked (never clobber user data).
- New Steam-sourced rows carry `source='steam'`, `metadata_status='pending'`.
- Existing Steam-sourced rows update `playtime_minutes` and `cover_url` only — do not overwrite user-editable `status`, `genre`, `platform`.
- `metadata_status` stays `pending` even on re-sync (Phase 4 of build plan resets it to `ok`/`missing`).
- Both private paths map to the same 422: (a) `getPlayerSummary().communityvisibilitystate != 3` (Profile private); (b) `getOwnedGames()` throws `SteamPrivateLibraryException` (Game Details private on an otherwise-public Profile). The 60s `getPlayerSummary` TTL from Phase 2 means a user who fixes both toggles gets un-blocked within ≤ 60 seconds on retry — the documented recovery flow.
- Imported/updated counts CANNOT be derived from `upsert()`'s return value on MySQL (affected-rows counts 2 for updated, 1 for inserted, 0 for unchanged). Pre-query the set of existing `steam_app_id` values for the user, diff against the Steam response set → `imported = new_ids.count()`, `updated = matched_ids.count()`.

**Edge cases:**
- User has no `steam_id` on record → 409 `{error_code: 'steam_not_connected'}`; Steam not called.
- Steam returns `game_count:0` (public profile, empty library) → 200 `{imported: 0, updated: 0}`.
- A game the user manually deleted comes back in the Steam response → re-created (Steam library is authoritative).
- Steam `communityvisibilitystate` in `{1, 2}` (private / friends-only) → 422 `steam_profile_private`.
- Steam response lacks `games` key entirely (SteamPrivateLibraryException) → same 422 shape as private profile.
- Steam response contains a game the user already has as Steam-source → path (b) updates it in-place via the composite unique index.

**Depends on:** Phase 2, Phase 3 | **Unlocks:** Phase 5
**File scope:** `app/Services/SteamLibrarySynchronizer.php`, `app/Http/Controllers/SteamSyncController.php`, `routes/api.php`, `tests/Feature/Steam/SyncTest.php`, `tests/Unit/Services/SteamLibrarySynchronizerTest.php`
**Produces:**
- `POST /api/steam/sync` (empty body, `auth:sanctum`) → 200 `{imported: int, updated: int}` on success.
- 422 `{error_code: 'steam_profile_private', message: string, help: {profile_toggle: string, game_details_toggle: string}}` on either private-Profile OR private-Game-Details.
- 409 `{error_code: 'steam_not_connected'}` when `users.steam_id` is null.
- `SteamLibrarySynchronizer::sync(User $user): array` returning `['imported' => int, 'updated' => int]` — reused by the Phase 5 artisan command.
- Written game rows: `source='steam'`, `metadata_status='pending'`, `steam_app_id` set, `cover_url` populated (if `img_icon_url` present), `playtime_minutes` = Steam `playtime_forever` (already minutes).

**Approach notes:** Use `DB::transaction(fn () => ...)`. Use `Game::upsert()` for the bulk write keyed on `(user_id, steam_app_id)` — the Phase 1 composite unique index is the load-bearing piece. Compute the imported/updated split by pre-querying `Game::where('user_id', $u->id)->whereNotNull('steam_app_id')->pluck('steam_app_id')` and diffing set-wise against the Steam response. Catch `SteamPrivateLibraryException` in the controller and translate to the same 422 shape as the visibility-based path.
**File hints:** `app/Http/Controllers/GameController.php` — pagination/response shape convention. `app/Models/Game.php` — `RESPONSE_FIELDS` constant + relationships.

**Rollback:** `DB::transaction` auto-rollback covers mid-batch failure. No production data destruction — this is an insert/update flow only.

**Done when:**
- [ ] DW-4.1: `POST /api/steam/sync` on a user with `steam_id=null` returns 409 `{error_code: 'steam_not_connected'}` without calling Steam.
- [ ] DW-4.2: `POST /api/steam/sync` where `getPlayerSummary` returns `communityvisibilitystate=1` returns 422 with error code `steam_profile_private` and the two-toggle help object.
- [ ] DW-4.2b: `POST /api/steam/sync` where `getPlayerSummary` returns `communityvisibilitystate=3` BUT `getOwnedGames` throws `SteamPrivateLibraryException` returns the SAME 422 shape (private Game Details path).
- [ ] DW-4.3: Successful sync with a 3-game `Http::fake()` fixture creates 3 rows with `source='steam'`, `metadata_status='pending'`, cover URLs populated (integration test asserting DB state).
- [ ] DW-4.4: Re-sync updates `playtime_minutes` on existing Steam rows without changing `status`, `genre`, or `platform` (integration test with a pre-existing row).
- [ ] DW-4.5: If the bulk write throws mid-batch (simulated), zero rows are persisted (transaction rollback verified by asserting row count unchanged).
- [ ] DW-4.6: A manual-source game with a matching title but no `steam_app_id` is NOT overwritten — a new Steam row is created instead.
- [ ] DW-4.7: Response body `imported` counts NEW `steam_app_id`s for the user, `updated` counts existing Steam rows re-touched. Sum equals the size of the Steam response set. (Assert by pre-populating one Steam row and syncing a 3-game fixture that includes it → expect `{imported: 2, updated: 1}`.)
- [ ] DW-4.8: Empty-library public profile (`game_count:0`) returns 200 `{imported: 0, updated: 0}` and writes no rows.
- [ ] DW-4.9: User who fixes both privacy toggles and retries after the 60s `getPlayerSummary` cache window succeeds (integration test using `Cache::forget` or a time-advancing helper).

**Difficulty:** MEDIUM
**Uncertainty:** Whether MySQL `upsert()` behavior on Laravel 13 respects the `updated_at` column correctly, and whether the `Http::fake()` sequence expectations match Laravel 13's release notes. Mitigation: unit tests for both.

---

### Phase 5: Scheduler + React UI + doc updates
**Model:** sonnet
**Skills:** cc-routine-and-class-design, code-clarity-and-docs
**Gate:** Standard

**Goal:** Register the nightly `steam:sync-all` artisan command that iterates connected users; wire the React dashboard UI (Connect-Steam button, Sync-now trigger, private-profile error card); close out Phase 2 by updating `DECISIONS.md` and `TROUBLESHOOTING.md` per the phase-close protocol.

**Scope:**
- IN: `SteamSyncAllCommand` artisan command using `SteamLibrarySynchronizer`; schedule registration in `bootstrap/app.php` (Laravel 13 idiom); React components for Steam-connect + sync trigger + private-profile error card; DECISIONS.md updates (OpenID choice, Redis caching, transactional sync); TROUBLESHOOTING.md updates (private profile + two toggles); README changelog entry.
- OUT: real-time sync progress WebSockets (Phase 7 stretch).

**Constraints:** the scheduled command runs on the scheduler container (already exists from Phase 0 — no new Docker changes). Command must swallow per-user exceptions and continue (one user's broken sync doesn't block the batch), logging via `Log::warning()` for observability. Nightly cadence: `->daily()` at Laravel's default hour (03:00 server time — acceptable for a portfolio). React UI must render the private-profile error card as a modal or inline banner surfacing BOTH Steam privacy toggles by name (Profile + Game Details) and link to the Steam privacy settings URL.
**Edge cases:** command run with zero connected users → exits 0 quietly (log the count); a single user's sync throwing → log + continue to next user (do NOT abort batch); React user clicks "Sync now" while a sync is already in flight → button disabled during pending request; Steam-connect callback returns `?steam_error=steam_profile_private` → dashboard renders the same error card the API-triggered path uses (single UI component).
**Depends on:** Phase 4 | **Unlocks:** none (terminal phase)
**File scope:** `app/Console/Commands/SteamSyncAllCommand.php`, `bootstrap/app.php`, `client/src/**`, `docs/DECISIONS.md`, `docs/TROUBLESHOOTING.md`, `README.md`, `tests/Feature/Steam/SyncAllCommandTest.php`
**Produces:**
- `php artisan steam:sync-all` — iterates users with non-null `steam_id`, calls `SteamLibrarySynchronizer::sync($user)` per user, logs per-user outcomes; exit code 0.
- Nightly schedule registered in `bootstrap/app.php` via `->command('steam:sync-all')->daily()`.
- React `<SteamConnect />` component with Connect + Sync buttons wired to the three Phase 3/4 endpoints.
- React `<SteamPrivateProfileError />` component surfacing both Steam privacy toggles by name with the Steam privacy URL link.
- `DECISIONS.md` entries: OpenID hand-roll choice, Redis 1h caching rationale (rate-limit protection), DB-transaction wrapping rationale, scheduler cadence.
- `TROUBLESHOOTING.md` entry: private-profile error with both toggle names + screenshot placeholder.
- `README.md` sprint changelog entry: `[Sprint 2b] Steam OpenID + Web API integration`.

**Approach notes:** Laravel 13 registers scheduled commands in `bootstrap/app.php` under `withSchedule()` — do NOT create a legacy `app/Console/Kernel.php`. The React components use the existing Axios instance from Phase 1 (already carries `withCredentials: true`).
**File hints:** `bootstrap/app.php` — Laravel 13 schedule registration idiom. `client/src/pages/Dashboard.jsx` (or existing dashboard location) — mount the new components. `docs/DECISIONS.md` — ADR-style entries with Date/Decision/Rationale/Alternatives/Consequences.

**Done when:**
- [ ] DW-5.1: `php artisan steam:sync-all` runs cleanly with 0 users (exits 0, logs count).
- [ ] DW-5.2: Command with 3 connected users invokes `SteamLibrarySynchronizer::sync` 3 times and continues past a mid-batch exception (feature test with a partially-failing fake).
- [ ] DW-5.3: `bootstrap/app.php` registers `steam:sync-all` on a daily schedule (assert via `Schedule::events()`).
- [ ] DW-5.4: React dashboard renders a "Connect Steam" button that redirects to `/api/steam/login` when the user has no `steam_id`, or "Sync now" when they do (component test or manual verification).
- [ ] DW-5.5: React `<SteamPrivateProfileError />` mentions both "Profile" and "Game Details" toggles by name and links to the Steam privacy settings URL.
- [ ] DW-5.6: `DECISIONS.md` contains four new entries (OpenID hand-roll, Redis caching, transactional sync, scheduler cadence) in the required Date/Decision/Rationale/Alternatives/Consequences format.
- [ ] DW-5.7: `TROUBLESHOOTING.md` contains a "Steam private-profile 422" entry naming both toggles.
- [ ] DW-5.8: `README.md` sprint changelog gains a `[Sprint 2b]` entry.

**Difficulty:** MEDIUM
**Uncertainty:** React file layout — the existing `client/src/**` may already carry a dashboard page structure that dictates where to mount. Mitigation: the build agent scans `client/src/` first before deciding component placement.

---
## Test Coverage
**Level:** 100%

## Test Plan

**Integration — Phase 1 (schema):**
- [ ] DW-1.1: Migration up/down cycle preserves existing user data (both column additions AND composite index).
- [ ] DW-1.2: `config('services.steam.api_key')` returns the env value.
- [ ] DW-1.3: `.env.example` documents `STEAM_API_KEY` (inspection assertion).
- [ ] DW-1.4: Unique `users.steam_id` index rejects duplicate insert (dirty).
- [ ] DW-1.5: `User::$fillable` does NOT include `steam_id` (mass-assignment guard, dirty).
- [ ] DW-1.6: Composite unique index on `games(user_id, steam_app_id)` rejects duplicate Steam-source insert but permits multiple null-appid manual rows for the same user (dirty + boundary).

**Unit — Phase 2 (`SteamClient`):**
- [ ] DW-2.1: `getOwnedGames` normalizes Steam response into the documented Collection shape with cover URLs (Http::fake).
- [ ] DW-2.2: Cached second call skips the HTTP request (Http::assertSentCount(1)).
- [ ] DW-2.3: Cache key is deterministic, hashed, and timestamp-free.
- [ ] DW-2.4: `resolveVanityUrl` returns null on `success != 1`.
- [ ] DW-2.5: `getPlayerSummary` surfaces `communityvisibilitystate`.
- [ ] DW-2.6a: Malformed JSON on `getOwnedGames` throws `SteamApiException` (dirty).
- [ ] DW-2.6b: Non-200 response on `getOwnedGames` throws `SteamApiException` (dirty).
- [ ] DW-2.7: Missing `games` key (private Game Details) throws `SteamPrivateLibraryException` — distinct from empty `game_count:0` which returns empty Collection (boundary + dirty).
- [ ] DW-2.8: Invalid vanity input (fails regex) returns null without hitting Steam (dirty).
- [ ] DW-2.9: `getPlayerSummary` cache TTL is 60s (boundary — 60s vs 3600s).
- [ ] DW-2.10a: Missing `img_icon_url` on a game row produces null `cover_url` without dropping the row (edge).
- [ ] DW-2.10b: Missing `playtime_forever` defaults to 0 minutes (edge).
- [ ] DW-2.11: Full profile URL (`https://steamcommunity.com/id/handle/`) vanity input is normalized to handle before validation (edge).

**Unit — Phase 3 (`SteamOpenIdVerifier`):**
- [ ] DW-3.3: Happy path with valid signed params + `is_valid:true` returns SteamID64.
- [ ] DW-3.4a: `openid.ns` wrong or missing returns null (dirty).
- [ ] DW-3.4b: `openid.mode != id_res` at input returns null (dirty).
- [ ] DW-3.4c: `openid.op_endpoint` wrong or missing returns null (dirty).
- [ ] DW-3.4d: `openid.return_to` not matching registered callback returns null (dirty).
- [ ] DW-3.4e: Steam `check_authentication` returning `is_valid:false` returns null (dirty).
- [ ] DW-3.4f: `openid.claimed_id` not matching SteamID64 regex returns null (dirty).
- [ ] DW-3.4b (SSRF): `check_authentication` POST always targets the hard-coded Steam URL regardless of `openid.op_endpoint` (security invariant).

**Feature — Phase 3 (auth flow):**
- [ ] DW-3.1: Guest hits `/api/steam/login` → 401 (dirty; auth boundary).
- [ ] DW-3.2: Authenticated user hits `/api/steam/login` → 302 to Steam with correct `return_to` and `realm`.
- [ ] DW-3.6: Callback with verifier-null → 302 `/dashboard?steam_error=steam_openid_verification_failed`; no DB write.
- [ ] DW-3.5: Callback with verifier-success → 302 `/dashboard?steam_connected=1`; DB row updated.
- [ ] DW-3.7: `connect-vanity` happy path → 200 + DB write.
- [ ] DW-3.8: `connect-vanity` unresolvable → 422 `steam_vanity_unresolved`; no DB write (dirty).
- [ ] DW-3.9a: Callback rejects if SteamID64 already claimed by another user (dirty; IDOR-flavored).
- [ ] DW-3.9b: `connect-vanity` rejects if SteamID64 already claimed by another user (dirty).

**Feature — Phase 4 (sync):**
- [ ] DW-4.1: Sync on user with `steam_id=null` → 409 `steam_not_connected`; Steam not called (dirty).
- [ ] DW-4.2: Sync on private profile (visibility=1) → 422 `steam_profile_private` with two-toggle help object (dirty).
- [ ] DW-4.2b: Sync on public profile but private Game Details → same 422 shape (dirty).
- [ ] DW-4.3: Fresh sync creates 3 rows with `source='steam'`, `metadata_status='pending'`, cover URLs populated.
- [ ] DW-4.4: Re-sync updates `playtime_minutes` but preserves user-edited `status`, `genre`, `platform`.
- [ ] DW-4.5: Mid-batch exception rolls back all writes (dirty; transactional invariant).
- [ ] DW-4.6: Manual-source game with matching title but no `steam_app_id` NOT overwritten; new Steam row created.
- [ ] DW-4.7: `{imported: 2, updated: 1}` on a 3-game fixture where 1 game already exists as Steam-source (boundary count invariant).
- [ ] DW-4.8: Empty-library public profile (`game_count:0`) → 200 `{imported: 0, updated: 0}`; no rows.
- [ ] DW-4.9: After 60s cache window elapses (or `Cache::forget`), user who fixed toggles gets successful sync on retry (recovery flow).
- [ ] Manually-deleted Steam game re-appears in sync response → row is re-created (Steam authoritative; edge).

**Feature — Phase 5 (scheduler + backend):**
- [ ] DW-5.1: `steam:sync-all` with 0 users exits 0 (edge).
- [ ] DW-5.2: `steam:sync-all` with mixed pass/fail users continues past exceptions (dirty; batch resilience).
- [ ] DW-5.3: Schedule registration asserted via `Schedule::events()`.

**Manual — Phase 5 (React + docs):**
- [ ] DW-5.4: Dashboard shows "Connect Steam" for unconnected users; "Sync now" for connected.
- [ ] DW-5.5: Private-profile error card names both toggles by name and links to Steam privacy settings.
- [ ] DW-5.6: `DECISIONS.md` contains four new entries (OpenID hand-roll, Redis caching, transactional sync, scheduler cadence) — visual inspection during phase-close review.
- [ ] DW-5.7: `TROUBLESHOOTING.md` contains the private-profile 422 entry — inspection.
- [ ] DW-5.8: `README.md` sprint changelog gains `[Sprint 2b]` entry — inspection.
- [ ] `?steam_connected=1` after callback surfaces a success toast on dashboard mount (UX flow).

---
## Assumptions
| Assumption | Confidence | Verify Before Phase | Fallback If Wrong |
|---|---|---|---|
| Steam Web API key can be registered at `steamcommunity.com/dev` for free with an existing Steam account. | HIGH | Phase 1 | Delay Phase 3+; use vanity-only flow until the key is in hand. |
| Steam OpenID `check_authentication` mode still returns `is_valid:true` (protocol unchanged since 2011). | HIGH | Phase 3 | Fall back to xPaw/SteamOpenID composer package. |
| Existing `client/src/` React tree has a dashboard component to mount the Steam UI into. | MEDIUM | Phase 5 | Create a new dashboard section; note in README. |
| Laravel 13 `Http::fake()` API matches the Laravel 12/13 fake conventions the tests assume. | HIGH | Phase 2 | Adjust test fixtures per Laravel 13 `Http::` release notes. |
| MySQL 8 `upsert()` respects `updated_at` correctly on the update path. | MEDIUM | Phase 4 | Fall back to explicit `updateOrCreate` per row (slower but predictable). |
| Steam Web API returns `GetOwnedGames` with no `games` key (rather than `game_count:0`) when the *Game Details* privacy toggle is set to Private on an otherwise-public profile. | MEDIUM | Phase 2 | If detection heuristic fails in practice, fall back to a titles-count sanity check: if `game_count` present but implausibly 0 despite public profile, still surface the two-toggle error card (softer signal, but no false-positive on a genuine empty library thanks to a small titles threshold). |
| MySQL allows multiple NULLs in a unique composite index — the `(user_id, steam_app_id)` unique index does not prevent multiple manual games with null appid for the same user. | HIGH | Phase 1 | Ship a partial unique index via raw SQL (`WHERE steam_app_id IS NOT NULL`) — MySQL 8 does not support this natively, so this fallback means dropping the constraint and enforcing dedup at the application layer inside the sync transaction. |

## Decision Log
| Decision | Alternatives Considered | Rationale | Phase |
|---|---|---|---|
| Hand-rolled OpenID verification (~50 lines) | `xPaw/SteamOpenID` composer package | Zero new deps, full walkthrough clarity in interview, security-critical code stays visible in the repo. | Phase 3 |
| Thin controllers + action classes + `SteamClient` boundary | Fat controllers with inline HTTP; single `SteamService` god-class | Sync logic re-used by scheduler + endpoint (no dup); `Http::fake()` seam clean at one class; OpenID crypto isolated for focused tests. | Phases 2-4 |
| One bundled PR (Phase 2b) | Split into 2b (auth) + 2c (sync) | Matches build-plan phase boundary; smaller number of merges; frontend + backend land together. | All |
| Include nightly scheduled sync | Ship on-demand only, add scheduler later | Scheduler container already exists from Phase 0 (no infra cost); nightly sync is the whole reason for the scheduler service; feature-complete Phase 2 story. | Phase 5 |
| Include React UI in scope | Backend-only, UI follow-up | Steam-connect is a demo-critical UX flow; backend without UI has no walkable story for the interview. | Phase 5 |

---
## Notes
- The nightly cadence at Laravel's default `->daily()` hour (03:00) is fine for a portfolio; production would offset per-user to avoid Steam API bursts.
- No Redis eviction policy tuning here — 1h TTL on owned-games / 60s on player summary means natural churn.
- `metadata_status='pending'` is written but not consumed until Phase 4 of the build plan (PCGamingWiki ingestion) — this plan does NOT flip it to `ok`/`missing`.
- The React `?steam_connected=1` / `?steam_error=<code>` query-string pattern is the simplest post-redirect flash mechanism; a future improvement is a proper flash-message session, but that's out of scope.
- Cover-URL nit: Steam's `img_icon_url` produces a 32×32 icon, not a marketing cover. The column is named `cover_url` per the Phase 2 CRUD schema; UI copy should use "icon" language during the demo walkthrough. Not a code change — just a demo-narrative note.

---
## Execution Log
_To be filled during /code-foundations:build_
