# Phase 6 — AWS Deployment + Native Agent Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship every agent-buildable Phase 6 artifact — the native-agent contract doc, production Docker images, a Parameter-Store secrets entrypoint, a production compose file, a self-resetting demo account, deploy helper scripts, an AWS Console runbook, and the phase-ending doc updates — so the operator's remaining work is *following steps* against their own AWS account.

**Architecture:** Production containers bake code into the image (no bind mount on EC2) and pull secrets at boot from AWS Systems Manager Parameter Store via an in-container PHP entrypoint that uses the EC2 instance role (IMDSv2) — secrets never touch disk. A deterministic demo account reseeds nightly from a committed fixture. All AWS provisioning is manual-console, documented in a numbered runbook; no Terraform.

**Tech Stack:** Laravel 13 / PHP 8.4, `aws/aws-sdk-php` (SSM client), Docker + Docker Compose, alpine images, PHPUnit, AWS (EC2 t3.small, RDS MySQL, ECR, CloudFront, Parameter Store, CloudWatch).

## Global Constraints

- Sprint-tag every commit: `[Sprint 6] <summary>`.
- No `.env` files on disk in production — secrets injected at container start from Parameter Store, in-memory only.
- Production images must stay under 500 MB; `.dockerignore` must be in effect.
- Never break the dev topology: `docker-compose.yml`, `docker/app/Dockerfile` (dev, root FPM, bind-mount) stay working unchanged.
- The LLM never decides settings; no code here touches the recommendation path.
- Multi-write operations wrapped in a DB transaction (the demo reset qualifies).
- Pricing copy is MYR (RM20/month); demo test card is `4242 4242 4242 4242`.
- Gemini model stays pinned: `gemini-3.5-flash`.
- Run tests via `make test`; run artisan via `make artisan CMD="..."`; add composer deps via `make composer CMD="..."`.
- Phase ends only after DECISIONS.md / TROUBLESHOOTING.md / ARCHITECTURE.md / README.md are updated (Tasks 8–9).
- There is **no `recommendations` table** — recommendation and reverse-mode usage is recorded in `usage_events` by `type`. The demo reset wipes `games` (which cascade-deletes `play_sessions`) and `usage_events`.

---

## File Structure

**Create:**
- `NATIVE_AGENT_CONTRACT.md` — portfolio artifact (repo root).
- `app/Support/ShellEnv.php` — pure shell-export escaping helper (unit-testable).
- `app/Console/Commands/SsmExportCommand.php` — `ssm:export`, prints `export KEY='value'` from Parameter Store.
- `app/Providers/SsmServiceProvider.php` — binds a configured `Aws\Ssm\SsmClient`.
- `app/Services/Demo/DemoAccountProvisioner.php` — shared demo create/reset/reseed logic.
- `app/Console/Commands/ResetDemoAccountCommand.php` — `demo:reset`.
- `database/data/demo_library.json` — deterministic demo library fixture.
- `database/seeders/DemoAccountSeeder.php` — seeds the demo account via the provisioner.
- `docker/app/Dockerfile.prod` — production PHP-FPM image (code baked, non-root, entrypoint).
- `docker/app/entrypoint.prod.sh` — fetch-secrets-then-exec entrypoint.
- `docker-compose.prod.yml` — production topology (no mysql; RDS).
- `scripts/ecr-push.sh`, `scripts/ec2-bootstrap.sh` — deploy helpers.
- `docs/DEPLOYMENT.md` — AWS Console runbook.
- Tests: `tests/Unit/ShellEnvTest.php`, `tests/Feature/Console/SsmExportCommandTest.php`, `tests/Feature/Demo/DemoAccountProvisionerTest.php`, `tests/Feature/Console/ResetDemoAccountCommandTest.php`.

**Modify:**
- `composer.json` — add `aws/aws-sdk-php`.
- `config/services.php` — add `ssm` config block (region + path).
- `bootstrap/providers.php` — register `SsmServiceProvider`.
- `bootstrap/app.php` — schedule `demo:reset` nightly.
- `database/seeders/DatabaseSeeder.php` — (do NOT auto-run demo seeder in tests; leave as-is; demo seed is called explicitly during deploy).
- `.env.example` — add SSM/AWS deployment vars.
- `docs/ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/TROUBLESHOOTING.md`, `README.md`.

---

## Task 1: Native Agent Contract artifact

**Files:**
- Create: `NATIVE_AGENT_CONTRACT.md`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing (documentation only).

- [ ] **Step 1: Write the artifact**

Create `NATIVE_AGENT_CONTRACT.md`:

