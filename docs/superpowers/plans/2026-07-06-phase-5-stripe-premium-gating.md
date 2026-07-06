# Phase 5 — Stripe Premium Gating Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate the forward-mode (`/api/recommend`) and reverse-mode (`/api/reverse`) optimizer endpoints behind a rolling 30-day free-tier quota (3 recommendations, 5 reverse-mode calls), and let users lift the caps by subscribing to a $5/month "Cortex Premium" plan through Stripe Checkout, with premium status kept in sync from Stripe webhooks.

**Architecture:** Usage is tracked as one row per successful call in a `usage_events` table (`type` = `recommend` | `reverse`); the free-tier check is a `count(...) where created_at >= now()-30d`, so there is no counter column and no reset job. A `UsageQuota` service owns the count/limit/record logic. Premium billing rides on the already-installed Laravel Cashier (Billable trait + `subscriptions` tables already exist). A denormalized `users.is_premium` boolean is the fast read path used by the quota check; it is written **only** by a webhook controller that extends Cashier's `WebhookController`, so Stripe remains the source of truth and signatures are verified. The React dashboard reads a new `GET /api/usage` endpoint to render counters, an upgrade button, and a soft-locked state.

**Tech Stack:** Laravel 12 / PHP 8.4, Laravel Cashier ^16.6 (Stripe), MySQL 8, PHPUnit, React 19 (Vite) + Axios + Tailwind, oxlint.

## Global Constraints

Copied verbatim from `CLAUDE.md` and `docs/cortex-lite-build-plan.md` — every task's requirements implicitly include these:

- **The LLM never decides settings.** This slice touches only gating; it must not change `RecommendationEngine`, `SettingsDiffEngine`, or `ExplanationGenerator` output.
- **Free tier gates usage volume, not catalog.** All games still get recommendations. Free users get **3 recommendations + 5 reverse-mode calls per rolling 30-day window**. Never restrict which games appear.
- **Rolling 30-day window via event-table count.** `count where user_id = ? and type = 'recommend' and created_at >= now() - interval 30 day`. No counter column, no reset job.
- **Do not add a counter column** to `users`.
- **Sanctum SPA auth only** (cookie-based, CSRF-protected, stateful). The webhook route is the one deliberate exception — it is unauthenticated, CSRF-exempt, and Stripe-signature-verified.
- **Verify Stripe webhook signatures.** Wrong signature → HTTP 400.
- **Every phase ends with a doc update:** `DECISIONS.md` (rolling-window choice, LLM-safety pattern, sync explanation choice, Cashier-vs-custom-webhook) and `TROUBLESHOOTING.md` (Stripe CLI test-mode walkthrough).
- **Commit style:** sprint-tagged, e.g. `[Sprint 5] enforce free-tier recommendation quota`.
- **Always use `make` targets**, never raw docker/php: `make test` runs PHPUnit in the app container; `make migrate` runs migrations. Frontend runs on the host (`cd client && npm run lint`, `npm run build`).

### Spec-drift notes (decided, flagged per collaboration guidance)

- The spec line "Add `is_premium`, `stripe_customer_id` columns to `users`" is satisfied for the customer id by Cashier's existing **`stripe_id`** column (added by `2026_07_01_085757_create_customer_columns.php`). We do **not** add a duplicate `stripe_customer_id`. We add only `is_premium`.
- The spec's "Stripe webhook handler flipping `is_premium`" is implemented by **extending Cashier's `WebhookController`** (per the confirmed design decision) rather than hand-rolling signature verification and subscription lifecycle from scratch. Cashier populates the `subscriptions` table; our override syncs `is_premium` from `subscribed('default')`.
- The spec's "CSRF-exempt the webhook route" is inherently true because the route lives in `routes/api.php` (the `api` middleware group has no `VerifyCsrfToken`). We additionally add it to the CSRF `except` list defensively and document why.

---

## File Structure

**Backend — create:**
- `database/migrations/2026_07_06_100000_add_is_premium_to_users_table.php` — `users.is_premium` boolean, default false.
- `database/migrations/2026_07_06_100100_create_usage_events_table.php` — usage event log.
- `app/Models/UsageEvent.php` — Eloquent model for a usage event.
- `database/factories/UsageEventFactory.php` — factory for tests.
- `app/Services/UsageQuota.php` — count / limit / remaining / enforce / record.
- `app/Exceptions/QuotaExceededException.php` — thrown when a free user hits a cap.
- `app/Http/Controllers/UsageController.php` — `GET /api/usage`.
- `app/Http/Controllers/CheckoutController.php` — `POST /api/checkout`.
- `app/Http/Controllers/StripeWebhookController.php` — extends Cashier's `WebhookController`.

**Backend — modify:**
- `app/Models/User.php` — `is_premium` cast + `usageEvents()` relation.
- `app/Http/Controllers/UserController.php` — expose `is_premium` on `/api/me`.
- `app/Http/Controllers/RecommendationController.php` — enforce + record `recommend` usage.
- `app/Http/Controllers/ReverseController.php` — enforce + record `reverse` usage.
- `app/Providers/AppServiceProvider.php` — `Cashier::ignoreRoutes()`.
- `bootstrap/app.php` — render `QuotaExceededException` as 402; CSRF `except` for the webhook.
- `config/services.php` — `stripe.price` (the premium price id).
- `routes/api.php` — `GET /api/usage`, `POST /api/checkout`, `POST /api/stripe/webhook`.
- `.env.example` — `STRIPE_PRICE_PREMIUM=`.

**Frontend — create:**
- `client/src/lib/usage.js` — `getUsage()`, `startCheckout()`.

**Frontend — modify:**
- `client/src/pages/Dashboard.jsx` — usage counters, upgrade button, soft-lock notice, premium badge.

**Docs — modify:**
- `docs/DECISIONS.md`, `docs/TROUBLESHOOTING.md`, `CLAUDE.md` (phase tracker), `README.md` (sprint changelog).

**Tests — create:**
- `tests/Feature/Billing/UsageEventSchemaTest.php`
- `tests/Unit/Services/UsageQuotaTest.php`
- `tests/Feature/Billing/UsageEndpointTest.php`
- `tests/Feature/Billing/CheckoutEndpointTest.php`
- `tests/Feature/Billing/StripeWebhookTest.php`
- **Modify:** `tests/Feature/Recommendations/RecommendEndpointTest.php`, `tests/Feature/Recommendations/ReverseEndpointTest.php` (add quota cases).

