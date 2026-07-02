# Phase 3 — Session Tracking & History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the manual play-session lifecycle (start, end, active, history) on top of the existing games library — REST endpoints, race-safe "one active session per user" enforcement, transactional playtime aggregation for manual games, and the React UI (Start button, persistent in-progress banner, History page).

**Architecture:** New `play_sessions` table (`sessions` is already occupied by Laravel's HTTP session store, so we namespace the domain table). Race safety on start via `DB::transaction` + `User::lockForUpdate()` (portable across MySQL 8 and SQLite-in-tests — a partial unique index is not portable). End-session is transactional and only touches `games.playtime_minutes` for manual-sourced games; Steam-sourced games stay authoritative-from-Steam, so re-sync never clobbers session-tracked minutes. Actions extracted to `App\Actions\PlaySessions\*` following the `DeleteAccountAction` pattern already in the repo.

**Tech Stack:** Laravel 13, PHP 8.4, Sanctum SPA cookie auth (already wired in Phase 1), MySQL 8 (dev/prod) + SQLite in-memory (tests), React 19, Vite, Axios (existing `client/src/lib/api.js` with CSRF cookie flow), react-router-dom v6, Tailwind v4.

## Global Constraints

- Branch: `Phase-3` off `main` (or current `Phase-2` tip if `main` hasn't yet been fast-forwarded — verify with `git log --oneline -5 main` before branching).
- Commit trailer style: `[Sprint 3] <what>` on every commit in this branch. No `-i`. No `--no-verify`.
- All commands run via project Makefile: `make artisan CMD="..."`, `make composer CMD="..."`, `make test`, `make shell`. Never raw `php artisan` / `docker exec`.
- Do NOT rename `games.playtime_minutes` — spec says `hours_played`, but the schema already ships in Phase 2 as minutes. Keep minutes. Session `duration_seconds` is source of truth; game `playtime_minutes` is the cached aggregate.
- **Playtime aggregation rule (load-bearing):** on session end, increment `games.playtime_minutes` **only if `games.source = 'manual'`**. Steam-sourced games are authoritative from Steam's `playtime_forever`; the daily `steam:sync-all` upsert (see `app/Services/SteamLibrarySynchronizer.php:65`) would clobber any locally-added minutes anyway, and double-counting is worse than no-op.
- Table name is `play_sessions`, model is `App\Models\PlaySession`. URL-facing paths stay `/api/sessions/*` per the build-plan spec — the internal name change is invisible to clients.
- All new endpoints under `auth:sanctum` middleware. All error responses are structured JSON with an `error_code` string (matches the Steam-controller convention in `app/Http/Controllers/SteamSyncController.php:29`).
- All feature tests use `RefreshDatabase` and Laravel factories. Match the existing test-naming pattern: `test_<subject>_<condition>_<outcome>`.
- Mass-assignment guard: `PlaySession` exposes ONLY `game_id` and timestamps via `#[Fillable]`; `user_id` is hidden. Never accept `user_id` from the request body.
- Test-count target: 20 feature + 2 unit new. Total suite green in CI.
- CI is `.github/workflows/ci.yml` running `php artisan test` — pushes to any branch trigger it.

---

### Task 1: Schema, model, factory, relationships

**Files:**
- Create: `database/migrations/2026_07_02_150000_create_play_sessions_table.php`
- Create: `app/Models/PlaySession.php`
- Create: `database/factories/PlaySessionFactory.php`
- Modify: `app/Models/User.php` (add `playSessions()` relation)
- Modify: `app/Models/Game.php` (add `playSessions()` relation)
- Test: `tests/Feature/PlaySessions/PlaySessionSchemaTest.php`

**Interfaces:**
- Consumes: `App\Models\User` and `App\Models\Game` (from Phase 1/2).
- Produces:
  - Table `play_sessions` with columns `id, user_id (FK cascade), game_id (FK cascade), started_at (not null), ended_at (nullable), duration_seconds (unsigned int, nullable — set on end), created_at, updated_at`.
  - Indexes `(user_id, ended_at)` and `(user_id, started_at desc)` for the "active session" and "history" queries.
  - `App\Models\PlaySession` with `user(): BelongsTo`, `game(): BelongsTo`. `#[Fillable(['game_id', 'started_at', 'ended_at', 'duration_seconds'])]`, `#[Hidden(['user_id'])]`. `RESPONSE_FIELDS = ['id', 'game_id', 'started_at', 'ended_at', 'duration_seconds', 'created_at', 'updated_at']`. Casts: `started_at`, `ended_at` → `datetime`; `duration_seconds` → `integer`.
  - `PlaySessionFactory` producing a completed session for a random `User::factory()` + `Game::factory()` for that user.
  - `User::playSessions(): HasMany` and `Game::playSessions(): HasMany`.

- [ ] **Step 1: Write the schema test (failing)**

Create `tests/Feature/PlaySessions/PlaySessionSchemaTest.php`:

```php
<?php

namespace Tests\Feature\PlaySessions;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlaySessionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_play_sessions_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('play_sessions'));
        $this->assertTrue(Schema::hasColumns('play_sessions', [
            'id', 'user_id', 'game_id',
            'started_at', 'ended_at', 'duration_seconds',
            'created_at', 'updated_at',
        ]));
    }

    public function test_user_has_many_play_sessions(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        PlaySession::factory()->for($user)->for($game)->create();
        PlaySession::factory()->for($user)->for($game)->create();

        $this->assertCount(2, $user->fresh()->playSessions);
    }

    public function test_game_has_many_play_sessions(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        PlaySession::factory()->for($user)->for($game)->create();

        $this->assertCount(1, $game->fresh()->playSessions);
    }

    public function test_deleting_user_cascades_play_sessions(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        $session = PlaySession::factory()->for($user)->for($game)->create();

        $user->delete();

        $this->assertDatabaseMissing('play_sessions', ['id' => $session->id]);
    }
}
```

- [ ] **Step 2: Run the test — expect fail on missing table**

Run: `make test -- --filter=PlaySessionSchemaTest`
Expected: FAIL — "Base table or view not found: 'play_sessions'".

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_02_150000_create_play_sessions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('play_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ended_at']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_sessions');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/PlaySession.php`:

```php
<?php

namespace App\Models;

use Database\Factories\PlaySessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['game_id', 'started_at', 'ended_at', 'duration_seconds'])]
#[Hidden(['user_id'])]
class PlaySession extends Model
{
    /** @use HasFactory<PlaySessionFactory> */
    use HasFactory;

    public const RESPONSE_FIELDS = [
        'id',
        'game_id',
        'started_at',
        'ended_at',
        'duration_seconds',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/PlaySessionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaySession>
 */
class PlaySessionFactory extends Factory
{
    public function definition(): array
    {
        $started = fake()->dateTimeBetween('-30 days', '-1 hour');
        $duration = fake()->numberBetween(60, 4 * 3600);
        $ended = (clone $started)->modify("+{$duration} seconds");

        return [
            'user_id' => User::factory(),
            'game_id' => Game::factory(),
            'started_at' => $started,
            'ended_at' => $ended,
            'duration_seconds' => $duration,
        ];
    }

    public function active(): self
    {
        return $this->state(fn () => [
            'started_at' => now()->subMinutes(5),
            'ended_at' => null,
            'duration_seconds' => null,
        ]);
    }
}
```

- [ ] **Step 6: Add relations to `User` and `Game`**

Edit `app/Models/User.php` — add import and method after `games()`:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
// ...

public function playSessions(): HasMany
{
    return $this->hasMany(PlaySession::class);
}
```

Edit `app/Models/Game.php` — add import and method after `user()`:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
// ...

public function playSessions(): HasMany
{
    return $this->hasMany(PlaySession::class);
}
```

- [ ] **Step 7: Run the schema test — expect PASS**

Run: `make test -- --filter=PlaySessionSchemaTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_02_150000_create_play_sessions_table.php \
        app/Models/PlaySession.php \
        database/factories/PlaySessionFactory.php \
        app/Models/User.php \
        app/Models/Game.php \
        tests/Feature/PlaySessions/PlaySessionSchemaTest.php
git commit -m "[Sprint 3] add play_sessions schema, model, factory, relations"
```

---

### Task 2: `POST /api/sessions/start` — start a play session (race-safe, IDOR-safe)

**Files:**
- Create: `app/Http/Requests/PlaySessions/StartPlaySessionRequest.php`
- Create: `app/Actions/PlaySessions/StartPlaySessionAction.php`
- Create: `app/Exceptions/PlaySessionAlreadyActiveException.php`
- Create: `app/Http/Controllers/PlaySessionController.php` (with `start` method)
- Modify: `routes/api.php` (register `POST /api/sessions/start`)
- Test: `tests/Feature/PlaySessions/StartPlaySessionTest.php`

**Interfaces:**
- Consumes: `PlaySession` model, `Game` model (Phase 2), Sanctum auth (Phase 1).
- Produces:
  - `StartPlaySessionRequest::rules()` returning `['game_id' => ['required', 'integer', 'exists:games,id']]`. Note: `exists:games,id` is deliberately global — the IDOR check (does the game belong to this user) happens in the action, and returns 404 rather than 422 to avoid leaking existence info.
  - `StartPlaySessionAction::execute(User $user, int $gameId): PlaySession` — wraps everything in `DB::transaction`; locks `users` row via `lockForUpdate()`; verifies `$user->games()->find($gameId)` is not null (else `ModelNotFoundException`); checks no open session exists (else throws `PlaySessionAlreadyActiveException`); creates and returns the session with `started_at = now()`.
  - `PlaySessionAlreadyActiveException` — a domain exception; caught by the controller and rendered as 409 with `error_code: play_session_already_active`.
  - `POST /api/sessions/start` route (name `sessions.start`), throttle `30,1`. Returns 201 with `PlaySession::RESPONSE_FIELDS`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PlaySessions/StartPlaySessionTest.php`:

```php
<?php

namespace Tests\Feature\PlaySessions;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StartPlaySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_start_returns_401(): void
    {
        $this->postJson('/api/sessions/start', ['game_id' => 1])->assertStatus(401);
    }

    public function test_authenticated_start_creates_open_session_201(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', ['game_id' => $game->id])
            ->assertCreated()
            ->assertJsonPath('game_id', $game->id)
            ->assertJsonMissingPath('user_id')
            ->assertJsonPath('ended_at', null)
            ->assertJsonPath('duration_seconds', null);

        $this->assertDatabaseHas('play_sessions', [
            'user_id' => $user->id,
            'game_id' => $game->id,
            'ended_at' => null,
        ]);
    }

    public function test_start_missing_game_id_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('game_id');
    }

    public function test_start_with_nonexistent_game_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', ['game_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('game_id');
    }

    public function test_start_with_another_users_game_returns_404_idor(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $othersGame = Game::factory()->for($other)->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', ['game_id' => $othersGame->id])
            ->assertStatus(404);

        $this->assertDatabaseMissing('play_sessions', [
            'user_id' => $user->id,
            'game_id' => $othersGame->id,
        ]);
    }

    public function test_start_when_user_already_has_an_open_session_returns_409(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        PlaySession::factory()->for($user)->for($game)->active()->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', ['game_id' => $game->id])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'play_session_already_active');

        $this->assertSame(1, $user->fresh()->playSessions()->whereNull('ended_at')->count());
    }

    public function test_start_ignores_user_id_in_body(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $game = Game::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', [
                'game_id' => $game->id,
                'user_id' => $other->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('play_sessions', ['user_id' => $user->id, 'game_id' => $game->id]);
        $this->assertDatabaseMissing('play_sessions', ['user_id' => $other->id]);
    }
}
```

- [ ] **Step 2: Run and confirm all fail**

Run: `make test -- --filter=StartPlaySessionTest`
Expected: FAIL (route/controller do not exist yet).

- [ ] **Step 3: Write the domain exception**

Create `app/Exceptions/PlaySessionAlreadyActiveException.php`:

```php
<?php