```markdown
# Native Agent Contract (Hypothetical)

> Cortex Lite is the web/cloud companion layer of a Cortex-style product. The
> native agent that would collect real hardware telemetry and detect game
> launches is **out of scope** — the browser security model makes it impossible
> to build in the web layer. This document is the contract the web layer would
> expose to that agent: the half we did not build, made as legible as the half
> we did.

## 1. Scope

| Concern | Owner |
|---|---|
| Exact GPU/CPU model, driver version, VRAM, live clocks/temps | Native agent |
| Running-process / game-launch detection | Native agent |
| Wall-clock session start/stop from the OS | Native agent |
| Library, manual sessions, recommendations, billing, auth | Web layer (this app) |
| Settings recommendation logic + LLM prose | Web layer (deterministic engine + Gemini) |

The agent **observes and reports**. It never decides settings and never renders
UI; the web layer owns all product logic.

## 2. Authentication & transport

- Transport: **mTLS**. The agent holds a device certificate issued at pairing;
  the web layer pins the issuing CA.
- Message integrity: each payload is a **signed JWT** (EdDSA), `sub` = device
  id, `iat`/`exp` set, signed with the device key registered at pairing.
- The web layer **never trusts an unsigned or expired payload** and rejects any
  device id not in the paired-devices table (HTTP 401).

## 3. Payload schema

### 3.1 Hardware snapshot (on change / daily)
```json
{
  "type": "hardware_snapshot",
  "device_id": "d_9f3...",
  "captured_at": "2026-07-09T10:00:00Z",
  "gpu": { "model": "NVIDIA GeForce RTX 4070", "vram_mb": 12288, "driver": "556.12" },
  "cpu": { "model": "AMD Ryzen 5 7600X", "cores": 6, "threads": 12 },
  "ram_mb": 32768
}
```

### 3.2 Running-game detection (event)
```json
{
  "type": "game_detected",
  "device_id": "d_9f3...",
  "observed_at": "2026-07-09T20:14:03Z",
  "steam_app_id": 1091500,
  "state": "launched"        // launched | closed
}
```

### 3.3 Session event (event)
```json
{
  "type": "session_event",
  "device_id": "d_9f3...",
  "steam_app_id": 1091500,
  "started_at": "2026-07-09T20:14:03Z",
  "ended_at": "2026-07-09T21:02:51Z"
}
```

## 4. Update cadence

- Hardware snapshot: on detected change, plus a daily heartbeat.
- Game/session events: pushed within 5s of the OS event; buffered locally and
  replayed if the web layer is unreachable (local-first).

## 5. Privacy

- **Opt-in** per telemetry category; the agent ships collecting nothing.
- **No PII to the LLM** — only hardware tiers and settings structures reach
  Gemini, never device ids or account identifiers.
- **Local-first caching** — the agent keeps its own buffer and uploads
  aggregates; raw process lists never leave the device.

## 6. Security boundaries

- The agent never executes web-layer-supplied code; the contract is data-only.
- The web layer treats every field as untrusted input: validates the signature,
  then validates the payload against this schema before persisting.
- Steam app ids from the agent are reconciled against the user's owned library;
  an app id the user does not own is dropped, not auto-added.
```

- [ ] **Step 2: Verify it renders and commit**

Run: `git add NATIVE_AGENT_CONTRACT.md && git diff --cached --stat`
Expected: one new file staged.

```bash
git commit -m "[Sprint 6] add NATIVE_AGENT_CONTRACT.md agent payload contract"
```

---

## Task 2: Shell-export escaping helper

**Files:**
- Create: `app/Support/ShellEnv.php`
- Test: `tests/Unit/ShellEnvTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Support\ShellEnv::export(string $name, string $value): string` — returns one POSIX-safe `export NAME='...'` line, single-quotes escaped as `'\''`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ShellEnvTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\ShellEnv;
use PHPUnit\Framework\TestCase;

class ShellEnvTest extends TestCase
{
    public function test_wraps_value_in_single_quotes(): void
    {
        $this->assertSame(
            "export STRIPE_SECRET='sk_test_123'",
            ShellEnv::export('STRIPE_SECRET', 'sk_test_123'),
        );
    }

    public function test_escapes_embedded_single_quote(): void
    {
        // A value containing a single quote must not break out of the quotes.
        $this->assertSame(
            "export PW='a'\\''b'",
            ShellEnv::export('PW', "a'b"),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make artisan CMD="test --filter=ShellEnvTest"` (or `make test`)
Expected: FAIL — class `App\Support\ShellEnv` not found.

- [ ] **Step 3: Write minimal implementation**

Create `app/Support/ShellEnv.php`:

```php
<?php

namespace App\Support;

class ShellEnv
{
    /**
     * Render one POSIX-safe `export NAME='value'` line. Single quotes in the
     * value are escaped as '\'' so the value cannot break out of the quoting.
     */
    public static function export(string $name, string $value): string
    {
        $escaped = str_replace("'", "'\\''", $value);

        return "export {$name}='{$escaped}'";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make test` (filtered to `ShellEnvTest` if supported)
Expected: PASS (2 assertions).

- [ ] **Step 5: Commit**

```bash
git add app/Support/ShellEnv.php tests/Unit/ShellEnvTest.php
git commit -m "[Sprint 6] add ShellEnv POSIX export escaping helper"
```

---

## Task 3: AWS SDK dependency + SSM client binding

**Files:**
- Modify: `composer.json` (via composer), `config/services.php`, `bootstrap/providers.php`
- Create: `app/Providers/SsmServiceProvider.php`

**Interfaces:**
- Consumes: nothing.
- Produces: an `Aws\Ssm\SsmClient` resolvable from the container; `config('services.ssm.region')` and `config('services.ssm.path')`.

- [ ] **Step 1: Add the SDK**

Run: `make composer CMD="require aws/aws-sdk-php"`
Expected: `composer.json` gains `aws/aws-sdk-php`, `composer.lock` updates, autoload dumps.

- [ ] **Step 2: Add SSM config**

Modify `config/services.php` — add before the closing `];`:

```php
    'ssm' => [
        'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
        'path' => env('SSM_PARAMETER_PATH', '/cortex-lite/'),
    ],
```

- [ ] **Step 3: Create the provider**

Create `app/Providers/SsmServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Aws\Ssm\SsmClient;
use Illuminate\Support\ServiceProvider;

class SsmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Credentials are auto-discovered from the EC2 instance role (IMDSv2)
        // in production. No static keys are configured here on purpose.
        $this->app->singleton(SsmClient::class, fn () => new SsmClient([
            'region' => config('services.ssm.region'),
            'version' => '2014-11-06',
        ]));
    }
}
```

- [ ] **Step 4: Register the provider**

Modify `bootstrap/providers.php` — add `App\Providers\SsmServiceProvider::class,` to the returned array.

- [ ] **Step 5: Verify the app still boots**

Run: `make artisan CMD="config:clear"` then `make artisan CMD="about"`
Expected: no exceptions; `about` prints normally.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock config/services.php app/Providers/SsmServiceProvider.php bootstrap/providers.php
git commit -m "[Sprint 6] add aws-sdk-php and SSM client binding"
```

---

## Task 4: `ssm:export` command

**Files:**
- Create: `app/Console/Commands/SsmExportCommand.php`
- Test: `tests/Feature/Console/SsmExportCommandTest.php`

**Interfaces:**
- Consumes: `Aws\Ssm\SsmClient` (Task 3), `App\Support\ShellEnv::export()` (Task 2).
- Produces: artisan command `ssm:export` writing `export KEY='value'` lines (one per Parameter Store param under the configured path, leaf-name → env var) to stdout.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/SsmExportCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use Aws\MockHandler;
use Aws\Result;
use Aws\Ssm\SsmClient;
use Tests\TestCase;

class SsmExportCommandTest extends TestCase
{
    public function test_prints_export_lines_for_each_parameter(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'Parameters' => [
                ['Name' => '/cortex-lite/STRIPE_SECRET', 'Value' => 'sk_test_123'],
                ['Name' => '/cortex-lite/DB_PASSWORD', 'Value' => "p'wd"],
            ],
            'NextToken' => null,
        ]));