---

## Task 1: Data layer — `is_premium` column, `usage_events` table, models

**Files:**
- Create: `database/migrations/2026_07_06_100000_add_is_premium_to_users_table.php`
- Create: `database/migrations/2026_07_06_100100_create_usage_events_table.php`
- Create: `app/Models/UsageEvent.php`
- Create: `database/factories/UsageEventFactory.php`
- Modify: `app/Models/User.php`
- Modify: `app/Http/Controllers/UserController.php`
- Test: `tests/Feature/Billing/UsageEventSchemaTest.php`

**Interfaces:**
- Produces:
  - `users.is_premium` (bool, default false), cast to boolean on `User`.
  - `User::usageEvents(): HasMany` → `UsageEvent`.
  - `UsageEvent` model, table `usage_events`, columns `id, user_id, type, created_at, updated_at`, `$fillable = ['type']`.
  - `UsageEvent::factory()` with default `type = 'recommend'`.
  - `/api/me` JSON now includes `is_premium`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Billing/UsageEventSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Billing;

use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageEventSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_default_to_non_premium(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->is_premium);
        $this->assertIsBool($user->fresh()->is_premium);
    }

    public function test_user_has_many_usage_events(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);
        $user->usageEvents()->create(['type' => 'reverse']);

        $this->assertSame(2, $user->usageEvents()->count());
        $this->assertContains('recommend', $user->usageEvents()->pluck('type')->all());
    }

    public function test_deleting_a_user_cascades_usage_events(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);

        $user->delete();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    public function test_me_endpoint_exposes_is_premium(): void
    {
        $user = User::factory()->create(['is_premium' => true]);

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('is_premium', true);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make test` (or `php artisan test --filter=UsageEventSchemaTest`)
Expected: FAIL — `usage_events` table / `UsageEvent` class / `is_premium` column do not exist.

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_07_06_100000_add_is_premium_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_premium')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_premium');
        });
    }
};
```

`database/migrations/2026_07_06_100100_create_usage_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->timestamps();

            // Supports the rolling-window quota count:
            // where user_id = ? and type = ? and created_at >= ?
            $table->index(['user_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
```

- [ ] **Step 4: Create the model and factory**

`app/Models/UsageEvent.php`:

```php
<?php

namespace App\Models;

use Database\Factories\UsageEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageEvent extends Model
{
    /** @use HasFactory<UsageEventFactory> */
    use HasFactory;

    protected $fillable = ['type'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`database/factories/UsageEventFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\UsageEvent>
 */
class UsageEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'recommend',
        ];
    }
}
```

- [ ] **Step 5: Wire up the `User` model**

In `app/Models/User.php`, add the `is_premium` cast to the `casts()` array and a `usageEvents()` relation. The `casts()` method becomes:

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'steam_id_resolved_at' => 'datetime',
            'is_premium' => 'boolean',
        ];
    }
```

Add the relation next to the existing `playSessions()` relation (also add the `UsageEvent` import is unnecessary — `HasMany` is already imported):

```php
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }
```

- [ ] **Step 6: Expose `is_premium` on `/api/me`**

In `app/Http/Controllers/UserController.php`, add `'is_premium'` to the `only(...)` list:

```php
    public function show(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->only(
                'id',
                'name',
                'email',
                'email_verified_at',
                'is_premium',
                'steam_id',
                'steam_id_resolved_at',
                'created_at',
            )
        );
    }
```

- [ ] **Step 7: Run migrations and the test**

Run: `make migrate` then `make test --filter=UsageEventSchemaTest` (or `php artisan test --filter=UsageEventSchemaTest`)
Expected: PASS (4 tests). `RefreshDatabase` runs the new migrations automatically for the test DB.

- [ ] **Step 8: Commit**

```bash
git add app/Models/User.php app/Models/UsageEvent.php database/factories/UsageEventFactory.php database/migrations/2026_07_06_100000_add_is_premium_to_users_table.php database/migrations/2026_07_06_100100_create_usage_events_table.php app/Http/Controllers/UserController.php tests/Feature/Billing/UsageEventSchemaTest.php
git commit -m "[Sprint 5] add is_premium column and usage_events table"
```

---

## Task 2: `UsageQuota` service + `QuotaExceededException` + 402 render

**Files:**
- Create: `app/Services/UsageQuota.php`
- Create: `app/Exceptions/QuotaExceededException.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Unit/Services/UsageQuotaTest.php`

**Interfaces:**
- Consumes: `User::usageEvents()`, `User::is_premium` (Task 1).
- Produces:
  - `UsageQuota::WINDOW_DAYS = 30`
  - `UsageQuota::LIMITS = ['recommend' => 3, 'reverse' => 5]`
  - `UsageQuota::used(User $user, string $type): int`
  - `UsageQuota::limit(string $type): int`
  - `UsageQuota::remaining(User $user, string $type): ?int` (null = unlimited/premium)
  - `UsageQuota::ensureWithinLimit(User $user, string $type): void` (throws `QuotaExceededException`)
  - `UsageQuota::record(User $user, string $type): void`
  - `QuotaExceededException` with public readonly `string $type`, `int $limit`, `int $used`.
  - `QuotaExceededException` renders to HTTP **402** with JSON `{ error_code: "quota_exceeded", type, limit, used, window_days, message }` on `api/*` requests.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/UsageQuotaTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Exceptions\QuotaExceededException;