namespace App\Exceptions;

use Exception;

class PlaySessionAlreadyActiveException extends Exception
{
}
```

- [ ] **Step 4: Write the FormRequest**

Create `app/Http/Requests/PlaySessions/StartPlaySessionRequest.php`:

```php
<?php

namespace App\Http\Requests\PlaySessions;

use Illuminate\Foundation\Http\FormRequest;

class StartPlaySessionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'game_id' => ['required', 'integer', 'exists:games,id'],
        ];
    }
}
```

- [ ] **Step 5: Write the action**

Create `app/Actions/PlaySessions/StartPlaySessionAction.php`:

```php
<?php

namespace App\Actions\PlaySessions;

use App\Exceptions\PlaySessionAlreadyActiveException;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class StartPlaySessionAction
{
    public function execute(User $user, int $gameId): PlaySession
    {
        return DB::transaction(function () use ($user, $gameId) {
            // Serialize concurrent "start" calls for this user by locking the
            // users row for the duration of the transaction. Portable across
            // MySQL 8 and SQLite (test DB). A partial unique index would be
            // cleaner but is not portable across both engines.
            User::whereKey($user->id)->lockForUpdate()->first();

            $game = $user->games()->whereKey($gameId)->first();
            if ($game === null) {
                throw new ModelNotFoundException();
            }

            if ($user->playSessions()->whereNull('ended_at')->exists()) {
                throw new PlaySessionAlreadyActiveException();
            }

            return $user->playSessions()->create([
                'game_id' => $game->id,
                'started_at' => now(),
            ]);
        });
    }
}
```

- [ ] **Step 6: Write the controller**

Create `app/Http/Controllers/PlaySessionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\PlaySessions\StartPlaySessionAction;
use App\Exceptions\PlaySessionAlreadyActiveException;
use App\Http\Requests\PlaySessions\StartPlaySessionRequest;
use App\Models\PlaySession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class PlaySessionController extends Controller
{
    public function start(StartPlaySessionRequest $request, StartPlaySessionAction $action): JsonResponse
    {
        try {
            $session = $action->execute($request->user(), (int) $request->validated('game_id'));
        } catch (ModelNotFoundException) {
            return response()->json(null, 404);
        } catch (PlaySessionAlreadyActiveException) {
            return response()->json([
                'error_code' => 'play_session_already_active',
                'message' => 'You already have an in-progress play session. End it before starting another.',
            ], 409);
        }

        return response()->json($session->only(PlaySession::RESPONSE_FIELDS), 201);
    }
}
```

- [ ] **Step 7: Register the route**

Edit `routes/api.php`. Add the import and, immediately after the `apiResource('games', ...)` block (around line 53), append:

```php
use App\Http\Controllers\PlaySessionController;
```

```php
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/sessions/start', [PlaySessionController::class, 'start'])
        ->middleware('throttle:30,1')
        ->name('sessions.start');
});
```

- [ ] **Step 8: Run and confirm all pass**

Run: `make test -- --filter=StartPlaySessionTest`
Expected: PASS (7 tests).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/PlaySessions/StartPlaySessionRequest.php \
        app/Actions/PlaySessions/StartPlaySessionAction.php \
        app/Exceptions/PlaySessionAlreadyActiveException.php \
        app/Http/Controllers/PlaySessionController.php \
        routes/api.php \
        tests/Feature/PlaySessions/StartPlaySessionTest.php
git commit -m "[Sprint 3] add POST /api/sessions/start with race-safe one-active-session enforcement"
```