        $this->app->instance(SsmClient::class, new SsmClient([
            'region' => 'ap-southeast-1',
            'version' => '2014-11-06',
            'handler' => $mock,
        ]));

        $this->artisan('ssm:export')
            ->expectsOutput("export STRIPE_SECRET='sk_test_123'")
            ->expectsOutput("export DB_PASSWORD='p'\\''wd'")
            ->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make artisan CMD="test --filter=SsmExportCommandTest"`
Expected: FAIL — command `ssm:export` not defined.

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/SsmExportCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Support\ShellEnv;
use Aws\Ssm\SsmClient;
use Illuminate\Console\Command;

class SsmExportCommand extends Command
{
    protected $signature = 'ssm:export';

    protected $description = 'Print Parameter Store secrets as shell export lines (in-memory secret injection).';

    public function handle(SsmClient $ssm): int
    {
        $path = config('services.ssm.path');
        $nextToken = null;

        do {
            $args = [
                'Path' => $path,
                'WithDecryption' => true,
                'Recursive' => true,
            ];

            if ($nextToken !== null) {
                $args['NextToken'] = $nextToken;
            }

            $result = $ssm->getParametersByPath($args);

            foreach ($result['Parameters'] ?? [] as $param) {
                $name = basename($param['Name']);
                $this->line(ShellEnv::export($name, $param['Value']));
            }

            $nextToken = $result['NextToken'] ?? null;
        } while ($nextToken !== null);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make artisan CMD="test --filter=SsmExportCommandTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SsmExportCommand.php tests/Feature/Console/SsmExportCommandTest.php
git commit -m "[Sprint 6] add ssm:export command for in-memory secret injection"
```

---

## Task 5: Production entrypoint + production Docker image

**Files:**
- Create: `docker/app/entrypoint.prod.sh`, `docker/app/Dockerfile.prod`

**Interfaces:**
- Consumes: `ssm:export` (Task 4).
- Produces: a production PHP-FPM image with code baked in, running non-root, whose entrypoint sources SSM secrets then execs the container command.

- [ ] **Step 1: Write the entrypoint**

Create `docker/app/entrypoint.prod.sh`:

```bash
#!/bin/sh
set -e

# Pull secrets from Parameter Store into this process's environment only.
# Requires the EC2 instance role (IMDSv2) to grant ssm:GetParametersByPath.
# Set SSM_SKIP=1 to bypass (local image smoke-tests only — never in prod).
if [ "${SSM_SKIP:-0}" != "1" ]; then
    eval "$(php /var/www/html/artisan ssm:export)"
fi

# Cache config/routes now that env vars are present.
php /var/www/html/artisan config:cache
php /var/www/html/artisan route:cache

exec "$@"
```

- [ ] **Step 2: Write the production Dockerfile**

Create `docker/app/Dockerfile.prod`:

```dockerfile
# Production PHP-FPM image: code baked in, non-root, secrets pulled at boot.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        bash git curl icu-dev libzip-dev oniguruma-dev \
        libpng-dev libjpeg-turbo-dev freetype-dev \
        autoconf g++ make linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip gd opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-network autoconf g++ make linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps first (better layer caching), then bake the app in.
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/app/entrypoint.prod.sh /usr/local/bin/entrypoint.prod.sh
RUN chmod +x /usr/local/bin/entrypoint.prod.sh

USER www-data

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.prod.sh"]
CMD ["php-fpm"]
```

- [ ] **Step 3: Build the image and verify size + boot**

Run:
```bash
docker build -f docker/app/Dockerfile.prod -t cortex-lite/app-prod .
docker image inspect cortex-lite/app-prod --format '{{.Size}}' | awk '{print $1/1024/1024 " MB"}'
docker run --rm -e SSM_SKIP=1 cortex-lite/app-prod php artisan --version
```
Expected: build succeeds; size < 500 MB; `--version` prints the Laravel version (SSM bypassed).

- [ ] **Step 4: Commit**

```bash
git add docker/app/entrypoint.prod.sh docker/app/Dockerfile.prod
git commit -m "[Sprint 6] add production PHP image with SSM-secrets entrypoint"
```

---

## Task 6: Production compose topology

**Files:**
- Create: `docker-compose.prod.yml`

**Interfaces:**
- Consumes: `docker/app/Dockerfile.prod` (Task 5), `docker/nginx/Dockerfile` (existing multi-stage).
- Produces: `docker-compose.prod.yml` runnable on EC2 (no mysql; RDS via env).

- [ ] **Step 1: Write the prod compose file**

Create `docker-compose.prod.yml`:

```yaml
# Cortex Lite — production topology (EC2). MySQL is RDS, not a container.
# Secrets come from Parameter Store via each PHP service's prod entrypoint.
# Bring up with: docker compose -f docker-compose.prod.yml up -d

services:
  app:
    image: ${ECR_REGISTRY}/cortex-lite-app:latest
    build:
      context: .
      dockerfile: docker/app/Dockerfile.prod
    restart: unless-stopped
    environment:
      SSM_PARAMETER_PATH: /cortex-lite/
      AWS_DEFAULT_REGION: ${AWS_DEFAULT_REGION}
    depends_on:
      - redis
    networks: [cortex_net]

  nginx:
    image: ${ECR_REGISTRY}/cortex-lite-nginx:latest
    build:
      context: .
      dockerfile: docker/nginx/Dockerfile
    restart: unless-stopped
    ports:
      - "80:80"
    depends_on:
      - app
    networks: [cortex_net]

  redis:
    image: redis:7-alpine
    command: ["redis-server", "--appendonly", "yes"]
    restart: unless-stopped
    volumes:
      - redis_data:/data
    networks: [cortex_net]

  scheduler:
    image: ${ECR_REGISTRY}/cortex-lite-app:latest
    restart: unless-stopped
    environment:
      SSM_PARAMETER_PATH: /cortex-lite/
      AWS_DEFAULT_REGION: ${AWS_DEFAULT_REGION}
    command: ["php", "artisan", "schedule:work"]
    depends_on:
      - app
      - redis
    networks: [cortex_net]

  queue:
    image: ${ECR_REGISTRY}/cortex-lite-app:latest
    restart: unless-stopped
    environment:
      SSM_PARAMETER_PATH: /cortex-lite/
      AWS_DEFAULT_REGION: ${AWS_DEFAULT_REGION}
    command: ["php", "artisan", "queue:work", "--tries=3", "--sleep=3"]
    depends_on:
      - app
      - redis
    networks: [cortex_net]

networks:
  cortex_net:
    driver: bridge

volumes:
  redis_data:
```

- [ ] **Step 2: Validate the compose file**

Run: `ECR_REGISTRY=local AWS_DEFAULT_REGION=ap-southeast-1 docker compose -f docker-compose.prod.yml config`
Expected: prints the resolved config with no errors; no `mysql` service present.

- [ ] **Step 3: Commit**

```bash
git add docker-compose.prod.yml
git commit -m "[Sprint 6] add production docker-compose topology (RDS, no mysql container)"
```

---

## Task 7: Demo library fixture + provisioner

**Files:**
- Create: `database/data/demo_library.json`, `app/Services/Demo/DemoAccountProvisioner.php`
- Test: `tests/Feature/Demo/DemoAccountProvisionerTest.php`

**Interfaces:**
- Consumes: `App\Models\User`, `App\Models\Game`, `App\Models\PlaySession`, `App\Models\UsageEvent`.
- Produces:
  - `App\Services\Demo\DemoAccountProvisioner::EMAIL` = `'demo@cortex-lite.example'`
  - `DemoAccountProvisioner::PASSWORD` = `'cortex-demo-2026'`
  - `DemoAccountProvisioner::ensureUser(): User`
  - `DemoAccountProvisioner::reset(): User` — transaction: wipe demo games (cascades sessions) + usage_events, reseed library + sample sessions, `is_premium=false`.

- [ ] **Step 1: Write the fixture**

Create `database/data/demo_library.json`:

```json
[
  { "title": "Cyberpunk 2077", "steam_app_id": 1091500, "genre": "RPG", "status": "playing", "playtime_minutes": 4820, "source": "steam", "metadata_status": "ok" },
  { "title": "Counter-Strike 2", "steam_app_id": 730, "genre": "FPS", "status": "playing", "playtime_minutes": 15230, "source": "steam", "metadata_status": "ok" },
  { "title": "Elden Ring", "steam_app_id": 1245620, "genre": "Souls", "status": "completed", "playtime_minutes": 6110, "source": "steam", "metadata_status": "ok" },
  { "title": "Baldur's Gate 3", "steam_app_id": 1086940, "genre": "RPG", "status": "playing", "playtime_minutes": 5300, "source": "steam", "metadata_status": "ok" },
  { "title": "Helldivers 2", "steam_app_id": 553850, "genre": "Shooter", "status": "backlog", "playtime_minutes": 940, "source": "steam", "metadata_status": "ok" },
  { "title": "Red Dead Redemption 2", "steam_app_id": 1174180, "genre": "Action", "status": "completed", "playtime_minutes": 7200, "source": "steam", "metadata_status": "ok" },
  { "title": "Grand Theft Auto V", "steam_app_id": 271590, "genre": "Action", "status": "backlog", "playtime_minutes": 3600, "source": "steam", "metadata_status": "ok" },
  { "title": "Valorant", "steam_app_id": null, "genre": "FPS", "status": "playing", "playtime_minutes": 2100, "source": "manual", "metadata_status": "missing" },
  { "title": "Minecraft Java Edition", "steam_app_id": null, "genre": "Sandbox", "status": "playing", "playtime_minutes": 8800, "source": "manual", "metadata_status": "missing" },
  { "title": "Fortnite", "steam_app_id": null, "genre": "Battle Royale", "status": "dropped", "playtime_minutes": 1300, "source": "manual", "metadata_status": "missing" }
]
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Demo/DemoAccountProvisionerTest.php`:

```php
<?php

namespace Tests\Feature\Demo;

use App\Models\Game;
use App\Models\User;
use App\Services\Demo\DemoAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoAccountProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_creates_demo_user_with_fixture_library(): void
    {
        $user = app(DemoAccountProvisioner::class)->reset();

        $this->assertSame(DemoAccountProvisioner::EMAIL, $user->email);
        $this->assertFalse($user->is_premium);
        $this->assertSame(10, $user->games()->count());
    }

    public function test_reset_is_idempotent_and_wipes_visitor_changes(): void
    {
        $provisioner = app(DemoAccountProvisioner::class);
        $user = $provisioner->reset();

        // Simulate an evaluator adding junk + going premium.
        Game::factory()->for($user)->create(['title' => 'Junk Added By Visitor']);
        $user->update(['is_premium' => true]);

        $provisioner->reset();

        $this->assertSame(10, $user->fresh()->games()->count());
        $this->assertFalse($user->fresh()->is_premium);
        $this->assertDatabaseMissing('games', ['title' => 'Junk Added By Visitor']);
    }

    public function test_reset_leaves_other_users_untouched(): void
    {
        $other = User::factory()->create();
        Game::factory()->for($other)->count(3)->create();

        app(DemoAccountProvisioner::class)->reset();

        $this->assertSame(3, $other->fresh()->games()->count());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `make artisan CMD="test --filter=DemoAccountProvisionerTest"`
Expected: FAIL — `App\Services\Demo\DemoAccountProvisioner` not found.

- [ ] **Step 4: Write the provisioner**

Create `app/Services/Demo/DemoAccountProvisioner.php`:

```php
<?php

namespace App\Services\Demo;

use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoAccountProvisioner
{
    public const EMAIL = 'demo@cortex-lite.example';
    public const PASSWORD = 'cortex-demo-2026';

    public function ensureUser(): User
    {
        return User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Cortex Demo',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'is_premium' => false,
            ],
        );
    }

    /**
     * Restore the demo account to its canonical state. Wraps the multi-write
     * wipe + reseed in a transaction so a crash never leaves it half-reset.
     */
    public function reset(): User
    {
        return DB::transaction(function (): User {
            $user = $this->ensureUser();

            // Deleting games cascade-deletes their play_sessions (FK).
            $user->games()->delete();
            $user->usageEvents()->delete();
            $user->update(['is_premium' => false]);

            foreach ($this->fixtureLibrary() as $row) {
                $game = $user->games()->create([
                    ...$row,
                    'last_played_at' => $row['status'] === 'playing' ? now()->subDays(1) : now()->subDays(20),
                ]);

                // Give the "playing" titles a sample completed session.
                // Create via the user relationship so user_id is set as the
                // relation FK (user_id is NOT fillable on PlaySession), and
                // pass game_id explicitly (game_id IS fillable).
                if ($row['status'] === 'playing') {
                    $started = Carbon::now()->subDays(2)->setTime(20, 0);
                    $user->playSessions()->create([
                        'game_id' => $game->id,
                        'started_at' => $started,
                        'ended_at' => (clone $started)->addHour(),
                        'duration_seconds' => 3600,
                    ]);
                }
            }

            return $user->fresh();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fixtureLibrary(): array
    {
        $path = database_path('data/demo_library.json');

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `make artisan CMD="test --filter=DemoAccountProvisionerTest"`
Expected: PASS (3 tests). If `Game::factory()->for($user)` needs a status/enum, the factory already supplies defaults (existing `GameFactory`).

- [ ] **Step 6: Commit**

```bash
git add database/data/demo_library.json app/Services/Demo/DemoAccountProvisioner.php tests/Feature/Demo/DemoAccountProvisionerTest.php
git commit -m "[Sprint 6] add demo account provisioner with deterministic fixture library"
```

---

## Task 8: Demo seeder, reset command, and nightly schedule

**Files:**
- Create: `database/seeders/DemoAccountSeeder.php`, `app/Console/Commands/ResetDemoAccountCommand.php`
- Test: `tests/Feature/Console/ResetDemoAccountCommandTest.php`
- Modify: `bootstrap/app.php`

**Interfaces:**
- Consumes: `DemoAccountProvisioner` (Task 7).
- Produces: artisan command `demo:reset`; `DemoAccountSeeder` (called explicitly during deploy, not from `DatabaseSeeder`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/ResetDemoAccountCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Services\Demo\DemoAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetDemoAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_provisions_the_demo_account(): void
    {
        $this->artisan('demo:reset')->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => DemoAccountProvisioner::EMAIL,
            'is_premium' => false,
        ]);
        $this->assertSame(10, \App\Models\User::where('email', DemoAccountProvisioner::EMAIL)->first()->games()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make artisan CMD="test --filter=ResetDemoAccountCommandTest"`
Expected: FAIL — command `demo:reset` not defined.

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/ResetDemoAccountCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoAccountProvisioner;
use Illuminate\Console\Command;

class ResetDemoAccountCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Reset the evaluator demo account to its canonical state.';

    public function handle(DemoAccountProvisioner $provisioner): int
    {
        $user = $provisioner->reset();

        $this->info("Demo account reset: {$user->email} ({$user->games()->count()} games).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Write the seeder**

Create `database/seeders/DemoAccountSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Services\Demo\DemoAccountProvisioner;
use Illuminate\Database\Seeder;

class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoAccountProvisioner::class)->reset();
    }
}
```

- [ ] **Step 5: Schedule the nightly reset**

Modify `bootstrap/app.php` — inside the existing `->withSchedule(function (Schedule $schedule): void {` block, add:

```php
        $schedule->command('demo:reset')->dailyAt('04:00')->withoutOverlapping();
```

- [ ] **Step 6: Run test to verify it passes**

Run: `make artisan CMD="test --filter=ResetDemoAccountCommandTest"`
Expected: PASS.

- [ ] **Step 7: Verify the schedule registered**

Run: `make artisan CMD="schedule:list"`
Expected: output lists `demo:reset` at `04:00` alongside `steam:sync-all` and `games:enrich-metadata`.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/ResetDemoAccountCommand.php database/seeders/DemoAccountSeeder.php bootstrap/app.php tests/Feature/Console/ResetDemoAccountCommandTest.php
git commit -m "[Sprint 6] add demo:reset command, seeder, and nightly schedule"
```

---

## Task 9: Deploy helper scripts

**Files:**
- Create: `scripts/ecr-push.sh`, `scripts/ec2-bootstrap.sh`

**Interfaces:**
- Consumes: `docker-compose.prod.yml` (Task 6), the two Dockerfiles.
- Produces: two operator scripts referenced by the runbook (Task 10).

- [ ] **Step 1: Write the ECR push script**

Create `scripts/ecr-push.sh`:

```bash
#!/bin/sh
# Build production images and push them to ECR.
# Usage: AWS_DEFAULT_REGION=ap-southeast-1 ECR_REGISTRY=<acct>.dkr.ecr.<region>.amazonaws.com scripts/ecr-push.sh
set -e

: "${ECR_REGISTRY:?set ECR_REGISTRY to <acct>.dkr.ecr.<region>.amazonaws.com}"
: "${AWS_DEFAULT_REGION:?set AWS_DEFAULT_REGION}"

echo "==> ECR login"
aws ecr get-login-password --region "$AWS_DEFAULT_REGION" \
    | docker login --username AWS --password-stdin "$ECR_REGISTRY"

echo "==> Build app image"
docker build -f docker/app/Dockerfile.prod -t "$ECR_REGISTRY/cortex-lite-app:latest" .

echo "==> Build nginx image"
docker build -f docker/nginx/Dockerfile -t "$ECR_REGISTRY/cortex-lite-nginx:latest" .

echo "==> Image sizes (must be < 500 MB each)"
docker image inspect "$ECR_REGISTRY/cortex-lite-app:latest" "$ECR_REGISTRY/cortex-lite-nginx:latest" \
    --format '{{.RepoTags}} {{.Size}}'

echo "==> Push"
docker push "$ECR_REGISTRY/cortex-lite-app:latest"
docker push "$ECR_REGISTRY/cortex-lite-nginx:latest"
echo "==> Done"
```

- [ ] **Step 2: Write the EC2 bootstrap script**

Create `scripts/ec2-bootstrap.sh`:

```bash
#!/bin/sh
# Run ON the EC2 host (Amazon Linux 2023). Installs Docker, logs in to ECR,
# pulls images, and brings the stack up. Secrets load from Parameter Store via
# each container's prod entrypoint (instance role required).
# Usage: AWS_DEFAULT_REGION=... ECR_REGISTRY=... sh ec2-bootstrap.sh
set -e

: "${ECR_REGISTRY:?set ECR_REGISTRY}"
: "${AWS_DEFAULT_REGION:?set AWS_DEFAULT_REGION}"

echo "==> Install Docker + compose plugin"
sudo dnf install -y docker
sudo systemctl enable --now docker
sudo usermod -aG docker "$(whoami)" || true
DOCKER_CONFIG=${DOCKER_CONFIG:-/usr/local/lib/docker}
sudo mkdir -p "$DOCKER_CONFIG/cli-plugins"
sudo curl -sSL "https://github.com/docker/compose/releases/latest/download/docker-compose-linux-x86_64" \
    -o "$DOCKER_CONFIG/cli-plugins/docker-compose"
sudo chmod +x "$DOCKER_CONFIG/cli-plugins/docker-compose"

echo "==> ECR login"
aws ecr get-login-password --region "$AWS_DEFAULT_REGION" \
    | sudo docker login --username AWS --password-stdin "$ECR_REGISTRY"

echo "==> Pull + up"
sudo -E docker compose -f docker-compose.prod.yml pull
sudo -E docker compose -f docker-compose.prod.yml up -d

echo "==> Status"
sudo docker compose -f docker-compose.prod.yml ps
sudo docker stats --no-stream
```

- [ ] **Step 3: Make executable and syntax-check**

Run:
```bash
chmod +x scripts/ecr-push.sh scripts/ec2-bootstrap.sh
sh -n scripts/ecr-push.sh && sh -n scripts/ec2-bootstrap.sh && echo "syntax ok"
```
Expected: `syntax ok`.

- [ ] **Step 4: Commit**

```bash
git add scripts/ecr-push.sh scripts/ec2-bootstrap.sh
git commit -m "[Sprint 6] add ECR push and EC2 bootstrap helper scripts"
```

---

## Task 10: AWS Console runbook + `.env.example`

**Files:**
- Create: `docs/DEPLOYMENT.md`
- Modify: `.env.example`

**Interfaces:**
- Consumes: everything above.
- Produces: the operator-facing deploy/teardown runbook.

- [ ] **Step 1: Add deployment vars to `.env.example`**

Modify `.env.example` — append a deployment block (values blank):

```dotenv
# --- Phase 6 deployment (production only; set as Parameter Store SecureStrings) ---
AWS_DEFAULT_REGION=ap-southeast-1
SSM_PARAMETER_PATH=/cortex-lite/
ECR_REGISTRY=
```

- [ ] **Step 2: Write the runbook**

Create `docs/DEPLOYMENT.md` with these sections (numbered, copy-pasteable):

```markdown
# Cortex Lite — AWS Deployment Runbook

Manual AWS Console + helper scripts. Target: a 48-hour live window, then tear
down. All secrets live in Parameter Store; nothing sensitive is committed.

## 0. Preconditions & cost guardrails (do first)
1. Check the AWS account creation date. Accounts created on/after 2025-07-15
   have **$200 credits / 6 months**, shared across all services — no 12-month
   per-service free tier.
2. **Create an AWS Budgets alert: $20, hard threshold, email**, BEFORE any resource.
3. **Never create a NAT Gateway** (~$33/mo). EC2 goes in a public subnet.
4. Set a 48-hour calendar reminder to tear down.

## 1. Networking & security groups
- Use the default VPC. Create three security groups:
  - `cortex-ec2-sg`: inbound 80/443 from 0.0.0.0/0; SSH (22) from **your IP only**.
  - `cortex-rds-sg`: inbound 3306 from `cortex-ec2-sg` **only**.

## 2. RDS
- MySQL, `db.t4g.micro`, same VPC, attach `cortex-rds-sg`, not publicly accessible.
- Note the endpoint → this becomes the `DB_HOST` Parameter Store value.

## 3. IAM instance role
- Create an IAM role for EC2 with a policy allowing `ssm:GetParametersByPath`
  and `ssm:GetParameters` on `arn:aws:ssm:<region>:<acct>:parameter/cortex-lite/*`
  plus `kms:Decrypt` on the SSM key, and `AmazonEC2ContainerRegistryReadOnly`.

## 4. EC2
- Launch `t3.small`, Amazon Linux 2023, public subnet, attach `cortex-ec2-sg`
  and the IAM instance role from §3. Assign a public IP.

## 5. ECR + push images (from your laptop)
- Create repos `cortex-lite-app` and `cortex-lite-nginx`.
- `AWS_DEFAULT_REGION=<r> ECR_REGISTRY=<acct>.dkr.ecr.<r>.amazonaws.com scripts/ecr-push.sh`
- Confirm both images are **< 500 MB** in the script output.

## 6. Parameter Store (SecureString, one per key under /cortex-lite/)
Create: APP_KEY, APP_URL, DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD,
STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET, STRIPE_PRICE_PREMIUM,
STEAM_API_KEY, GEMINI_API_KEY, GEMINI_MODEL, SANCTUM_STATEFUL_DOMAINS,
SESSION_DOMAIN, CASHIER_CURRENCY(=myr).
(Also set non-secret prod env in the compose environment: APP_ENV=production,
APP_DEBUG=false, DB_CONNECTION=mysql, CACHE_STORE=redis, SESSION_DRIVER=redis,
QUEUE_CONNECTION=redis, REDIS_HOST=redis — add these to ssm or the compose file.)

## 7. Bring the stack up (on EC2)
- SSH in; copy `docker-compose.prod.yml` + `scripts/ec2-bootstrap.sh` to the host.
- `AWS_DEFAULT_REGION=<r> ECR_REGISTRY=<...> sh scripts/ec2-bootstrap.sh`
- Verify `docker stats` shows RAM headroom before proceeding.

## 8. Migrate + seed on live RDS
- `docker compose -f docker-compose.prod.yml exec app php artisan migrate --force`
- `docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force`
  (GPU/CPU/anchor seeders)
- `docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=DemoAccountSeeder --force`

## 9. CloudFront
- Create a distribution; origin = EC2 public DNS; viewer protocol = HTTPS only;
  use the free `*.cloudfront.net` domain.
- **Webhook carve-out** — add a cache behavior:
  - Path pattern: `/api/stripe/webhook`
  - Cache policy: CachingDisabled (TTL 0)
  - Origin request policy: forward **all** headers, cookies, query strings
  - Allowed methods: include POST
  - This preserves `Stripe-Signature` and the raw body. Non-negotiable.
- Set APP_URL / SANCTUM_STATEFUL_DOMAINS / SESSION_DOMAIN Parameter Store values
  to the CloudFront domain, then restart the app/scheduler/queue containers.

## 10. Verify before declaring demoable
- `stripe trigger checkout.session.completed` against the live `/api/stripe/webhook`
  → expect 200 with signature verified.
- Full HTTPS flow: register → connect Steam (OpenID) → import → start/end session
  → recommend → reverse mode → upgrade via Stripe (`4242 4242 4242 4242`) →
  confirm `is_premium` flipped.

## 11. Screenshots (for the README + portfolio)
EC2 console, RDS dashboard, ECR repos, CloudWatch logs (structured JSON),
security-group rules, CloudFront distribution + cache behaviors, Parameter Store
(values masked), running app at the CloudFront URL, a recommendation result, a
reverse-mode result.

## 12. Teardown (within 48h)
- Delete CloudFront distribution, EC2 instance, RDS instance (skip final
  snapshot), ECR repos, Parameter Store params, IAM role, security groups.
- Cancel any active Stripe test subscriptions.
- Verify $0/day:
  `aws ce get-cost-and-usage --time-period Start=<yyyy-mm-dd>,End=<yyyy-mm-dd> --granularity DAILY --metrics UnblendedCost`
```

- [ ] **Step 3: Commit**

```bash
git add docs/DEPLOYMENT.md .env.example
git commit -m "[Sprint 6] add AWS deployment runbook and deployment env vars"
```

---

## Task 11: Phase-ending documentation updates

**Files:**
- Modify: `docs/ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/TROUBLESHOOTING.md`, `README.md`

**Interfaces:**
- Consumes: everything above.
- Produces: the doc trail CLAUDE.md requires to close a phase.

- [ ] **Step 1: ARCHITECTURE.md — add an AWS infrastructure section**

Append a "## AWS Infrastructure (Phase 6)" section covering: an ASCII topology
diagram (Browser → CloudFront (HTTPS) → EC2 t3.small [nginx→app→redis, scheduler,
queue] → RDS MySQL; secrets from Parameter Store via instance role), each service
and why, the security model (SG isolation, SSH-from-IP, no NAT, secrets in-memory),
a cost breakdown (~$1 EC2 over 48h + RDS/CloudFront within the $200 pool), and the
teardown procedure (point to `docs/DEPLOYMENT.md` §12).

- [ ] **Step 2: DECISIONS.md — add the Phase 6 ADR entries**

Add entries (using the existing ADR format — Date/Decision/Rationale/Alternatives/Consequences) for:
- t3.small over t2.micro (OOM risk with app+nginx+redis+scheduler+queue).
- In-container Redis over ElastiCache (single-instance workload; ElastiCache is for multi-instance shared state).
- IAM-role SSM fetch over `.env` on disk (secrets in-memory only; strongest security story).
- Manual Console + runbook over Terraform (48h throwaway deploy; least tooling to debug in-window; teardown verified by hand).
- CloudFront free `*.cloudfront.net` domain over a custom domain (no domain purchase; HTTPS still mandatory).
- Demo nightly **full reseed** over quota-only reset (guarantees every evaluator sees the same populated walkthrough).

- [ ] **Step 3: TROUBLESHOOTING.md — add the Phase 6 failure modes**

Add entries (Symptom/Cause/Fix) for:
- Stripe webhook signature fails behind CloudFront → default behavior strips `Stripe-Signature`/mangles body → apply the `/api/stripe/webhook` carve-out (§9).
- `ssm:export` fails / containers boot without secrets → instance role missing `ssm:GetParametersByPath` or IMDSv2 unreachable → check the instance profile and that containers can reach 169.254.169.254; `SSM_SKIP=1` is local-only.
- Container OOM during demo → `docker stats`; t3.small not t2.micro; drop the queue worker as a last resort.
- AWS teardown verification → the `aws ce get-cost-and-usage` command.

- [ ] **Step 4: README.md — evaluator quick-start + Sprint 6 changelog**

Add/refresh an "Evaluator quick-start" section: the CloudFront URL (placeholder
until deploy), demo login (`demo@cortex-lite.example` / `cortex-demo-2026`), the
Stripe test card `4242 4242 4242 4242`, and a note that the demo account resets
nightly. Add a Sprint 6 changelog line describing the AWS deployment, native-agent
contract, and demo account.

- [ ] **Step 5: Verify docs and full test suite**

Run: `make test`
Expected: full suite green (existing suite + the new ShellEnv/SSM/demo tests).

Run: `git diff --check`
Expected: no whitespace errors.

- [ ] **Step 6: Commit**

```bash
git add docs/ARCHITECTURE.md docs/DECISIONS.md docs/TROUBLESHOOTING.md README.md
git commit -m "[Sprint 6] document AWS infra, deployment decisions, and evaluator quick-start"
```

- [ ] **Step 7: Update the phase tracker**

Modify `CLAUDE.md` — mark `- [x] Phase 6` in the Phase tracker. Commit:

```bash
git add CLAUDE.md
git commit -m "[Sprint 6] mark Phase 6 agent-buildable work complete"
```

---

## Operator handoff (not agent tasks)

After this plan is executed and merged, the **operator** performs the live
deploy by following `docs/DEPLOYMENT.md`: provision AWS resources, push images,
bring the stack up, seed, wire CloudFront, run the smoke test, screenshot,
record a 2–3 min demo video, then tear down and verify $0/day. These steps
require console access and credential custody and cannot be done by the agent.
```
