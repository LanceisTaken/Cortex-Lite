<!-- base-commit: c80d602421eae8c40eef0a5fa488d4ef2ac744db -->
<!-- generated: 2026-07-05 -->

# Code Standards

Project-specific conventions for Cortex Lite (Laravel 13 / PHP 8.4 / React 19). Anything not listed here defaults to Laravel and Vite/React norms.

---

## 1. Forbidden Patterns

**Never run PHP/artisan/composer directly. Always go through the Makefile.**
Docker owns the runtime; a raw `php artisan` on the host uses the wrong PHP version and cannot see the containerised MySQL/Redis.

```makefile
# BAD
php artisan migrate
composer require some/pkg
vendor/bin/phpunit

# GOOD — see repo Makefile
make artisan CMD="migrate"
make composer CMD="require some/pkg"
make test
```

**Never use `$fillable` / `$hidden` array properties on Eloquent models.** This project uses Laravel 13's PHP-8 attribute form. Mixing both is silently error-prone (attributes win, array is dead code).

```php
// BAD
class User extends Authenticatable {
    protected $fillable = ['name', 'email'];
    protected $hidden   = ['password'];
}

// GOOD — from app/Models/User.php:14
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail { /* ... */ }
```

**Never do multi-write persistence inline in a controller.** Extract to an Action class and wrap the writes in `DB::transaction()`. A partial write on subscription cancel + user delete would leave a live Stripe customer with no local user row.

```php
// BAD
public function destroy(Request $r) {
    $r->user()->subscription('default')?->cancelNow();
    $r->user()->delete();                 // no transaction: partial-write hazard
    return response()->noContent();
}

// GOOD — from app/Actions/Auth/DeleteAccountAction.php:10
public function execute(User $user): void {
    DB::transaction(function () use ($user) {
        if ($user->subscribed('default')) {
            $user->subscription('default')->cancelNow();
        }
        $user->delete();
    });
}
```

**Never leak "which field was wrong" on failed login.** Errors on `email` (never a `password` error) — enumeration/credential-stuffing defence.

```php
// BAD
throw ValidationException::withMessages(['password' => 'Wrong password']);

// GOOD — from app/Http/Controllers/Auth/LoginController.php:20
throw ValidationException::withMessages(['email' => __('auth.failed')]);
```

**Never call raw `Auth::logout()` on a Sanctum SPA route.** The `auth:sanctum` middleware switches the default guard to `sanctum` mid-request; `Auth::forgetUser()` is required to clear Sanctum's `RequestGuard` cache. See `LoginController::destroy` and the identical block in `AccountController::destroy` — both carry the WHY comment; mirror it for any new logout-adjacent flow.

---

## 2. Code Examples

### Controller (JSON API, thin, Form-Request-validated)

```php
// DO — from app/Http/Controllers/Auth/RegisterController.php
// Handler stays thin: FormRequest validates, controller composes, no logic.
public function store(RegisterRequest $request): JsonResponse
{
    $user = User::create($request->validated());
    event(new Registered($user));
    Auth::guard('web')->login($user);
    $request->session()->regenerate();

    return response()->json(
        $user->only('id', 'name', 'email', 'email_verified_at', 'created_at'),
        201
    );
}

// DON'T — validates inline, returns the full model (leaks password_hash, remember_token, cashier cols)
public function store(Request $request) {
    $request->validate(['email' => 'required|email', 'password' => 'required']);
    $u = User::create($request->all());   // mass-assignment hole
    return $u;                             // response leak
}
```

### Route file (middleware chain + named route)

```php
// DO — from routes/api.php
// Domain-grouped Route:: calls, middleware chained, every route has a name().
Route::post('/login', [LoginController::class, 'store'])
    ->middleware(['guest', 'throttle:5,1'])
    ->name('login');

Route::delete('/account', [AccountController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->name('account.destroy');

// DON'T — controller strings, no name, no throttle
Route::post('/login', 'LoginController@store');
```

### React page: form submit with 422 + 429 handling

```jsx
// DO — from client/src/pages/Login.jsx
// 422 → per-field errors under `errors[field][0]`; 429 → read Retry-After header and
// disable the button with a live countdown; everything else → generic message.
// Matches server contract; user always gets specific + non-enumerating feedback.
try {
  await login(email, password)
  navigate('/dashboard')
} catch (err) {
  const status = err?.response?.status
  if (status === 429) {
    const secs = Number(err.response.headers['retry-after'] ?? 60)
    setRetryAfter(secs)
    setFormError(`Too many attempts. Try again in ${secs}s.`)
  } else if (status === 422) {
    const fieldErrors = err.response.data?.errors ?? {}
    setErrors(Object.fromEntries(
      Object.entries(fieldErrors).map(([k, v]) => [k, v[0]])
    ))
  } else {
    setFormError('Something went wrong. Please try again.')
  }
}
```