---

### Task 3: `POST /api/sessions/{session}/end` — end a play session (transactional, manual-only playtime bump)

**Files:**
- Create: `app/Actions/PlaySessions/EndPlaySessionAction.php`
- Create: `app/Exceptions/PlaySessionAlreadyEndedException.php`
- Modify: `app/Http/Controllers/PlaySessionController.php` (add `end` method)
- Modify: `routes/api.php` (register `POST /api/sessions/{session}/end`)
- Test: `tests/Feature/PlaySessions/EndPlaySessionTest.php`

**Interfaces:**
- Consumes: `PlaySession` model (Task 1), controller class (Task 2).
- Produces:
  - `EndPlaySessionAction::execute(PlaySession $session): PlaySession` — assumes the caller has already verified ownership. Wraps in `DB::transaction`; locks the row via `lockForUpdate()`; refuses if `ended_at` is already set (throws `PlaySessionAlreadyEndedException`); computes `duration_seconds = $endedAt->diffInSeconds($startedAt)`; sets `ended_at`, `duration_seconds`; if the game is `source = 'manual'`, increments `games.playtime_minutes` by `intdiv($duration_seconds, 60)` AND sets `games.last_played_at = ended_at`; if `source = 'steam'`, only sets `games.last_played_at`. Returns the fresh session.
  - `PlaySessionAlreadyEndedException` — domain exception.
  - `POST /api/sessions/{session}/end` route (name `sessions.end`). Uses **route-model-binding** on the `session` parameter — the controller receives a hydrated `PlaySession`. Ownership check happens in the controller: `if ($session->user_id !== $request->user()->id) return 404;`. Throttle `30,1`. Returns 200 with `PlaySession::RESPONSE_FIELDS`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PlaySessions/EndPlaySessionTest.php`:

```php
<?php