use App\Models\User;
use App\Services\UsageQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function quota(): UsageQuota
    {
        return new UsageQuota();
    }

    public function test_used_counts_only_matching_type_within_window(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);
        $user->usageEvents()->create(['type' => 'recommend']);
        $user->usageEvents()->create(['type' => 'reverse']);

        $this->assertSame(2, $this->quota()->used($user, 'recommend'));
        $this->assertSame(1, $this->quota()->used($user, 'reverse'));
    }

    public function test_used_ignores_events_older_than_the_window(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend', 'created_at' => now()->subDays(31)]);
        $user->usageEvents()->create(['type' => 'recommend', 'created_at' => now()->subDays(1)]);

        $this->assertSame(1, $this->quota()->used($user, 'recommend'));
    }

    public function test_used_ignores_other_users_events(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $other->usageEvents()->create(['type' => 'recommend']);

        $this->assertSame(0, $this->quota()->used($user, 'recommend'));
    }

    public function test_remaining_counts_down_for_free_users(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);

        $this->assertSame(2, $this->quota()->remaining($user, 'recommend')); // 3 - 1
    }

    public function test_remaining_is_null_for_premium_users(): void
    {
        $user = User::factory()->create(['is_premium' => true]);

        $this->assertNull($this->quota()->remaining($user, 'recommend'));
    }

    public function test_ensure_within_limit_passes_below_the_cap(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);
        $user->usageEvents()->create(['type' => 'recommend']);

        $this->quota()->ensureWithinLimit($user, 'recommend'); // 2 < 3, no throw

        $this->assertTrue(true);
    }

    public function test_ensure_within_limit_throws_at_the_cap(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $user->usageEvents()->create(['type' => 'recommend']);
        }

        try {
            $this->quota()->ensureWithinLimit($user, 'recommend');
            $this->fail('Expected QuotaExceededException.');
        } catch (QuotaExceededException $e) {
            $this->assertSame('recommend', $e->type);
            $this->assertSame(3, $e->limit);
            $this->assertSame(3, $e->used);
        }
    }

    public function test_ensure_within_limit_never_throws_for_premium(): void
    {
        $user = User::factory()->create(['is_premium' => true]);
        for ($i = 0; $i < 10; $i++) {
            $user->usageEvents()->create(['type' => 'recommend']);
        }

        $this->quota()->ensureWithinLimit($user, 'recommend');

        $this->assertTrue(true);
    }

    public function test_record_writes_one_event(): void
    {
        $user = User::factory()->create();

        $this->quota()->record($user, 'reverse');

        $this->assertSame(1, $user->usageEvents()->where('type', 'reverse')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UsageQuotaTest`
Expected: FAIL — `App\Services\UsageQuota` and `App\Exceptions\QuotaExceededException` do not exist.

- [ ] **Step 3: Create the exception**

`app/Exceptions/QuotaExceededException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class QuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $type,
        public readonly int $limit,
        public readonly int $used,
    ) {
        parent::__construct("Free-tier quota exceeded for {$type}.");
    }
}
```

- [ ] **Step 4: Create the service**

`app/Services/UsageQuota.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\QuotaExceededException;
use App\Models\User;
use Illuminate\Support\Carbon;

class UsageQuota
{
    public const WINDOW_DAYS = 30;

    /** @var array<string, int> */
    public const LIMITS = [
        'recommend' => 3,
        'reverse' => 5,
    ];

    public function used(User $user, string $type): int
    {
        return $user->usageEvents()
            ->where('type', $type)
            ->where('created_at', '>=', Carbon::now()->subDays(self::WINDOW_DAYS))
            ->count();
    }

    public function limit(string $type): int
    {
        return self::LIMITS[$type];
    }

    /**
     * Remaining calls in the window, or null when unlimited (premium).
     */
    public function remaining(User $user, string $type): ?int
    {
        if ($user->is_premium) {
            return null;
        }

        return max(0, $this->limit($type) - $this->used($user, $type));
    }

    public function ensureWithinLimit(User $user, string $type): void
    {
        if ($user->is_premium) {
            return;
        }

        $used = $this->used($user, $type);

        if ($used >= $this->limit($type)) {
            throw new QuotaExceededException($type, $this->limit($type), $used);
        }
    }

    public function record(User $user, string $type): void
    {
        $user->usageEvents()->create(['type' => $type]);
    }
}
```

- [ ] **Step 5: Render the exception as 402**

In `bootstrap/app.php`, inside the `->withExceptions(function (Exceptions $exceptions): void { ... })` closure, add a render handler alongside the existing `SteamApiException` one. Add these imports at the top of the file:

```php
use App\Exceptions\QuotaExceededException;
use App\Services\UsageQuota;
```

Add inside the `withExceptions` closure:

```php
        $exceptions->render(function (QuotaExceededException $exception, Request $request): ?\Illuminate\Http\JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error_code' => 'quota_exceeded',
                'type' => $exception->type,
                'limit' => $exception->limit,
                'used' => $exception->used,
                'window_days' => UsageQuota::WINDOW_DAYS,
                'message' => "You've used all {$exception->limit} free {$exception->type} calls in the last "
                    . UsageQuota::WINDOW_DAYS . ' days. Upgrade to Cortex Premium for unlimited access.',
            ], 402);
        });
```

- [ ] **Step 6: Run the test**

Run: `php artisan test --filter=UsageQuotaTest`
Expected: PASS (9 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Services/UsageQuota.php app/Exceptions/QuotaExceededException.php bootstrap/app.php tests/Unit/Services/UsageQuotaTest.php
git commit -m "[Sprint 5] add UsageQuota service and 402 quota exception"
```

---

## Task 3: Enforce + record quota on `POST /api/recommend`

**Files:**
- Modify: `app/Http/Controllers/RecommendationController.php`
- Test: `tests/Feature/Recommendations/RecommendEndpointTest.php` (add cases)

**Interfaces:**
- Consumes: `UsageQuota::ensureWithinLimit()`, `UsageQuota::record()` (Task 2); the `quota_exceeded` 402 render (Task 2).
- Produces: `/api/recommend` records a `recommend` usage event on success; free users get 402 after 3 successful calls in the window; premium users are never capped.

- [ ] **Step 1: Write the failing tests**

Add these methods to the existing `tests/Feature/Recommendations/RecommendEndpointTest.php` class (it already has the `scenario()` helper, `RefreshDatabase`, and `use App\Models\User;`):

```php
    public function test_successful_recommend_records_a_usage_event(): void
    {
        [$user, $payload] = $this->scenario();

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertOk();

        $this->assertSame(1, $user->usageEvents()->where('type', 'recommend')->count());
    }

    public function test_free_user_is_blocked_with_402_after_three_recommendations(): void
    {
        [$user, $payload] = $this->scenario();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->postJson('/api/recommend', $payload)->assertOk();
        }

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertStatus(402)
            ->assertJsonPath('error_code', 'quota_exceeded')
            ->assertJsonPath('type', 'recommend')
            ->assertJsonPath('limit', 3);

        // The blocked call must NOT record a 4th event.
        $this->assertSame(3, $user->usageEvents()->where('type', 'recommend')->count());
    }

    public function test_premium_user_is_never_capped_on_recommendations(): void
    {
        [$user, $payload] = $this->scenario();
        $user->update(['is_premium' => true]);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/recommend', $payload)->assertOk();
        }

        $this->assertTrue(true);
    }
```

