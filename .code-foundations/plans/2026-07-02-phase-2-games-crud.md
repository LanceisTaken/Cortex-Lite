# Plan: Phase 2 — Games library manual CRUD (backend + React UI)
**Created:** 2026-07-02
**Status:** draft
**Complexity:** simple

---

## Context

Cortex Lite Phase 2 needs a user-scoped `games` library: users manually add, browse, filter, edit, and delete games via a paginated React library page backed by a REST API. Steam OpenID + auto-import ships in a separate follow-up plan; this plan delivers **only** the schema, manual CRUD API, and the React library page — but the schema is designed forward-compatible with the Steam sync that comes next.

The v4 build plan (`docs/cortex-lite-build-plan.md`, Phase 2 → Database & manual CRUD) is the source of scope. Phase 1 shipped Sanctum SPA cookie auth with a React SPA; that stack is inherited unchanged (Axios `withCredentials`, XSRF interceptor, `oxlint` on the client, PHPUnit on the backend).

## Constraints

- Every endpoint sits behind `auth:sanctum`; every query is scoped to `$request->user()`; cross-user IDs return **404 (not 403)** — don't confirm existence.
- Branch `Phase-2`; commits tagged `[Sprint 2] <verb> <what>`; all commands via Makefile (`make artisan CMD="..."`, `make composer CMD="..."`, `make test`) — never raw `docker exec` or `php artisan`.
- Existing 34 feature + 6 unit tests must stay green in CI. Delete-account cascade covers `games` too (existing test may need to assert this).
- **Schema forward-compat with Steam sync (next plan):** `steam_app_id` nullable, `source` enum `manual|steam` default `manual`, `metadata_status` enum `pending|ok|missing` default `missing`, `cover_url` nullable — present from day one so Steam sync is a code change, not a migration.
- **Playtime unit:** column `playtime_minutes` (int) — matches Steam's `playtime_forever` (minutes). Slight deviation from the v4 plan's `hours_played` name; documented in `DECISIONS.md` this phase.
- **List controls (MVP):** status filter, title search (`LIKE %q%`), sort selector — `last_played_at desc` (default), `title asc`, `playtime_minutes desc`. Platform/genre filters deferred.
- Testing rule 3 in CLAUDE.md: **authorization-boundary + IDOR test on every endpoint.**
- `oxlint` clean on the client. No lint disables.

---

## Implementation Phases

### Phase 1: Games backend — schema, model, API, tests

**Skills:** code-foundations:cc-routine-and-class-design, code-foundations:cc-defensive-programming, code-foundations:cc-quality-practices
**Model:** sonnet
**Gate:** Standard
**Depends on:** none
**File scope:** `database/migrations/*_create_games_table.php`, `database/factories/GameFactory.php`, `app/Models/{User,Game}.php`, `app/Http/Controllers/GameController.php`, `app/Http/Requests/Games/**`, `routes/api.php`, `tests/Feature/Games/**`, `docs/DECISIONS.md`
**Security-sensitive:** yes

**Goal:** Ship the `games` migration, `Game` Eloquent model, four REST endpoints under `/api/games` with FormRequest validation, and PHPUnit feature tests covering happy paths, validation failures, guest 401, mass-assignment protection, authorization boundaries, and IDOR — all following the Phase-1 conventions catalogued in `docs/code-standards.md`.