namespace Tests\Feature\PlaySessions;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EndPlaySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_end_returns_401(): void
    {
        $this->postJson('/api/sessions/1/end')->assertStatus(401);
    }

    public function test_end_computes_duration_and_returns_200(): void
    {
        Carbon::setTestNow('2026-07-02 12:00:00');
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create(['source' => 'manual', 'playtime_minutes' => 0]);
        $session = PlaySession::factory()->for($user)->for($game)->active()->create([
            'started_at' => now()->subMinutes(45),
        ]);

        Carbon::setTestNow('2026-07-02 12:00:00');

        $this->actingAs($user)
            ->postJson("/api/sessions/{$session->id}/end")
            ->assertOk()
            ->assertJsonPath('id', $session->id)
            ->assertJsonPath('duration_seconds', 45 * 60)
            ->assertJsonMissingPath('user_id');

        $this->assertDatabaseHas('play_sessions', [
            'id' => $session->id,
            'duration_seconds' => 45 * 60,
        ]);
    }

    public function test_end_increments_playtime_minutes_for_manual_game(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create(['source' => 'manual', 'playtime_minutes' => 100]);
        $session = PlaySession::factory()->for($user)->for($game)->active()->create([
            'started_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($user)->postJson("/api/sessions/{$session->id}/end")->assertOk();

        $this->assertSame(130, $game->fresh()->playtime_minutes);
        $this->assertNotNull($game->fresh()->last_played_at);
    }

    public function test_end_does_not_increment_playtime_minutes_for_steam_game(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create([
            'source' => 'steam',
            'steam_app_id' => 620,
            'playtime_minutes' => 500,
        ]);
        $session = PlaySession::factory()->for($user)->for($game)->active()->create([
            'started_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($user)->postJson("/api/sessions/{$session->id}/end")->assertOk();

        // Steam is authoritative for Steam games; the daily sync would clobber
        // any local increment anyway. Session record still persists.
        $this->assertSame(500, $game->fresh()->playtime_minutes);
        $this->assertNotNull($game->fresh()->last_played_at);
        $this->assertNotNull(PlaySession::find($session->id)->ended_at);
    }

    public function test_end_with_another_users_session_returns_404_idor(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $othersGame = Game::factory()->for($other)->create();
        $othersSession = PlaySession::factory()->for($other)->for($othersGame)->active()->create();

        $this->actingAs($user)
            ->postJson("/api/sessions/{$othersSession->id}/end")
            ->assertStatus(404);

        $this->assertNull(PlaySession::find($othersSession->id)->ended_at);
    }

    public function test_ending_an_already_ended_session_returns_409(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        $session = PlaySession::factory()->for($user)->for($game)->create();

        $this->actingAs($user)
            ->postJson("/api/sessions/{$session->id}/end")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'play_session_already_ended');
    }

    public function test_ending_nonexistent_session_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/999999/end')
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run and confirm all fail**

Run: `make test -- --filter=EndPlaySessionTest`
Expected: FAIL.

- [ ] **Step 3: Write the domain exception**

Create `app/Exceptions/PlaySessionAlreadyEndedException.php`:

```php
<?php

namespace App\Exceptions;

use Exception;

class PlaySessionAlreadyEndedException extends Exception
{
}
```

- [ ] **Step 4: Write the action**

Create `app/Actions/PlaySessions/EndPlaySessionAction.php`:

```php
<?php

namespace App\Actions\PlaySessions;

use App\Exceptions\PlaySessionAlreadyEndedException;
use App\Models\PlaySession;
use Illuminate\Support\Facades\DB;

class EndPlaySessionAction
{
    public function execute(PlaySession $session): PlaySession
    {
        return DB::transaction(function () use ($session) {
            // Re-fetch under a row lock so a concurrent "end" call is serialized.
            $locked = PlaySession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->ended_at !== null) {
                throw new PlaySessionAlreadyEndedException();
            }

            $endedAt = now();
            $durationSeconds = (int) $endedAt->diffInSeconds($locked->started_at, absolute: true);

            $locked->update([
                'ended_at' => $endedAt,
                'duration_seconds' => $durationSeconds,
            ]);

            $game = $locked->game()->lockForUpdate()->first();
            $update = ['last_played_at' => $endedAt];

            // Steam is authoritative for Steam games; the daily sync would
            // clobber any local increment. See DECISIONS.md.
            if ($game->source === 'manual') {
                $update['playtime_minutes'] = $game->playtime_minutes + intdiv($durationSeconds, 60);
            }

            $game->update($update);

            return $locked->fresh();
        });
    }
}
```

- [ ] **Step 5: Add the controller method**

Edit `app/Http/Controllers/PlaySessionController.php`. Add these imports at the top:

```php
use App\Actions\PlaySessions\EndPlaySessionAction;
use App\Exceptions\PlaySessionAlreadyEndedException;
use Illuminate\Http\Request;
```

Add this method to the class:

```php
public function end(Request $request, PlaySession $session, EndPlaySessionAction $action): JsonResponse
{
    if ($session->user_id !== $request->user()->id) {
        return response()->json(null, 404);
    }

    try {
        $ended = $action->execute($session);
    } catch (PlaySessionAlreadyEndedException) {
        return response()->json([
            'error_code' => 'play_session_already_ended',
            'message' => 'This play session has already ended.',
        ], 409);
    }

    return response()->json($ended->only(PlaySession::RESPONSE_FIELDS));
}
```

- [ ] **Step 6: Register the route**

Edit `routes/api.php`. Inside the same `Route::middleware('auth:sanctum')->group(...)` block added in Task 2, append:

```php
Route::post('/sessions/{session}/end', [PlaySessionController::class, 'end'])
    ->middleware('throttle:30,1')
    ->name('sessions.end');
```

(Route-model binding on `{session}` resolves to `PlaySession` via the parameter type hint.)

- [ ] **Step 7: Run and confirm all pass**

Run: `make test -- --filter=EndPlaySessionTest`
Expected: PASS (7 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Actions/PlaySessions/EndPlaySessionAction.php \
        app/Exceptions/PlaySessionAlreadyEndedException.php \
        app/Http/Controllers/PlaySessionController.php \
        routes/api.php \
        tests/Feature/PlaySessions/EndPlaySessionTest.php
git commit -m "[Sprint 3] add POST /api/sessions/{session}/end with transactional playtime aggregation"
```

---

### Task 4: `GET /api/sessions/active` + `GET /api/sessions` (history, paginated)

**Files:**
- Modify: `app/Http/Controllers/PlaySessionController.php` (add `active` and `index` methods)
- Modify: `routes/api.php` (register two GET routes)
- Test: `tests/Feature/PlaySessions/ActivePlaySessionTest.php`
- Test: `tests/Feature/PlaySessions/PlaySessionHistoryTest.php`

**Interfaces:**
- Consumes: controller from Task 3.
- Produces:
  - `GET /api/sessions/active` (name `sessions.active`) — returns `{data: <PlaySession | null>}`. `null` when the user has no open session. The `data` object, when present, includes the joined game title/cover for the header banner: `{...PlaySession::RESPONSE_FIELDS, game: {id, title, cover_url, steam_app_id}}`. No auth throttle needed beyond Sanctum.
  - `GET /api/sessions` (name `sessions.index`) — paginated (15/page), ended sessions only, ordered `ended_at desc`. Response shape mirrors `GameController@index`: `{data: [...], meta: {current_page, last_page, per_page, total}}`. Each row includes `{...PlaySession::RESPONSE_FIELDS, game: {id, title, cover_url, steam_app_id}}`.

- [ ] **Step 1: Write the failing tests — active**

Create `tests/Feature/PlaySessions/ActivePlaySessionTest.php`:

```php
<?php

namespace Tests\Feature\PlaySessions;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivePlaySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_active_returns_401(): void
    {
        $this->getJson('/api/sessions/active')->assertStatus(401);
    }

    public function test_active_returns_null_when_no_open_session(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        PlaySession::factory()->for($user)->for($game)->create();

        $this->actingAs($user)
            ->getJson('/api/sessions/active')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_active_returns_the_open_session_with_game(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create(['title' => 'Portal 2']);
        $session = PlaySession::factory()->for($user)->for($game)->active()->create();

        $this->actingAs($user)
            ->getJson('/api/sessions/active')
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.ended_at', null)
            ->assertJsonPath('data.game.id', $game->id)
            ->assertJsonPath('data.game.title', 'Portal 2')
            ->assertJsonMissingPath('data.user_id');
    }

    public function test_active_is_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $othersGame = Game::factory()->for($other)->create();
        PlaySession::factory()->for($other)->for($othersGame)->active()->create();

        $this->actingAs($user)
            ->getJson('/api/sessions/active')
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}
```

- [ ] **Step 2: Write the failing tests — history**

Create `tests/Feature/PlaySessions/PlaySessionHistoryTest.php`:

```php
<?php

namespace Tests\Feature\PlaySessions;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaySessionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_history_returns_401(): void
    {
        $this->getJson('/api/sessions')->assertStatus(401);
    }

    public function test_history_returns_only_own_ended_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $game = Game::factory()->for($user)->create(['title' => 'Portal 2']);
        $othersGame = Game::factory()->for($other)->create();

        PlaySession::factory()->for($user)->for($game)->create();
        PlaySession::factory()->for($other)->for($othersGame)->create();
        // Open session must be excluded from history.
        PlaySession::factory()->for($user)->for($game)->active()->create();

        $this->actingAs($user)
            ->getJson('/api/sessions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.game.title', 'Portal 2')
            ->assertJsonMissingPath('data.0.user_id');
    }

    public function test_history_orders_by_ended_at_desc(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();

        $older = PlaySession::factory()->for($user)->for($game)->create([
            'started_at' => now()->subDays(3),
            'ended_at' => now()->subDays(3)->addHour(),
            'duration_seconds' => 3600,
        ]);
        $newer = PlaySession::factory()->for($user)->for($game)->create([
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'duration_seconds' => 3600,
        ]);

        $this->actingAs($user)
            ->getJson('/api/sessions')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_history_paginates_at_15_per_page(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        PlaySession::factory()->count(16)->for($user)->for($game)->create();

        $this->actingAs($user)
            ->getJson('/api/sessions')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 16)
            ->assertJsonCount(15, 'data');
    }
}
```

- [ ] **Step 3: Run and confirm all fail**

Run: `make test -- --filter=ActivePlaySessionTest`
Run: `make test -- --filter=PlaySessionHistoryTest`
Expected: FAIL for both suites.

- [ ] **Step 4: Add the controller methods**

Edit `app/Http/Controllers/PlaySessionController.php`. Add these imports at the top:

```php
use App\Models\Game;
```

Add these two methods to the class:

```php
public function active(Request $request): JsonResponse
{
    $session = $request->user()->playSessions()
        ->whereNull('ended_at')
        ->with(['game' => fn ($q) => $q->select(['id', 'title', 'cover_url', 'steam_app_id'])])
        ->orderByDesc('started_at')
        ->first();

    return response()->json([
        'data' => $session === null ? null : $this->serialize($session),
    ]);
}

public function index(Request $request): JsonResponse
{
    $sessions = $request->user()->playSessions()
        ->whereNotNull('ended_at')
        ->with(['game' => fn ($q) => $q->select(['id', 'title', 'cover_url', 'steam_app_id'])])
        ->orderByDesc('ended_at')
        ->paginate(15);

    return response()->json([
        'data' => collect($sessions->items())->map(fn (PlaySession $s) => $this->serialize($s))->all(),
        'meta' => [
            'current_page' => $sessions->currentPage(),
            'last_page' => $sessions->lastPage(),
            'per_page' => $sessions->perPage(),
            'total' => $sessions->total(),
        ],
    ]);
}

private function serialize(PlaySession $session): array
{
    return [
        ...$session->only(PlaySession::RESPONSE_FIELDS),
        'game' => $session->game?->only(['id', 'title', 'cover_url', 'steam_app_id']),
    ];
}
```

- [ ] **Step 5: Register the routes**

Edit `routes/api.php`. Inside the Task-2 `Route::middleware('auth:sanctum')->group(...)` block, add above `/sessions/start`:

```php
Route::get('/sessions/active', [PlaySessionController::class, 'active'])
    ->name('sessions.active');
Route::get('/sessions', [PlaySessionController::class, 'index'])
    ->name('sessions.index');
```

(Route order matters: `/sessions/active` must come before any `/sessions/{session}` bindings so the literal wins.)

- [ ] **Step 6: Run and confirm all pass**

Run: `make test -- --filter=ActivePlaySessionTest`
Run: `make test -- --filter=PlaySessionHistoryTest`
Expected: PASS (4 + 4).

- [ ] **Step 7: Full-suite regression check**

Run: `make test`
Expected: full suite green (previous 115 + ~22 new).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/PlaySessionController.php \
        routes/api.php \
        tests/Feature/PlaySessions/ActivePlaySessionTest.php \
        tests/Feature/PlaySessions/PlaySessionHistoryTest.php
git commit -m "[Sprint 3] add GET /api/sessions/active and GET /api/sessions history endpoints"
```

---

### Task 5: React — session API client, active-session context, Start button on Library, persistent in-progress banner

**Files:**
- Create: `client/src/lib/playSessions.js`
- Create: `client/src/context/PlaySessionContext.jsx`
- Create: `client/src/components/sessions/ActiveSessionBanner.jsx`
- Modify: `client/src/main.jsx` (wrap the app in `PlaySessionProvider` inside `AuthProvider`)
- Modify: `client/src/pages/Library.jsx` (add "Start Session" button per row; disabled/repurposed while a session is open)
- Modify: `client/src/pages/Dashboard.jsx` (mount `ActiveSessionBanner` above the Steam-connection card)

**Interfaces:**
- Consumes: existing `client/src/lib/api.js` (Axios with CSRF cookie flow), `AuthContext` (mount order: `AuthProvider > PlaySessionProvider > Routes`).
- Produces:
  - `playSessions.js` exports: `getActiveSession()`, `startSession(gameId)`, `endSession(sessionId)`, `listHistory({ page, signal })`.
  - `PlaySessionContext` value: `{ active, loading, refresh(), start(gameId), end() }`. Fetches `/api/sessions/active` on mount and after `start`/`end`. Ticks a client-side elapsed-seconds counter every 1s while `active` is non-null.
  - `<ActiveSessionBanner />` — mounted at the top of Dashboard/Library/History pages. Renders nothing when `active === null`. When active: shows game title, elapsed HH:MM:SS (from client-side tick), "End Session" button.

- [ ] **Step 1: Write the session API client**

Create `client/src/lib/playSessions.js`:

```js
import { api } from './api'

export async function getActiveSession({ signal } = {}) {
  const { data } = await api.get('/api/sessions/active', { signal })
  return data.data
}

export async function startSession(gameId) {
  const { data } = await api.post('/api/sessions/start', { game_id: gameId })
  return data
}

export async function endSession(sessionId) {
  const { data } = await api.post(`/api/sessions/${sessionId}/end`)
  return data
}

export async function listHistory({ page = 1, signal } = {}) {
  const { data } = await api.get('/api/sessions', { params: { page }, signal })
  return data
}
```

- [ ] **Step 2: Write the context provider**

Create `client/src/context/PlaySessionContext.jsx`:

```jsx
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { endSession as endApi, getActiveSession, startSession as startApi } from '../lib/playSessions'

const PlaySessionContext = createContext(null)

export function PlaySessionProvider({ children }) {
  const [active, setActive] = useState(null)
  const [loading, setLoading] = useState(true)
  const [nowMs, setNowMs] = useState(() => Date.now())
  const tickHandle = useRef(null)

  const refresh = useCallback(async (signal) => {
    try {
      const session = await getActiveSession({ signal })
      setActive(session)
    } catch (err) {
      if (err.name !== 'CanceledError' && err.code !== 'ERR_CANCELED') {
        setActive(null)
      }
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    refresh(controller.signal)
    return () => controller.abort()
  }, [refresh])

  useEffect(() => {
    if (active === null) {
      if (tickHandle.current) window.clearInterval(tickHandle.current)
      tickHandle.current = null
      return
    }
    tickHandle.current = window.setInterval(() => setNowMs(Date.now()), 1000)
    return () => window.clearInterval(tickHandle.current)
  }, [active])

  const start = useCallback(async (gameId) => {
    const session = await startApi(gameId)
    setActive(session)
    return session
  }, [])

  const end = useCallback(async () => {
    if (!active) return null
    const ended = await endApi(active.id)
    setActive(null)
    return ended
  }, [active])

  const elapsedSeconds = useMemo(() => {
    if (!active) return 0
    const start = new Date(active.started_at).getTime()
    return Math.max(0, Math.floor((nowMs - start) / 1000))
  }, [active, nowMs])

  const value = useMemo(() => ({ active, loading, elapsedSeconds, refresh, start, end }),
    [active, loading, elapsedSeconds, refresh, start, end])

  return <PlaySessionContext.Provider value={value}>{children}</PlaySessionContext.Provider>
}

export function usePlaySession() {
  const ctx = useContext(PlaySessionContext)
  if (!ctx) throw new Error('usePlaySession must be used inside PlaySessionProvider')
  return ctx
}
```

- [ ] **Step 3: Write the banner component**

Create `client/src/components/sessions/ActiveSessionBanner.jsx`:

```jsx
import { useState } from 'react'
import { usePlaySession } from '../../context/PlaySessionContext'
import { Button } from '../ui/Button'

function formatElapsed(totalSeconds) {
  const h = Math.floor(totalSeconds / 3600).toString().padStart(2, '0')
  const m = Math.floor((totalSeconds % 3600) / 60).toString().padStart(2, '0')
  const s = (totalSeconds % 60).toString().padStart(2, '0')
  return `${h}:${m}:${s}`
}

export function ActiveSessionBanner() {
  const { active, elapsedSeconds, end } = usePlaySession()
  const [ending, setEnding] = useState(false)
  const [error, setError] = useState(null)

  if (!active) return null

  async function handleEnd() {
    setError(null)
    setEnding(true)
    try {
      await end()
    } catch {
      setError('Could not end the session. Please try again.')
    } finally {
      setEnding(false)
    }
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">
      <div>
        <div className="text-sm font-medium">Now playing: {active.game?.title ?? 'Unknown game'}</div>
        <div className="mt-1 font-mono text-lg" aria-label="elapsed time">{formatElapsed(elapsedSeconds)}</div>
        {error ? <div className="mt-1 text-sm text-rose-700">{error}</div> : null}
      </div>
      <Button type="button" onClick={handleEnd} disabled={ending} className="w-auto">
        {ending ? 'Ending…' : 'End Session'}
      </Button>
    </div>
  )
}
```

- [ ] **Step 4: Wrap the app in the provider**

Read `client/src/main.jsx` first so you match its existing shape. Then modify it to wrap `<App />` (and `<Routes />` if applicable) with `<PlaySessionProvider>` **inside** `<AuthProvider>`, e.g.:

```jsx
<AuthProvider>
  <PlaySessionProvider>
    <App />
  </PlaySessionProvider>
</AuthProvider>
```

Add the import at the top:

```jsx
import { PlaySessionProvider } from './context/PlaySessionContext'
```

- [ ] **Step 5: Add Start button + disabled state to Library**

Edit `client/src/pages/Library.jsx`. Add these imports at the top:

```jsx
import { usePlaySession } from '../context/PlaySessionContext'
```

Inside the `Library()` component, near the top with the other hooks, add:

```jsx
const { active, start } = usePlaySession()
const [startingGameId, setStartingGameId] = useState(null)
const [sessionError, setSessionError] = useState(null)

async function handleStart(gameId) {
  setSessionError(null)
  setStartingGameId(gameId)
  try {
    await start(gameId)
  } catch (err) {
    if (err.response?.status === 409) {
      setSessionError('You already have an active session. End it first.')
    } else {
      setSessionError('Could not start the session. Please try again.')
    }
  } finally {
    setStartingGameId(null)
  }
}
```

In the per-game action buttons block (currently the `<div className="flex gap-2">` around lines 180–189), add a Start button as the first child:

```jsx
<button
  type="button"
  disabled={active !== null || startingGameId === game.id}
  onClick={() => handleStart(game.id)}
  className="rounded-md border border-emerald-300 px-3 py-2 text-sm text-emerald-800 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-50"
>
  {startingGameId === game.id ? 'Starting…' : 'Start'}
</button>
```

Just above `<LibraryFilters ... />` render:

```jsx
<ActiveSessionBanner />
<FormError message={sessionError} />
```

Import it at the top:

```jsx
import { ActiveSessionBanner } from '../components/sessions/ActiveSessionBanner'
```

- [ ] **Step 6: Mount the banner on Dashboard**

Edit `client/src/pages/Dashboard.jsx`. Add the import:

```jsx
import { ActiveSessionBanner } from '../components/sessions/ActiveSessionBanner'
```

Immediately above `<VerifiedBanner user={user} />` (around line 127), add:

```jsx
<ActiveSessionBanner />
```

- [ ] **Step 7: Manually verify the flow**

Start dev stack: `make up` (backend) and `cd client && npm run dev` (frontend). Log in, visit `/library`, add a manual game, click Start next to it, observe the emerald banner tick. Refresh the page — banner should reappear (state is server-side). Click End — banner disappears, `games.playtime_minutes` on the game bumps. Verify:

```bash
make artisan CMD="tinker --execute='echo \App\Models\PlaySession::latest()->first();'"
```

- [ ] **Step 8: Frontend lint**

Run: `cd client && npx oxlint`
Expected: clean (pre-existing AuthContext warning is not a regression).

- [ ] **Step 9: Commit**

```bash
git add client/src/lib/playSessions.js \
        client/src/context/PlaySessionContext.jsx \
        client/src/components/sessions/ActiveSessionBanner.jsx \
        client/src/main.jsx \
        client/src/pages/Library.jsx \
        client/src/pages/Dashboard.jsx
git commit -m "[Sprint 3] add React active-session context, Start button, in-progress banner"
```

---

### Task 6: React — History page

**Files:**
- Create: `client/src/pages/History.jsx`
- Modify: `client/src/App.jsx` (add `/history` route)
- Modify: `client/src/pages/Dashboard.jsx` (add Link to History in the header nav)
- Modify: `client/src/pages/Library.jsx` (add Link to History in the header nav)

**Interfaces:**
- Consumes: `playSessions.js::listHistory` (Task 5), `PlaySessionContext` (for banner), `ProtectedRoute` (existing).
- Produces: `/history` route, protected. Groups sessions by game (grouped by `session.game.id` and displayed as a game-headed list), shows per-session date + duration, per-game total (sum of `duration_seconds`).

- [ ] **Step 1: Write the History page**

Create `client/src/pages/History.jsx`:

```jsx
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ActiveSessionBanner } from '../components/sessions/ActiveSessionBanner'
import { FormError } from '../components/ui/FormError'
import { listHistory } from '../lib/playSessions'

function formatDuration(seconds) {
  if (!seconds) return '0m'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function formatDate(value) {
  if (!value) return ''
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function groupByGame(sessions) {
  const map = new Map()
  for (const s of sessions) {
    const key = s.game?.id ?? 'unknown'
    if (!map.has(key)) map.set(key, { game: s.game, total: 0, sessions: [] })
    const entry = map.get(key)
    entry.sessions.push(s)
    entry.total += s.duration_seconds ?? 0
  }
  return Array.from(map.values())
}

export default function History() {
  const [sessions, setSessions] = useState([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const fetchPage = useCallback(async (signal) => {
    setError(null)
    try {
      const payload = await listHistory({ page, signal })
      setSessions(payload.data)
      setMeta(payload.meta)
    } catch (err) {
      if (err.name === 'CanceledError' || err.code === 'ERR_CANCELED') return
      setError('Could not load your session history. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [page])

  useEffect(() => {
    setLoading(true)
    const controller = new AbortController()
    fetchPage(controller.signal)
    return () => controller.abort()
  }, [fetchPage])

  const grouped = groupByGame(sessions)

  return (
    <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-8">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <Link to="/dashboard" className="text-sm text-slate-500 hover:underline">Dashboard</Link>
          <h1 className="text-2xl font-semibold">Play history</h1>
        </div>
        <Link to="/library" className="text-sm text-slate-600 hover:underline">Library</Link>
      </header>

      <ActiveSessionBanner />
      <FormError message={error} />

      {loading && sessions.length === 0 ? (
        <div className="rounded-md border border-slate-200 p-8 text-center text-sm text-slate-500">Loading…</div>
      ) : meta.total === 0 ? (
        <section className="rounded-md border border-slate-200 p-8 text-center">
          <h2 className="text-lg font-medium">No sessions yet.</h2>
          <p className="mt-1 text-sm text-slate-600">
            Start a session from the <Link to="/library" className="underline">library</Link> to see it here.
          </p>
        </section>
      ) : (
        <section className="space-y-4">
          {grouped.map(({ game, total, sessions: rows }) => (
            <article key={game?.id ?? 'unknown'} className="rounded-md border border-slate-200 p-4">
              <header className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-lg font-medium">{game?.title ?? 'Unknown game'}</h2>
                <span className="text-sm text-slate-600">Total: {formatDuration(total)}</span>
              </header>
              <ul className="mt-3 divide-y divide-slate-100">
                {rows.map((s) => (
                  <li key={s.id} className="flex items-center justify-between py-2 text-sm">
                    <span className="text-slate-700">{formatDate(s.ended_at)}</span>
                    <span className="text-slate-600">{formatDuration(s.duration_seconds)}</span>
                  </li>
                ))}
              </ul>
            </article>
          ))}
        </section>
      )}

      {meta.last_page > 1 && (
        <nav className="flex items-center justify-between text-sm">
          <button
            type="button"
            disabled={page <= 1}
            onClick={() => setPage((current) => current - 1)}
            className="rounded-md border border-slate-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-50"
          >
            Previous
          </button>
          <span className="text-slate-600">Page {meta.current_page} of {meta.last_page}</span>
          <button
            type="button"
            disabled={page >= meta.last_page}
            onClick={() => setPage((current) => current + 1)}
            className="rounded-md border border-slate-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-50"
          >
            Next
          </button>
        </nav>
      )}
    </div>
  )
}
```

- [ ] **Step 2: Register the route**

Edit `client/src/App.jsx`. Add the import at the top:

```jsx
import History from './pages/History'
```

Inside `<Routes>`, add above the `*` fallback:

```jsx
<Route path="/history" element={<ProtectedRoute><History /></ProtectedRoute>} />
```

- [ ] **Step 3: Add History link to Dashboard nav**

Edit `client/src/pages/Dashboard.jsx`. In the header nav `<div>` (around line 121–125), add a Link to History, e.g. immediately after the Library link:

```jsx
<Link to="/history" className="text-slate-600 hover:underline">History</Link>
```

- [ ] **Step 4: Add History link to Library nav**

Edit `client/src/pages/Library.jsx`. In the header `<header>` (around lines 130–138), add a Link to History (mirror the Dashboard link that appears in the same header):

```jsx
<Link to="/history" className="text-sm text-slate-500 hover:underline">History</Link>
```

Place it adjacent to the existing `<Link to="/dashboard">…</Link>`.

- [ ] **Step 5: Manually verify the flow**

With backend + frontend running: log in, start a session, end it, visit `/history` — the ended session should appear grouped under its game with a duration.

- [ ] **Step 6: Frontend lint**

Run: `cd client && npx oxlint`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add client/src/pages/History.jsx \
        client/src/App.jsx \
        client/src/pages/Dashboard.jsx \
        client/src/pages/Library.jsx
git commit -m "[Sprint 3] add /history page grouped by game"
```

---

### Task 7: Docs update + phase-close (DECISIONS.md, TROUBLESHOOTING.md, SESSION_LOG.md, README.md)

**Files:**
- Modify: `docs/DECISIONS.md`
- Modify: `docs/TROUBLESHOOTING.md`
- Modify: `SESSION_LOG.md`
- Modify: `README.md` (sprint changelog)

**Interfaces:**
- Consumes: everything shipped in Tasks 1–6.
- Produces: three ADR entries and one troubleshooting entry (see below); a new SESSION_LOG top entry; a README sprint-changelog bullet.

Do not touch `CLAUDE.md`'s phase tracker in a commit — it is gitignored (per SESSION_LOG entry from 2026-07-02). Update it locally for correctness but leave it out of `git add`.

- [ ] **Step 1: Add ADR entries to `docs/DECISIONS.md`**

Append three entries (use the format documented in `CLAUDE.md`):

```markdown
### One active play-session per user via row-locked user in a transaction (not a partial unique index)
**Date:** 2026-07-02
**Decision:** Enforce "one open `play_sessions` row per user" via `DB::transaction` + `User::whereKey($id)->lockForUpdate()` around a `whereNull('ended_at')->exists()` check before insert.
**Rationale:** Portable across MySQL 8 (dev/prod) and SQLite (test). Race-safe under concurrent "start session" calls from the same user.
**Alternatives considered:** Partial unique index on `user_id WHERE ended_at IS NULL` — supported by SQLite but not MySQL 8; would require a stored-generated-column workaround that diverges per engine. Application-only check without row locking — races under concurrent requests. A serializable transaction — heavier than needed.
**Consequences:** Every "start session" call takes a brief row lock on the users table for the duration of the transaction. Acceptable for a low-QPS user-scoped action.

### Session-end increments `games.playtime_minutes` only for manual-sourced games
**Date:** 2026-07-02
**Decision:** In `EndPlaySessionAction`, only bump `games.playtime_minutes` when `games.source = 'manual'`. Steam-sourced games have `last_played_at` updated but not the aggregate playtime.
**Rationale:** Steam is authoritative for Steam games — `SteamLibrarySynchronizer::sync` upserts `playtime_minutes` from Steam's `playtime_forever` on every daily sync, so any local increment would be silently overwritten. Worse, if we incremented locally AND Steam later added the same minutes, the total would double-count while the sync happened to overlap the session window.
**Alternatives considered:** Always increment locally (double-counts against Steam re-sync). Never increment locally, always require sync (breaks the manual-game workflow, which has no external source). Split into `local_playtime_minutes` vs `steam_playtime_minutes` (schema churn for a fringe benefit).
**Consequences:** Session records are still the source of truth for history; `games.playtime_minutes` is a cache with a documented source-per-row semantics. History view derives per-game totals from `sum(duration_seconds)` at read time, so it stays consistent regardless.

### `play_sessions` table (not `sessions`) — namespace collision with Laravel's HTTP session store
**Date:** 2026-07-02
**Decision:** Domain table is `play_sessions`; model is `App\Models\PlaySession`. URL paths remain `/api/sessions/*` per the build-plan spec.
**Rationale:** Laravel's `SESSION_DRIVER=database` already occupies the `sessions` table (created by the framework migration in `database/migrations/0001_01_01_000000_create_users_table.php`). Reusing the name would collide.
**Alternatives considered:** Switching the session driver to file/redis to free the name (breaks Sanctum's stateful session persistence for the SPA flow, which the current setup depends on). Model-name-only workaround (still leaves the DB table ambiguous).
**Consequences:** Slight internal-vs-URL naming divergence; documented here to prevent confusion.
```

- [ ] **Step 2: Add a TROUBLESHOOTING entry**

Append to `docs/TROUBLESHOOTING.md`:

```markdown
### `POST /api/sessions/start` returns 409 `play_session_already_active`
**Cause:** The user already has a `play_sessions` row with `ended_at IS NULL`. The active-session invariant is application-enforced by a lock-then-check inside `StartPlaySessionAction`.
**Fix:** End the existing session via `POST /api/sessions/{id}/end` (or `GET /api/sessions/active` to find its id), then retry the start. If the UI banner is stale, call `refresh()` on `usePlaySession()` to re-fetch.

### After a Steam re-sync, session-tracked minutes appear to "reset" on a Steam game
**Cause:** By design. Steam's `playtime_forever` is authoritative for Steam-sourced games and overwrites `games.playtime_minutes` on every scheduled sync (see `SteamLibrarySynchronizer::sync`). The session record itself is untouched — the history page still shows the session.
**Fix:** Not a bug. Per-game totals shown on the history page are computed from `sum(duration_seconds)` over ended sessions, not from `games.playtime_minutes`.
```

- [ ] **Step 3: Update `SESSION_LOG.md`**

Insert a new top entry (below the `Most recent first.` line, above the current top entry). Use the existing prose style — one paragraph:

```markdown
## [2026-07-02] Cortex Lite — Phase 3 session tracking shipped

Implemented the play-session lifecycle end-to-end: `play_sessions` table (`sessions` was taken by Laravel's HTTP session store), `PlaySession` model, `StartPlaySessionAction` (race-safe via `User::lockForUpdate()` inside a transaction — portable across MySQL 8 and SQLite), `EndPlaySessionAction` (transactional; only bumps `games.playtime_minutes` for `source = 'manual'` games because Steam sync is authoritative for Steam rows), the four endpoints (`POST /api/sessions/start`, `POST /api/sessions/{id}/end`, `GET /api/sessions/active`, `GET /api/sessions` history paginated), and the React side: `PlaySessionContext` with a client-side elapsed-time ticker, a persistent `ActiveSessionBanner` on Dashboard/Library/History, a per-row Start button on Library, and a new `/history` page grouped by game. Docs: three new ADRs in `DECISIONS.md` (one-active-session enforcement approach, manual-only playtime bump, table-name namespace collision), two entries in `TROUBLESHOOTING.md`. `make test` → full suite green (previous 115 + 22 new). Committed on branch `Phase-3` as `[Sprint 3]` commits.

→ branch `Phase-3`
```

- [ ] **Step 4: Update the README sprint changelog**

Add a line to the README changelog section (mirror the wording style of prior Sprint entries):

```markdown
- **Sprint 3 — Session tracking:** play-session lifecycle (start/end/active/history), race-safe one-active-per-user invariant, manual-only playtime aggregation, persistent React banner + /history page.
```

- [ ] **Step 5: Final regression check**

Run: `make test`
Expected: full suite green.

Run: `cd client && npx oxlint`
Expected: clean.

- [ ] **Step 6: Commit + push branch**

```bash
git add docs/DECISIONS.md docs/TROUBLESHOOTING.md SESSION_LOG.md README.md
git commit -m "[Sprint 3] update DECISIONS, TROUBLESHOOTING, SESSION_LOG, README for Phase 3"
git push -u origin Phase-3
```

Then open a PR from `Phase-3` → `main` via `gh pr create` if the user wants the merge flow; otherwise stop here for local review.

---

## Post-plan self-review

Verified against the spec (build-plan Phase 3, lines 164–179):

- **`sessions` schema (user_id, game_id, started_at, ended_at nullable, duration_seconds, timestamps, FKs on user_id AND game_id)** → Task 1. Table named `play_sessions` (explicit deviation from spec — documented in Task 7 ADR, forced by the framework `sessions` table).
- **Endpoints `POST /api/sessions/start` and `POST /api/sessions/{id}/end`, session-end computes duration + increments `games.hours_played`** → Tasks 2, 3. `hours_played` renamed to `playtime_minutes` per existing Phase 2 schema; documented in Global Constraints. Only manual games get the increment (documented in Global Constraints and in a DECISIONS.md ADR).
- **Wrap session-end in a DB transaction** → Task 3, `EndPlaySessionAction`.
- **One in-progress session at a time, via unique partial index OR `SELECT ... FOR UPDATE` in transaction** → Task 2, `StartPlaySessionAction` uses `User::whereKey($id)->lockForUpdate()->first()` inside `DB::transaction`.
- **React UI: Start Session button per game + persistent in-progress indicator in header with elapsed time and End Session button** → Task 5 (Start on Library rows + `ActiveSessionBanner` on Dashboard/Library) and Task 6 (banner also on History page).
- **History page: past sessions grouped by game, date, duration, total per-game playtime** → Task 6.
- **PHPUnit tests: start, end, one-in-progress constraint, authorization boundary, IDOR via `game_id` belonging to another user** → Tasks 1–4 cover all five plus additional coverage (guest 401 on every endpoint, validation, already-ended session, Steam-vs-manual playtime aggregation, history pagination + ordering + own-scope).
- **DECISIONS.md entry for the application-layer constraint rationale (vs. DB partial index)** → Task 7.
- **End-of-phase docs update** → Task 7 (DECISIONS, TROUBLESHOOTING, SESSION_LOG, README).

No placeholder steps. All types referenced across tasks are defined in an earlier task (`PlaySession` in Task 1 is used in Tasks 2–6; `StartPlaySessionAction`/`EndPlaySessionAction` are used by controller methods in the same task they're defined). Route order for the two `GET /api/sessions*` routes is called out explicitly (literal `/sessions/active` before parameterized `/sessions/{session}/end`).

One consequential deviation from the spec worth flagging to the user before execution: **`games.playtime_minutes` is only incremented for manual games.** This is the honest design given the existing Steam sync overwrite behavior; if you'd rather always increment (accepting Steam re-sync clobber for Steam rows), it's a one-line change in `EndPlaySessionAction` — but the test suite in Task 3 encodes the manual-only rule, so it would need updating too.