> Note: `scenario()` already fakes any HTTP the engine/explanation layer needs (existing tests pass without live Gemini). If a new premium loop surfaces a live-call flake, add `Http::fake()` at the top of the new test — but do not change `scenario()`'s existing behavior.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=RecommendEndpointTest`
Expected: FAIL — no usage event is recorded; the 4th call returns 200, not 402.

- [ ] **Step 3: Enforce and record in the controller**

Edit `app/Http/Controllers/RecommendationController.php`. Add the import and inject `UsageQuota`, check the cap first, and record on success. The `store` method becomes:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Recommendations\RecommendRequest;
use App\Models\Cpu;
use App\Models\Gpu;
use App\Services\ExplanationGenerator;
use App\Services\RecommendationEngine;
use App\Services\UsageQuota;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class RecommendationController extends Controller
{
    public function store(RecommendRequest $request, RecommendationEngine $engine, ExplanationGenerator $explanations, UsageQuota $quota): JsonResponse
    {
        $user = $request->user();

        // Enforce the free-tier cap before doing any work. Premium users pass through.
        $quota->ensureWithinLimit($user, 'recommend');

        try {
            $game = $user->games()->findOrFail($request->validated('game_id'));
        } catch (ModelNotFoundException) {
            return response()->json(null, 404);
        }

        $gpu = Gpu::query()->findOrFail($request->validated('gpu_id'));
        $cpu = Cpu::query()->findOrFail($request->validated('cpu_id'));
        $goal = $request->validated('goal');

        $result = $engine->recommend($game, $gpu, $cpu, (int) $request->validated('ram_gb'), $goal);
        $fallback = $this->fallbackExplanation($result, $goal);

        // Only successful recommendations consume quota.
        $quota->record($user, 'recommend');

        return response()->json([
            'data' => [
                'game_id' => $game->id,
                'goal' => $goal,
                ...$result,
                'explanation' => $explanations->forward($result, $goal, $game->id, $fallback),
            ],
        ]);
    }
```

Leave the `fallbackExplanation()` private method below it unchanged.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=RecommendEndpointTest`
Expected: PASS (all existing cases plus the 3 new ones).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/RecommendationController.php tests/Feature/Recommendations/RecommendEndpointTest.php
git commit -m "[Sprint 5] enforce free-tier recommendation quota"
```

---

## Task 4: Enforce + record quota on `POST /api/reverse`

**Files:**
- Modify: `app/Http/Controllers/ReverseController.php`
- Test: `tests/Feature/Recommendations/ReverseEndpointTest.php` (add cases)

**Interfaces:**
- Consumes: `UsageQuota::ensureWithinLimit()`, `UsageQuota::record()` (Task 2).
- Produces: `/api/reverse` records a `reverse` usage event on success; free users get 402 after **5** successful calls; premium users are never capped. Reverse and recommend quotas are independent (separate `type`).

- [ ] **Step 1: Write the failing tests**

Open `tests/Feature/Recommendations/ReverseEndpointTest.php` and inspect its existing helper that builds a valid payload (mirrors `RecommendEndpointTest::scenario()` but includes `current_settings`). Add cases using that helper. If the helper is named differently, substitute its name; the shape below assumes a `scenario()` returning `[User $user, array $payload]` exactly like the recommend test:

```php
    public function test_successful_reverse_records_a_usage_event(): void
    {
        [$user, $payload] = $this->scenario();

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertOk();

        $this->assertSame(1, $user->usageEvents()->where('type', 'reverse')->count());
    }

    public function test_free_user_is_blocked_with_402_after_five_reverse_calls(): void
    {
        [$user, $payload] = $this->scenario();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/reverse', $payload)->assertOk();
        }

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertStatus(402)
            ->assertJsonPath('error_code', 'quota_exceeded')
            ->assertJsonPath('type', 'reverse')
            ->assertJsonPath('limit', 5);

        $this->assertSame(5, $user->usageEvents()->where('type', 'reverse')->count());
    }

    public function test_reverse_and_recommend_quotas_are_independent(): void
    {
        [$user, $payload] = $this->scenario();

        // Exhaust reverse.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/reverse', $payload)->assertOk();
        }
        $this->actingAs($user)->postJson('/api/reverse', $payload)->assertStatus(402);

        // Recommend still has its own independent budget of 3.
        $this->assertSame(0, $user->usageEvents()->where('type', 'recommend')->count());
    }

    public function test_premium_user_is_never_capped_on_reverse(): void
    {
        [$user, $payload] = $this->scenario();
        $user->update(['is_premium' => true]);

        for ($i = 0; $i < 7; $i++) {
            $this->actingAs($user)->postJson('/api/reverse', $payload)->assertOk();
        }

        $this->assertTrue(true);
    }
```

> If `ReverseEndpointTest` does **not** already have a `scenario()` helper, add one modeled on `RecommendEndpointTest::scenario()` but include a valid `current_settings` array (copy the payload shape from that file's existing happy-path test).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ReverseEndpointTest`
Expected: FAIL — no event recorded; 6th call returns 200 not 402.

- [ ] **Step 3: Enforce and record in the controller**