**Scope:**
- **IN:** Migration, factory, `Game` model with `#[Fillable]`/`#[Hidden]` attributes + `casts()`, `User::games()` HasMany, `GameController` with `index/store/update/destroy`, `StoreGameRequest` + `UpdateGameRequest`, route registrations in `routes/api.php`, feature tests under `tests/Feature/Games/`, three `DECISIONS.md` entries.
- **OUT:** Steam OpenID / `SteamClient` service / `/api/steam/*` routes / Steam sync scheduler / `getOwnedGames` / private-profile handling (all in next plan). No React changes (Phase 2 of this plan). No `show` endpoint. No cover-art fetching. No pagination cursor style (offset pagination via Eloquent's `->paginate(15)` is fine).

**Edge cases:**
- Empty library → `GET /api/games` returns `{ data: [], meta: {..., total: 0} }`, **not** 404.
- Search containing SQL wildcards (`%`, `_`, `\`) → escape before feeding into `LIKE`; verify via test that a title `"50%_off"` matches only itself, not everything.
- Enum casing: request body sending `status: "PLAYING"` (uppercase) → 422 (contract is case-sensitive lowercase). Documented in `StoreGameRequest`.
- `steam_app_id` set on a `source=manual` record → allowed (user annotating a Steam id for a manual entry); no cross-column validation.
- `playtime_minutes` = 0 default for new backlog entries.
- Cross-user `PUT`/`DELETE` `/api/games/{id}` → 404 via user-scoped route-model binding, **never** 403 (don't confirm existence).
- Mass-assignment: request body containing `user_id` or `id` must not overwrite the authed user's ownership — enforced by `#[Fillable]` on the `Game` model.
- Delete-account cascade: extend the existing `DeleteAccountTest` to assert the user's games are also gone (FK `onDelete('cascade')`).

**Produces:**
- Route contract (Phase 2 consumes this):
  - `GET /api/games?status=&search=&sort=&page=` → `200 { data: Game[], meta: { current_page, last_page, per_page, total } }`. Valid `sort`: `last_played_desc` (default) | `title_asc` | `playtime_desc`. Valid `status`: `playing|backlog|completed|dropped` (or omit for all).
  - `POST /api/games { title, platform?, genre?, status, playtime_minutes?, last_played_at?, steam_app_id?, cover_url? }` → `201 Game` on success, `422 { errors: {field: [msg]} }` on validation fail.
  - `PUT /api/games/{game} { partial fields }` → `200 Game` on success, `404` for cross-user id, `422` on validation fail.
  - `DELETE /api/games/{game}` → `204` on success, `404` for cross-user id.
  - `Game` JSON shape: `{ id, title, platform, genre, status, playtime_minutes, last_played_at, steam_app_id, source, metadata_status, cover_url, created_at, updated_at }` (`user_id` hidden).
- Route names: `games.index`, `games.store`, `games.update`, `games.destroy`.
- `Game` Eloquent model + `User::games()` relationship consumable by later phases (Phase 3 sessions, Phase 5 recommender).

**Done when:**
- [ ] DW-1.1: Migration `create_games_table` shipped with `up()` + `down()`; columns per spec; FK `user_id` cascade-deletes; indices on `(user_id, status)` and `(user_id, last_played_at)`; enum columns use `enum('playing','backlog','completed','dropped')` / `enum('manual','steam')` / `enum('pending','ok','missing')`.
- [ ] DW-1.2: `App\Models\Game` shipped using `#[Fillable]`/`#[Hidden]` attributes (no `$fillable`/`$hidden` array properties); `casts()` returns `['last_played_at' => 'datetime', 'playtime_minutes' => 'integer']`; `User::games()` HasMany relationship added.
- [ ] DW-1.3: `App\Http\Requests\Games\StoreGameRequest` + `UpdateGameRequest` shipped with `rules()` covering title (required on Store, sometimes on Update), status (in the 4-enum list), playtime_minutes (integer, min:0), last_played_at (nullable date), steam_app_id (nullable integer), cover_url (nullable url). **`source` is not accepted in either payload** — the controller sets `source = 'manual'` server-side on Store; Update never mutates it. `source` sent in a request body is silently ignored (not present in `validated()`) and is asserted so via a dirty test.
- [ ] DW-1.4: `App\Http\Controllers\GameController` shipped with `index`, `store`, `update`, `destroy`; every method under `auth:sanctum`; every query starts from `$request->user()->games()`; `update`/`destroy` use user-scoped route-model binding (or explicit `firstOrFail()` on the user-scoped query); response projections use `->only(...)` or a `GameResource` to keep `user_id` out.
- [ ] DW-1.5: `routes/api.php` gets `Route::apiResource('games', GameController::class)->except(['show'])` (or four explicit `Route::` lines matching the Phase-1 style) under `auth:sanctum`; every route has `->name('games.<action>')`.
- [ ] DW-1.6: Feature tests under `tests/Feature/Games/`: `GamesIndexTest` (guest 401, own-only, status filter, title search, sort options, wildcard-escape, **response projection hides `user_id`**), `GamesStoreTest` (guest 401, happy 201, validation 422, mass-assignment guard on `user_id`/`id`, **`source` in body ignored — server-set to `manual`**), `GamesUpdateTest` (guest 401, happy 200, IDOR 404, validation 422, **`source` in body ignored — never mutated**), `GamesDestroyTest` (guest 401, happy 204, IDOR 404). Minimum 14 feature tests total, each using `RefreshDatabase`, `postJson`/`getJson`/`putJson`/`deleteJson`, factory-first fixtures.
- [ ] DW-1.7: `docs/DECISIONS.md` gets three new dated entries: (a) `playtime_minutes` unit choice over v4 plan's `hours_played`; (b) forward-compat columns `source`/`metadata_status`/`steam_app_id`/`cover_url` rationale; (c) 404-vs-403 IDOR response choice (don't confirm existence).
- [ ] DW-1.8: Existing `DeleteAccountTest` extended (or a new test added) to assert the user's `games` rows are gone after `DELETE /api/account` (verifies FK cascade in production DB, not just schema).
- [ ] DW-1.9: `make test` all green — existing 34 feature + 6 unit tests + new ~14 games feature tests.

**Rollback:** Migration `down()` drops the `games` table cleanly. Tests are the safety net; no other rollback needed.

---

### Phase 2: React library page + form flows

**Skills:** code-foundations:cc-routine-and-class-design, code-foundations:code-clarity-and-docs
**Model:** sonnet
**Gate:** Standard
**Depends on:** Phase 1
**File scope:** `client/src/pages/Library.jsx`, `client/src/pages/Dashboard.jsx`, `client/src/components/games/**`, `client/src/lib/games.js`, `client/src/App.jsx`, `README.md`, `CLAUDE.md`, `docs/TROUBLESHOOTING.md`

**Goal:** Ship the protected `/library` route: paginated list of the authed user's games, status filter, title search (debounced), sort selector, add/edit/delete modal flows — all wired through the same 422 / 429 / generic error branching as `client/src/pages/Login.jsx`, using primitives from `client/src/components/ui/`.

**Scope:**
- **IN:** New `Library.jsx` page behind `<ProtectedRoute>`; game-list rendering with formatted `playtime_minutes` as `"Xh Ym"`; `LibraryFilters.jsx` (status + search + sort controls with ~300ms debounce on search); `GameFormModal.jsx` (create + edit share one modal, dispatched by presence of an `initialGame` prop); `DeleteGameModal.jsx` with explicit confirm; `client/src/lib/games.js` API helpers; add `<Link to="/library">` to `Dashboard.jsx` nav.
- **OUT:** Any backend changes (Phase 1's contract is frozen). Cover-art rendering (column exists but always null for manual entries — placeholder box for now). Bulk actions. Drag-to-reorder. Any Phase-3 session UI. Pagination-cursor infinite scroll (use numbered pages).

**Edge cases:**
- Search debounce: user types fast → in-flight request is cancelled (via `AbortController`) so results don't flash the wrong content.
- Empty library → empty-state card with a primary "Add your first game" button that opens the create modal.
- Pagination beyond last page → API returns `{ data: [] }`; UI shows "No games match" and a "Reset filters" button.
- 401 mid-session (session expired) → the existing Axios flow surfaces the 401; `AuthContext.refresh()` on next tick clears user, `<ProtectedRoute>` redirects to `/login`.
- 422 on Store/Update → per-field errors mapped into the modal form (mirrors `Login.jsx` at line ~40).
- Rapid create-then-list → pessimistic UI: wait for the 201, then re-fetch the current page rather than optimistic-append (simpler, correct under filter/sort).
- Concurrent edit across tabs → last-write-wins is acceptable for MVP; not surfaced.
- Delete confirm: the confirm button is disabled until the user types the game title into the confirm field (or checks a checkbox — pick the lightest that still guards misclicks).

**Produces:** nothing downstream (last phase of this plan).

**Done when:**
- [ ] DW-2.1: `client/src/App.jsx` gets a `/library` route wrapped in `<ProtectedRoute>` rendering `<Library />`; matches the shape of `/dashboard` and `/account`.
- [ ] DW-2.2: `client/src/lib/games.js` exposes `listGames({status, search, sort, page, signal})`, `createGame(payload)`, `updateGame(id, payload)`, `deleteGame(id)` — all built on the shared `api` axios instance from `lib/api.js` (no second axios instance).
- [ ] DW-2.3: `Library.jsx` renders the paginated list with each row showing title, platform, genre, status badge, formatted `playtime_minutes` as `"Xh Ym"` (or `"—"` when 0), and add/edit/delete buttons; shows a loading state on first fetch and an error banner on failure.
- [ ] DW-2.4: `LibraryFilters.jsx` provides the status dropdown, debounced (~300ms) title search input, and sort selector; changes reset the page to 1; component lifts state via callback props (matches the AuthContext consumer pattern).
- [ ] DW-2.5: `GameFormModal.jsx` handles both create (empty initial state) and edit (populated from prop) modes; submit branches on 422 to per-field errors and any other status to a generic form error — reuses `<Input>`, `<Button>`, `<FormError>` from `components/ui/`.
- [ ] DW-2.6: `DeleteGameModal.jsx` requires an explicit confirm action (disabled submit until the user commits to it); shows the target game's title; disables the button while busy; closes on success.
- [ ] DW-2.7: `Dashboard.jsx` gets a `<Link to="/library">Library</Link>` in the header nav; no other Dashboard changes.
- [ ] DW-2.8: `npx oxlint` (or `cd client && npm run lint`) clean — no new warnings versus main; no lint disables added.
- [ ] DW-2.9: Manual verification via `make up` + `cd client && npm run dev`: register a fresh user → open `/library` → empty state renders → create 3 games with distinct statuses → filter narrows list → search narrows further → sort reorders → edit updates in place → delete removes with confirm. Filter/search/sort state resets on navigation away from `/library` — URL sync via `useSearchParams` is a follow-up, not required here.
- [ ] DW-2.10: Phase-close docs updates in one final commit: `README.md` sprint changelog gets a "Sprint 2 — games library CRUD" entry; `CLAUDE.md` phase tracker flips `[ ] Phase 2` → `[x] Phase 2 — Game library + Steam integration` (partial, note that Steam sub-phase is separate); `docs/TROUBLESHOOTING.md` gets any new failure modes surfaced during Phase 1 or Phase 2 build (or a "no new entries this sprint" one-liner if none arose).

**Rollback:** No production data touched; revert commits if the UI regresses.

---

## Test Coverage

**Level:** 100% for backend (Phase 1). Frontend (Phase 2) is manually verified in the browser + `oxlint`-checked; no React test suite exists in the repo yet and standing one up is out of scope.

## Test Plan

Backend (Phase 1) — new tests to add under `tests/Feature/Games/`:

- [ ] `test_guest_index_returns_401`
- [ ] `test_authenticated_index_returns_only_own_games` (creates A + B; asserts A sees only A's)
- [ ] `test_index_paginates_with_default_15_per_page`
- [ ] `test_index_filters_by_status`
- [ ] `test_index_searches_by_title_case_insensitive`
- [ ] `test_index_escapes_sql_wildcards_in_search` (dirty — the escape-`%_\` case)
- [ ] `test_index_sorts_by_last_played_desc_by_default`
- [ ] `test_index_sorts_by_title_asc_when_requested`
- [ ] `test_index_sorts_by_playtime_desc_when_requested`
- [ ] `test_index_rejects_invalid_sort_param_422` (dirty)
- [ ] `test_index_response_hides_user_id_field` (dirty — response projection contract)
- [ ] `test_guest_store_returns_401` (dirty)
- [ ] `test_authenticated_store_creates_game_201`
- [ ] `test_store_validation_rejects_missing_title_422` (dirty)
- [ ] `test_store_validation_rejects_invalid_status_422` (dirty — payload must send `PLAYING` uppercase or a bogus string like `"halfway"` so the case-sensitive lowercase-enum contract is exercised)
- [ ] `test_store_ignores_user_id_in_body` (dirty — mass-assignment guard)
- [ ] `test_store_ignores_id_in_body` (dirty — mass-assignment guard)
- [ ] `test_store_ignores_source_in_body_sets_manual` (dirty — source is server-set)
- [ ] `test_update_ignores_source_in_body` (dirty — source never mutated)
- [ ] `test_guest_update_returns_401` (dirty)
- [ ] `test_authenticated_update_returns_200`
- [ ] `test_update_cross_user_game_returns_404_not_403` (dirty — IDOR)
- [ ] `test_update_validation_rejects_bad_status_422` (dirty)
- [ ] `test_guest_destroy_returns_401` (dirty)
- [ ] `test_authenticated_destroy_returns_204`
- [ ] `test_destroy_cross_user_game_returns_404_not_403` (dirty — IDOR)
- [ ] `test_delete_account_cascades_games_via_fk` (extend or add to `DeleteAccountTest`)

Frontend (Phase 2) — manual smoke matrix in the browser:

- [ ] Library page: empty state → "Add your first game" flow → creates a game
- [ ] List renders with title / platform / genre / status badge / formatted playtime
- [ ] Status filter narrows; combined with search + sort works as expected
- [ ] Sort selector reorders (each of the three options)
- [ ] Edit modal pre-populates and PUT updates in place; 422 on empty title shows per-field error
- [ ] Delete confirm requires the guard action; 204 removes from list
- [ ] `oxlint` clean
- [ ] Navigate away and back — filter/search state is either preserved (URL sync) or explicitly reset (documented)

Regression floor for the whole plan: existing 34 feature + 6 unit tests remain green after every commit on `Phase-2`.

---

## Notes

- **Route-model binding scoping:** the cleanest way to make cross-user IDs 404 automatically is to declare the `{game}` binding on the group with `Route::scopeBindings()` and use `$request->user()->games()->findOrFail($game->id)` inside the controller — or Laravel 11+ nested `Route::apiResource('users.games')->scoped()`. Either works; the flat `apiResource('games')` + explicit `$request->user()->games()->findOrFail(...)` in the controller is the most obvious pattern and matches the Phase-1 style. Pick that unless the build agent surfaces a reason to change.
- **Response projection:** either use `$game->only([...])` (matches `RegisterController`) or introduce a `GameResource` (Laravel API Resources). `->only()` is simpler for a first pass and matches Phase-1; upgrade to a Resource in a later phase if we start returning nested relations.
- **Search escape:** `str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $search)` before feeding into `where('title', 'LIKE', "%{$q}%")`. Dirty test locked in DW-1.6.
- **Enum storage:** MySQL native `enum` column is fine (no plans to add values from user input). Laravel doesn't need `casts()` for enum columns unless we introduce PHP enum classes — hold off for now; upgrade later if it helps type safety.
- **Delete-cascade test:** the current `DeleteAccountTest` should get a new test `test_delete_account_cascades_games` that creates 2 games for the user, deletes the account, and asserts `Game::where('user_id', $userId)->count() === 0`. This is the load-bearing FK assertion.
- **Debounce implementation (Phase 2):** a small `useDebouncedValue(value, delay)` hook inside `Library.jsx` is simpler than pulling in `lodash.debounce` — the codebase has no lodash. Same for `AbortController`, which is native.
- **URL sync for filters:** using `useSearchParams()` from `react-router-dom` v7 preserves filters through back/forward and lets the user share links. Small win; do it if the build agent finds it costs nothing extra, otherwise document as follow-up.
- **Docs decay:** update `README.md` sprint changelog and `CLAUDE.md` phase tracker (`[x] Phase 2`) as the last commit of the plan.

---

## Execution Log
_To be filled during /code-foundations:build_