---

## 3. Error Handling

**Backend — throw `ValidationException` for user-visible input errors** so Laravel emits `422` with `{errors: {field: [...]}}` matching the frontend contract. Do NOT `return response()->json([...], 422)` by hand.

```php
// From LoginController.php:20 — one convention across all endpoints
throw ValidationException::withMessages(['email' => __('auth.failed')]);
```

**Frontend — never swallow errors.** Read `err.response.status` and branch on 422/429; log to `setFormError` for anything else. `try/catch` without any `setFormError` fallback ships silent failures.

---

## 4. Imports & Dependency Direction

**PHP:** Composer PSR-4 autoload. Never require files. Use FQCN `use` statements grouped by origin (framework → app), then a blank line separates them from the class body. See any controller for the pattern.

**React:** Import order = external → context/lib → components → pages. Relative imports only (this project has no `@/` alias).

```jsx
// DO — from client/src/pages/Login.jsx
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Button } from '../components/ui/Button'
import { Input } from '../components/ui/Input'
import { FormError } from '../components/ui/FormError'
```

**Layering:**
- `App\Http\Controllers\*` → may call Actions, Models, Requests
- `App\Actions\*` → may call Models, no HTTP concerns (`Request`, `Response`, `FormRequest` are forbidden here)
- `App\Models\*` → leaf; no controllers, no actions
- React `pages/` → import `context/` and `components/`; never import another page

---

## 5. Testing Patterns