Edit `app/Http/Controllers/ReverseController.php`. Add the `UsageQuota` import and parameter, check the cap first, record on success. The `store` method becomes:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Recommendations\ReverseRequest;
use App\Models\Cpu;
use App\Models\Gpu;
use App\Services\ExplanationGenerator;
use App\Services\SettingsDiffEngine;
use App\Services\UsageQuota;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class ReverseController extends Controller
{
    public function store(ReverseRequest $request, SettingsDiffEngine $engine, ExplanationGenerator $explanations, UsageQuota $quota): JsonResponse
    {
        $user = $request->user();

        $quota->ensureWithinLimit($user, 'reverse');

        try {
            $game = $user->games()->findOrFail($request->validated('game_id'));
        } catch (ModelNotFoundException) {
            return response()->json(null, 404);
        }

        $gpu = Gpu::query()->findOrFail($request->validated('gpu_id'));
        $cpu = Cpu::query()->findOrFail($request->validated('cpu_id'));
        $goal = $request->validated('goal');

        $result = $engine->diff(
            $game,
            $gpu,
            $cpu,
            (int) $request->validated('ram_gb'),
            $goal,
            $request->validated('current_settings'),
        );

        $fallback = $this->fallbackExplanation($result['diff'], $result['recommendation'], $goal);

        $quota->record($user, 'reverse');

        return response()->json([
            'data' => [
                'game_id' => $game->id,
                'goal' => $goal,
                ...$result,
                'explanation' => $explanations->reverse($result['diff'], $result['recommendation'], $goal, $fallback),
            ],
        ]);
    }
```

Leave the `fallbackExplanation()` private method below it unchanged.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ReverseEndpointTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ReverseController.php tests/Feature/Recommendations/ReverseEndpointTest.php
git commit -m "[Sprint 5] enforce free-tier reverse-mode quota"
```

---

## Task 5: `GET /api/usage` endpoint

**Files:**
- Create: `app/Http/Controllers/UsageController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Billing/UsageEndpointTest.php`

**Interfaces:**
- Consumes: `UsageQuota::used()`, `limit()`, `remaining()` (Task 2).
- Produces: `GET /api/usage` (auth:sanctum) → JSON:
  ```json
  { "data": {
      "is_premium": false,
      "window_days": 30,
      "recommend": { "used": 1, "limit": 3, "remaining": 2 },
      "reverse":   { "used": 0, "limit": 5, "remaining": 5 }
  } }
  ```
  For premium users, `limit` and `remaining` are `null`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Billing/UsageEndpointTest.php`:

```php
<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_rejected_401(): void
    {
        $this->getJson('/api/usage')->assertStatus(401);
    }

    public function test_free_user_sees_counts_and_limits(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);

        $this->actingAs($user)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('data.is_premium', false)
            ->assertJsonPath('data.window_days', 30)
            ->assertJsonPath('data.recommend.used', 1)
            ->assertJsonPath('data.recommend.limit', 3)
            ->assertJsonPath('data.recommend.remaining', 2)
            ->assertJsonPath('data.reverse.used', 0)
            ->assertJsonPath('data.reverse.limit', 5)
            ->assertJsonPath('data.reverse.remaining', 5);
    }

    public function test_premium_user_sees_null_limits(): void
    {
        $user = User::factory()->create(['is_premium' => true]);

        $this->actingAs($user)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('data.is_premium', true)
            ->assertJsonPath('data.recommend.limit', null)
            ->assertJsonPath('data.recommend.remaining', null)
            ->assertJsonPath('data.reverse.limit', null)
            ->assertJsonPath('data.reverse.remaining', null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UsageEndpointTest`
Expected: FAIL — route `/api/usage` not defined (404/405).

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/UsageController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Services\UsageQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function show(Request $request, UsageQuota $quota): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'is_premium' => (bool) $user->is_premium,
                'window_days' => UsageQuota::WINDOW_DAYS,
                'recommend' => $this->line($quota, $user, 'recommend'),
                'reverse' => $this->line($quota, $user, 'reverse'),
            ],
        ]);
    }

    /**
     * @return array{used: int, limit: int|null, remaining: int|null}
     */
    private function line(UsageQuota $quota, \App\Models\User $user, string $type): array
    {
        return [
            'used' => $quota->used($user, $type),
            'limit' => $user->is_premium ? null : $quota->limit($type),
            'remaining' => $quota->remaining($user, $type),
        ];
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, add the import at the top with the other controller imports:

```php
use App\Http\Controllers\UsageController;
```

Inside the existing `Route::middleware('auth:sanctum')->group(function (): void { ... })` block, add:

```php
    Route::get('/usage', [UsageController::class, 'show'])
        ->name('usage.show');
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --filter=UsageEndpointTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/UsageController.php routes/api.php tests/Feature/Billing/UsageEndpointTest.php
git commit -m "[Sprint 5] add GET /api/usage quota endpoint"
```

---

## Task 6: Stripe config + `POST /api/checkout`

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`
- Create: `app/Http/Controllers/CheckoutController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Billing/CheckoutEndpointTest.php`

**Interfaces:**
- Consumes: Cashier's `Billable::newSubscription()->checkout()` (already available on `User`); `config('services.stripe.price')`.
- Produces: `POST /api/checkout` (auth:sanctum, throttle:6,1) → `{ "url": "https://checkout.stripe.com/..." }`. Returns 500 with a clear message if the price id is unconfigured. The Stripe happy path is verified manually via the Stripe CLI (Task 9 docs), not in an automated test, because Cashier's checkout call hits the live Stripe API through the Stripe SDK.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Billing/CheckoutEndpointTest.php`:

```php
<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_rejected_401(): void
    {
        $this->postJson('/api/checkout')->assertStatus(401);
    }

    public function test_unconfigured_price_returns_500_with_a_clear_message(): void
    {
        config(['services.stripe.price' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/checkout')
            ->assertStatus(500)
            ->assertJsonPath('error_code', 'stripe_not_configured');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CheckoutEndpointTest`
Expected: FAIL — route `/api/checkout` not defined.

- [ ] **Step 3: Add Stripe config**

In `config/services.php`, add a `stripe` block after the `gemini` block (before the closing `];`):

```php
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'price' => env('STRIPE_PRICE_PREMIUM'),
    ],
```

In `.env.example`, under the existing `# --- Stripe (Phase 5) ---` block, add:

```
STRIPE_PRICE_PREMIUM=
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/CheckoutController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $price = config('services.stripe.price');

        if (blank($price)) {
            return response()->json([
                'error_code' => 'stripe_not_configured',
                'message' => 'The Cortex Premium price is not configured.',
            ], 500);
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $checkout = $request->user()
            ->newSubscription('default', $price)
            ->checkout([
                'success_url' => $frontend . '/dashboard?checkout=success',
                'cancel_url' => $frontend . '/dashboard?checkout=cancelled',
            ]);

        return response()->json(['url' => $checkout->url]);
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/api.php`, add the import:

```php
use App\Http\Controllers\CheckoutController;
```

Inside the `auth:sanctum` group, add:

```php
    Route::post('/checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('checkout');
```

- [ ] **Step 6: Run the test**

Run: `php artisan test --filter=CheckoutEndpointTest`
Expected: PASS (2 tests). The guest test hits the auth wall; the unconfigured-price test short-circuits before any Stripe call.

- [ ] **Step 7: Commit**

```bash
git add config/services.php .env.example app/Http/Controllers/CheckoutController.php routes/api.php tests/Feature/Billing/CheckoutEndpointTest.php
git commit -m "[Sprint 5] add Stripe checkout endpoint for Cortex Premium"
```

---

## Task 7: Stripe webhook — sync `is_premium`, verify signatures

**Files:**
- Create: `app/Http/Controllers/StripeWebhookController.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/api.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Billing/StripeWebhookTest.php`

**Interfaces:**
- Consumes: Cashier's `WebhookController`, `Cashier::findBillable()`, `Billable::subscribed()`; the existing `subscriptions` table.
- Produces: `POST /api/stripe/webhook` (unauthenticated, CSRF-exempt, signature-verified) named `cashier.webhook`. Wrong/missing signature (when a secret is configured) → **400**. Cashier's parent handlers keep the `subscriptions` table current; our overrides then set `users.is_premium = subscribed('default')`. Cashier's own auto-registered webhook route is disabled via `Cashier::ignoreRoutes()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Billing/StripeWebhookTest.php`:

```php
<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_signature_is_rejected_with_400(): void
    {
        config(['cashier.webhook.secret' => 'whsec_test_secret']);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_test',
            'type' => 'customer.subscription.deleted',
        ], ['Stripe-Signature' => 't=1,v1=deadbeef'])
            ->assertStatus(400);
    }

    public function test_subscription_deleted_flips_is_premium_to_false(): void
    {
        // No signing secret configured => signature check is skipped, so we can
        // drive the handler logic directly. (Signature enforcement is covered above.)
        config(['cashier.webhook.secret' => null]);

        $user = User::factory()->create([
            'is_premium' => true,
            'stripe_id' => 'cus_test123',
        ]);

        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $this->assertTrue($user->fresh()->subscribed('default'));

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_test',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_test123',
                    'customer' => 'cus_test123',
                    'status' => 'canceled',
                ],
            ],
        ])->assertOk();

        $this->assertFalse($user->fresh()->is_premium);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StripeWebhookTest`
Expected: FAIL — `/api/stripe/webhook` not defined; `is_premium` not synced.

- [ ] **Step 3: Disable Cashier's default webhook route**

In `app/Providers/AppServiceProvider.php`, add the import at the top:

```php
use Laravel\Cashier\Cashier;
```

In the `register()` method (currently just `//`), replace the body with:

```php
    public function register(): void
    {
        // We register our own webhook route at /api/stripe/webhook (so the
        // CloudFront cache-behavior carve-out and the /api prefix line up) and
        // extend Cashier's WebhookController to also sync users.is_premium.
        Cashier::ignoreRoutes();
    }
```

- [ ] **Step 4: Create the webhook controller**

`app/Http/Controllers/StripeWebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook as StripeWebhook;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class StripeWebhookController extends CashierWebhookController
{
    /**
     * Verify the Stripe signature (400 on failure), then defer to Cashier's
     * event dispatch, which calls our overridden subscription handlers below.
     */
    public function handleWebhook(Request $request): Response
    {
        $secret = config('cashier.webhook.secret');

        if (! empty($secret)) {
            try {
                StripeWebhook::constructEvent(
                    $request->getContent(),
                    (string) $request->header('Stripe-Signature'),
                    $secret,
                );
            } catch (SignatureVerificationException|UnexpectedValueException) {
                return new Response('Invalid webhook signature.', 400);
            }
        }

        return parent::handleWebhook($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionCreated($payload);
        $this->syncPremium($payload);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload);
        $this->syncPremium($payload);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);
        $this->syncPremium($payload);

        return $response;
    }

    /**
     * Denormalize subscription state onto the fast-read is_premium flag.
     * Stripe (via Cashier's subscriptions table) remains the source of truth.
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncPremium(array $payload): void
    {
        $customerId = $payload['data']['object']['customer'] ?? null;

        if ($customerId === null) {
            return;
        }

        $user = Cashier::findBillable($customerId);

        if ($user === null) {
            return;
        }

        // fresh() reloads the subscriptions relation written by the parent handler.
        $user->forceFill(['is_premium' => $user->fresh()->subscribed('default')])->save();
    }
}
```

- [ ] **Step 5: Register the route + CSRF exemption**

In `routes/api.php`, add the import:

```php
use App\Http\Controllers\StripeWebhookController;
```

At the top level of `routes/api.php` (NOT inside the `auth:sanctum` group — the webhook is unauthenticated), add:

```php
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');
```

> The `name('cashier.webhook')` keeps any internal Cashier `route('cashier.webhook')` lookups resolving.

In `bootstrap/app.php`, inside the `->withMiddleware(function (Middleware $middleware): void { ... })` closure, add a defensive CSRF exemption (api routes already skip CSRF; this documents intent and covers any future web-group move):

```php
        $middleware->validateCsrfTokens(except: [
            'api/stripe/webhook',
        ]);
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=StripeWebhookTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Run the full backend suite (regression gate)**

Run: `make test`
Expected: PASS — all prior tests (234+) plus the new billing/quota tests. Fix any regression before continuing.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/StripeWebhookController.php app/Providers/AppServiceProvider.php routes/api.php bootstrap/app.php tests/Feature/Billing/StripeWebhookTest.php
git commit -m "[Sprint 5] sync is_premium from signature-verified Stripe webhook"
```

---

## Task 8: React — usage counters, upgrade button, soft-lock state

**Files:**
- Create: `client/src/lib/usage.js`
- Modify: `client/src/pages/Dashboard.jsx`
- Verify: `cd client && npm run lint && npm run build` (no frontend test framework in this repo — matches prior slices)

**Interfaces:**
- Consumes: `GET /api/usage` (Task 5), `POST /api/checkout` (Task 6), `user.is_premium` from `useAuth()` (`/api/me`, Task 1).
- Produces: A "Cortex Premium" dashboard section showing per-mode counters ("1 / 3 recommendations used in the last 30 days"), an Upgrade button (redirects to Stripe Checkout), a premium badge for subscribers, and a soft-lock note when a free user is at a cap. Reads `?checkout=success|cancelled` query params to surface a post-redirect notice.

- [ ] **Step 1: Create the usage API helper**

`client/src/lib/usage.js`:

```js
import { api } from './api'

export async function getUsage({ signal } = {}) {
  const { data } = await api.get('/api/usage', { signal })
  return data.data
}

export async function startCheckout() {
  const { data } = await api.post('/api/checkout')
  return data.url
}
```

- [ ] **Step 2: Add the usage section to the Dashboard**

Edit `client/src/pages/Dashboard.jsx`. Add imports near the existing ones:

```js
import { getUsage, startCheckout } from '../lib/usage'
```

Inside the `Dashboard` component, add state and effects after the existing `useState` declarations:

```js
  const [usage, setUsage] = useState(null)
  const [upgrading, setUpgrading] = useState(false)
  const [checkoutNotice, setCheckoutNotice] = useState(() => {
    const status = searchParams.get('checkout')
    if (status === 'success') {
      return { type: 'success', message: 'Thanks for subscribing! Premium unlocks shortly after Stripe confirms payment.' }
    }
    if (status === 'cancelled') {
      return { type: 'error', message: 'Checkout cancelled — no charge was made.' }
    }
    return null
  })
```

Extend the existing query-param cleanup effect to also clear `checkout`. Replace the existing effect at lines ~35-42 with:

```js
  useEffect(() => {
    if (searchParams.has('steam_connected') || searchParams.has('steam_error') || searchParams.has('checkout')) {
      const next = new URLSearchParams(searchParams)
      next.delete('steam_connected')
      next.delete('steam_error')
      next.delete('checkout')
      setSearchParams(next, { replace: true })
    }
  }, [searchParams, setSearchParams])
```

Add an effect to load usage on mount:

```js
  useEffect(() => {
    const controller = new AbortController()
    getUsage({ signal: controller.signal })
      .then(setUsage)
      .catch(() => {})
    return () => controller.abort()
  }, [])
```

Add the upgrade handler alongside the other `async function` handlers:

```js
  async function handleUpgrade() {
    setUpgrading(true)
    try {
      const url = await startCheckout()
      window.location.assign(url)
    } catch {
      setCheckoutNotice({ type: 'error', message: 'Could not start checkout. Please try again.' })
      setUpgrading(false)
    }
  }
```

- [ ] **Step 3: Render the usage section**

In the returned JSX, add this `<section>` immediately after the `<ActiveSessionBanner />` / `<VerifiedBanner />` lines (before the "Welcome" section):

```jsx
      <section className="rounded-md border border-slate-200 p-6">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-medium">Cortex Premium</h2>
          {user.is_premium ? (
            <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">
              Premium
            </span>
          ) : null}
        </div>

        {checkoutNotice ? (
          <div
            className={`mt-3 rounded-md border p-3 text-sm ${
              checkoutNotice.type === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                : 'border-rose-200 bg-rose-50 text-rose-900'
            }`}
          >
            {checkoutNotice.message}
          </div>
        ) : null}

        {user.is_premium ? (
          <p className="mt-2 text-sm text-slate-600">
            You have unlimited recommendations and reverse-mode calls. Thanks for supporting Cortex Lite.
          </p>
        ) : usage ? (
          <div className="mt-3 space-y-3">
            <UsageMeter label="Recommendations" line={usage.recommend} windowDays={usage.window_days} />
            <UsageMeter label="Reverse-mode calls" line={usage.reverse} windowDays={usage.window_days} />
            {usage.recommend.remaining === 0 || usage.reverse.remaining === 0 ? (
              <p className="text-sm text-rose-700">
                You've hit a free-tier cap. Upgrade for unlimited access.
              </p>
            ) : null}
            <Button type="button" onClick={handleUpgrade} disabled={upgrading}>
              {upgrading ? 'Starting checkout…' : 'Upgrade to Premium — $5/mo'}
            </Button>
          </div>
        ) : (
          <p className="mt-2 text-sm text-slate-500">Loading usage…</p>
        )}
      </section>
```

Add the `UsageMeter` presentational component at the bottom of the file, after the `Dashboard` function:

```jsx
function UsageMeter({ label, line, windowDays }) {
  const atCap = line.remaining === 0
  return (
    <div>
      <div className="flex justify-between text-sm">
        <span className="font-medium text-slate-700">{label}</span>
        <span className={atCap ? 'text-rose-700' : 'text-slate-600'}>
          {line.used} / {line.limit} used in the last {windowDays} days
        </span>
      </div>
      <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100">
        <div
          className={`h-full ${atCap ? 'bg-rose-500' : 'bg-slate-700'}`}
          style={{ width: `${Math.min(100, (line.used / line.limit) * 100)}%` }}
        />
      </div>
    </div>
  )
}
```

- [ ] **Step 4: Lint and build**

Run: `cd client && npm run lint && npm run build`
Expected: PASS. `oxlint` may emit the pre-existing `AuthContext.jsx` fast-refresh warning only (documented in prior slices) — no new errors. `npm run build` completes.

- [ ] **Step 5: Commit**

```bash
git add client/src/lib/usage.js client/src/pages/Dashboard.jsx
git commit -m "[Sprint 5] add premium usage counters and upgrade UI"
```

---

## Task 9: Documentation + phase tracker

**Files:**
- Modify: `docs/DECISIONS.md`
- Modify: `docs/TROUBLESHOOTING.md`
- Modify: `CLAUDE.md` (phase tracker + check off Phase 5 gating spec items in the build plan)
- Modify: `docs/cortex-lite-build-plan.md` (check off the Stripe gating checkboxes)
- Modify: `README.md` (sprint changelog)

**Interfaces:** None (docs only).

- [ ] **Step 1: Add DECISIONS.md entries**

Append these ADR entries to `docs/DECISIONS.md` (match the existing entry format):

```markdown
### Rolling 30-day quota via event-table count (no counter column, no reset job)
**Date:** 2026-07-06
**Decision:** Free-tier limits (3 recommendations, 5 reverse-mode calls) are enforced by counting rows in a `usage_events` table where `created_at >= now() - interval 30 day`, per `type`. No per-user counter column and no scheduled reset job.
**Rationale:** A rolling window is fairer than a calendar reset and needs no cron. A count query on an indexed `(user_id, type, created_at)` tuple is cheap. Avoids the thundering-herd of a monthly reset job.
**Alternatives considered:** A counter column reset monthly (needs a reset job, has a herd spike, and a rolling window is a better UX); Redis counters (adds a consistency-with-billing failure mode for no real gain here).
**Consequences:** Every successful call writes one row; the table grows unbounded but is trivially prunable later. Premium users still log events (for the usage view) but bypass the cap.

### Premium status: denormalized is_premium synced from Cashier webhook
**Date:** 2026-07-06
**Decision:** Billing rides on Laravel Cashier (the `subscriptions` table is the source of truth). A denormalized `users.is_premium` boolean is the fast read path for the quota check, written only by a webhook controller that extends Cashier's `WebhookController` and sets `is_premium = subscribed('default')` on subscription created/updated/deleted.
**Rationale:** The quota check runs on every optimizer call; reading a boolean column beats loading the subscriptions relation each time. Extending Cashier reuses its signature verification and subscription bookkeeping instead of hand-rolling both. Cashier's existing `stripe_id` column already serves as the Stripe customer id, so no separate `stripe_customer_id` was added.
**Alternatives considered:** Hand-rolled webhook + manual signature verification (re-implements Cashier, more surface for bugs); gating purely on `subscribed('default')` with no `is_premium` column (an extra relation load per request and diverges from the spec/tests).
**Consequences:** `is_premium` is a cache that can briefly lag Stripe between the event and the webhook; the webhook is the only writer. `Cashier::ignoreRoutes()` disables Cashier's default webhook route so ours at `/api/stripe/webhook` (aligned with the CloudFront carve-out) is authoritative.

### Sync (not async) LLM explanation call for v1
**Date:** 2026-07-06
**Decision:** The optimizer endpoints call the explanation generator synchronously within the request.
**Rationale:** On a cache hit it's instant; on a cold miss it's ~2–3s, acceptable behind a UI loading state. Async + polling is more code for a cold-path-only win.
**Alternatives considered:** Queue + client polling (snappier UX, more moving parts — deferred).
**Consequences:** Cold-path requests block for the LLM round-trip; mitigated by aggressive Redis caching and the static fallback on failure.
```

- [ ] **Step 2: Add TROUBLESHOOTING.md entry**

Append to `docs/TROUBLESHOOTING.md`:

```markdown
### Testing Stripe webhooks locally (test mode)
**Cause:** Premium status only flips when Stripe delivers a signed webhook to `/api/stripe/webhook`; there is no way to exercise the happy path from an automated test (it needs a real signature).
**Fix:** Use the Stripe CLI in test mode:
1. `stripe login`
2. `stripe listen --forward-to localhost/api/stripe/webhook` — copy the `whsec_...` it prints into `STRIPE_WEBHOOK_SECRET` (or `CASHIER_WEBHOOK_SECRET`).
3. `stripe trigger checkout.session.completed` (or `customer.subscription.deleted`).
4. Confirm `users.is_premium` flips. A wrong/absent signature returns HTTP 400; a missing `whsec` secret means our controller skips verification (fine for local runs, never for production).
```

- [ ] **Step 3: Update the phase tracker and checklists**

- In `CLAUDE.md`, the Phase 5 tracker line stays as-is until the phase-completing PR merges; if this is that PR, change `- [ ] Phase 5 — AI optimizer + Stripe freemium` to `- [x]`.
- In `docs/cortex-lite-build-plan.md`, check off the "Stripe premium gating (rolling 30-day window)" checkboxes that this plan delivered (`is_premium` column, usage table, Cashier install already done, `/api/checkout`, webhook + signature, free/premium tiers, both webhook test cases, React usage counters/upgrade/soft-lock).
- In `README.md`, add a sprint changelog line: `[Sprint 5] Stripe premium gating — rolling 30-day free-tier quotas (3 recommendations / 5 reverse-mode calls), $5/mo Cortex Premium via Stripe Checkout, is_premium synced from signature-verified webhooks.`

- [ ] **Step 4: Commit**

```bash
git add docs/DECISIONS.md docs/TROUBLESHOOTING.md CLAUDE.md docs/cortex-lite-build-plan.md README.md
git commit -m "[Sprint 5] document Stripe premium gating decisions and troubleshooting"
```

---

## Final verification (run after all tasks)

- [ ] `make test` — full PHPUnit suite green (all prior tests + new billing/quota tests).
- [ ] `cd client && npm run lint && npm run build` — green (only the known pre-existing `AuthContext.jsx` fast-refresh warning).
- [ ] `git diff --check` — no whitespace errors.
- [ ] Manual Stripe CLI smoke test per the new TROUBLESHOOTING entry (optional but recommended before the Phase 6 deploy).

---

## Self-Review (completed against the spec)

**Spec coverage** (build-plan "Stripe premium gating" bullets):
- `is_premium`, no counter column → Task 1 (added `is_premium`; `stripe_customer_id` intentionally satisfied by Cashier's `stripe_id`, flagged in Global Constraints).
- `usage_events` table with `type`, rolling-window count → Tasks 1 + 2.
- Install Cashier → already installed (Billable trait + migrations present); no task needed, noted.
- `POST /api/checkout` $5/mo subscription → Task 6.
- Webhook flips `is_premium` on create/cancel, verify signatures → Task 7.
- CSRF-exempt webhook route → Task 7 (api group + defensive `except`).
- Free tier (3 rec / 5 reverse, no catalog restriction) → Tasks 3, 4.
- Premium unlimited → Tasks 3, 4 (premium bypass).
- Two webhook tests (wrong-signature, cancellation→false) → Task 7.
- React usage counters + upgrade + soft-lock → Task 8.
- Docs (rolling-window, LLM-safety, sync choice; Stripe CLI walkthrough) → Task 9.

**Placeholder scan:** No TBD/TODO/"add validation"/"similar to Task N" — every code step shows complete code.

**Type consistency:** `UsageQuota` method names (`used`/`limit`/`remaining`/`ensureWithinLimit`/`record`) are used identically in Tasks 3–5; `QuotaExceededException` public props (`type`/`limit`/`used`) match between Task 2 (definition), the 402 render, and Task 3/4 assertions; `/api/usage` JSON shape (`data.recommend.{used,limit,remaining}`) matches between Task 5 controller, its test, and the Task 8 `UsageMeter` consumption (`line.used`/`line.limit`/`line.remaining`).