Framework: PHPUnit via `make test`. Every feature-test class uses `RefreshDatabase`. Tests use `postJson`/`getJson`/`deleteJson` (never `post`/`get` — those return HTML and won't set the `Accept: application/json` header the app checks).

**Factory-first data creation. Never insert with `User::create(['password' => 'x'])` and hand-hash** — the `password` cast on the model handles it.

```php
// DO — from tests/Feature/Auth/LoginTest.php:15
$user = User::factory()->create(['password' => 'SecurePass123']);

$response = $this->postJson('/api/login', [
    'email'    => $user->email,
    'password' => 'SecurePass123',
]);

$response->assertOk()
    ->assertJsonPath('email', $user->email)
    ->assertJsonMissing(['password']);   // response-leak assertion is standard
$this->assertAuthenticatedAs($user);
```

**Authorization boundary + IDOR is the required test shape for every user-owned resource** (games, sessions, recommendations). User A calling `/api/{resource}/{B_id}` MUST return 403/404, never 200 or 500. This is per CLAUDE.md rule 3.

```php
// Pattern — add one of these per method (index/store/update/destroy) per resource
public function test_user_a_cannot_update_user_b_game(): void
{
    $a = User::factory()->create();
    $b = User::factory()->create();
    $bGame = Game::factory()->for($b)->create();

    $this->actingAs($a)
        ->putJson("/api/games/{$bGame->id}", ['status' => 'completed'])
        ->assertNotFound();                // 404, not 403 — don't confirm existence
}
```

**`actingAs($user)` is the auth shortcut** — do not hand-fake the session cookie.

---

## 6. Naming Conventions

**PHP files:**
- Controllers: `<Domain>Controller.php`, method names `store` (create), `show` (read one), `update`, `destroy`. Domain grouping under `Auth/`, `Account/`, etc. — see `app/Http/Controllers/Auth/`.
- Requests: `<Verb><Resource>Request.php` — `RegisterRequest`, `LoginRequest`, `ForgotPasswordRequest`.
- Actions: `<Verb><Noun>Action.php` — always one public method `execute()` (see `DeleteAccountAction`).
- Migrations: Laravel default `YYYY_MM_DD_HHMMSS_<verb>_<resource>_table.php`.

**Test methods:** `test_<subject>_<verb>_<outcome>` in `snake_case`, one behaviour per test.

```php
test_valid_credentials_login_succeeds()
test_invalid_credentials_return_generic_error()
test_login_throttles_after_five_failures()
```

**React:**
- Files: `PascalCase.jsx` for components + pages, `camelCase.js` for lib/utility modules.
- State setters: `useState()` return uses `[value, setValue]` — never `[val, changeVal]`.
- Booleans use `busy` / `disabled` / `loading` (project convention — no `is`/`has` prefix in the existing code).

**Commit messages:** `[Sprint <N>] <verb> <what>`. Every commit on a phase branch carries the same sprint tag; the sprint number = the phase number.

---

## 7. File Organization

```
app/
├── Actions/<Domain>/       # multi-write orchestrators, one execute() method
├── Http/
│   ├── Controllers/<Domain>/   # thin JSON handlers; Auth/ Account/ Steam/ ...
│   └── Requests/<Domain>/      # FormRequest validation classes
└── Models/                 # Eloquent models with PHP attributes

tests/
├── Feature/<Domain>/       # mirrors app/Http/Controllers/<Domain>/ layout
└── Unit/                   # pure-logic tests (no HTTP, no DB by default)

database/
├── migrations/             # date-prefixed; up() AND down() both required
└── factories/              # one factory per model

client/src/
├── App.jsx                 # single Routes tree
├── main.jsx                # entry
├── pages/                  # one file per route; PascalCase.jsx
├── components/             # feature components
│   └── ui/                 # reusable primitives: Button, Input, FormError
├── context/                # React contexts (AuthContext...)
└── lib/                    # api.js and other side-effectful helpers
```

**New domain — create four things together:** model, migration + factory, controller, feature test file. Route line goes in `routes/api.php` under a domain comment.

**Reference/lookup data lives in `database/data/*.json`, ingested by an idempotent seeder.** The seeder reads the JSON, derives any computed columns via a `Support/` classifier, and `upsert()`s so re-running `make artisan CMD="db:seed"` never duplicates rows.

```php
// DO — from database/seeders/GpuSeeder.php
$rows = json_decode((string) file_get_contents(database_path('data/gpus.json')), true, flags: JSON_THROW_ON_ERROR);
$records = array_map(fn (array $row) => [..., 'tier' => GpuTierClassifier::classify($row['g3d_mark']), ...], $rows);
DB::table('gpus')->upsert($records, uniqueBy: ['name'], update: [...]);
```

**Pure derivation logic goes in `App\Support\<Domain>\*`, not inline in the seeder or a service.** One `final class` per concern, a `public const` for thresholds/config, a single `public static` entry point — no instantiation, no side effects.

```php
// DO — from app/Support/Hardware/GpuTierClassifier.php
final class GpuTierClassifier
{
    public const THRESHOLDS = ['low_max' => 7999, 'mid_max' => 13999, 'high_max' => 21999];

    public static function classify(int $g3dMark): string
    {
        return match (true) {
            $g3dMark <= self::THRESHOLDS['low_max'] => 'low',
            $g3dMark <= self::THRESHOLDS['mid_max'] => 'mid',
            $g3dMark <= self::THRESHOLDS['high_max'] => 'high',
            default => 'enthusiast',
        };
    }
}
```

---

## 8. Technology Decisions

- **Auth:** Sanctum SPA (cookie + CSRF), not tokens. The React client is first-party same-origin (Vite proxy). See `docs/DECISIONS.md`.
- **Password rule chain:** `Password::defaults()` = `min(8)->mixedCase()->numbers()->uncompromised()`. The `uncompromised()` HIBP check is auto-skipped under `app()->runningUnitTests()`.
- **Model attributes over legacy properties:** PHP 8 `#[Fillable]` / `#[Hidden]` only. Casts stay as a `casts()` method.
- **JSON responses only under `/api/*`.** `bootstrap/app.php` forces `shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))` — validation errors ship as JSON automatically.
- **CSRF flow:** `client/src/lib/api.js` fetches `GET /sanctum/csrf-cookie` lazily via a request interceptor on the first mutating request. Never call it eagerly.
- **Linter (client):** `oxlint` (not ESLint). Fast, opinionated. Fix warnings; don't disable.
- **Docs:** Four canonical files — `ARCHITECTURE.md`, `DECISIONS.md`, `TROUBLESHOOTING.md`, `README.md`. Update as part of the same PR as the code.

---

## 9. Exemplar Files

**`app/Http/Controllers/Auth/RegisterController.php`** — the reference shape for a POST-create endpoint: FormRequest injected, thin action, explicit `JsonResponse` return type, `$user->only(...)` response projection to avoid response-leaks.

**`app/Actions/Auth/DeleteAccountAction.php`** — the reference for any multi-write flow: one class, one `execute()` method, `DB::transaction()` wrapping the writes, no HTTP concerns.

**`app/Http/Controllers/AccountController.php`** — the Sanctum-SPA logout ordering pattern (documented in inline WHY comments). Reproduce verbatim in any new endpoint that logs out or deletes the authed user.

**`tests/Feature/Auth/LoginTest.php`** — the reference feature-test file: `RefreshDatabase`, factory-first, `postJson`, `assertJsonPath` + `assertJsonMissing`, throttle-and-`Retry-After` assertions. Any resource test file should read the same.

**`client/src/pages/Login.jsx`** — the reference React form page: local state, submit handler branching on 422 / 429 / generic, primitives from `components/ui/`, semantic Tailwind classes inline. Copy-paste and rewire for new forms.

**`client/src/lib/api.js`** — the axios client. Do not create a second axios instance; import `api` from here.
