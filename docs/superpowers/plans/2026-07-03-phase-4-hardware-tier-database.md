# Phase 4 — Hardware Tier Database Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the hardware side of Phase 4 — hand-curated `gpus`/`cpus` tables (~60 GPUs, ~40 CPUs) tier-classified via absolute benchmark thresholds, seedable JSON data files, typeahead REST endpoints, a reusable React autocomplete component, and a `/hardware` demo page with best-effort browser auto-detect (with an honest note about the GPU-model detection limit).

**Architecture:** Two identically-shaped domain tables (`gpus`, `cpus`) seeded from committed JSON data files at `database/data/*.json`. Tier is derived deterministically by pure PHP classifiers (`GpuTierClassifier`, `CpuTierClassifier`) at seed time — the JSON stores the raw benchmark number, not the tier, so re-classification is a one-line seeder rerun. Two auth-gated typeahead endpoints (`GET /api/hardware/gpus?search=…`, `GET /api/hardware/cpus?search=…`) return the top 20 matches ordered by benchmark desc — cheap `LIKE`-based search on ~60/40 rows, no full-text index needed. React exposes a single reusable `HardwareAutocomplete` component parameterized by kind, wrapped by a `/hardware` demo page that also surfaces `navigator.hardwareConcurrency`, `navigator.deviceMemory`, and a WebGPU adapter probe (with the honest limits disclaimer).

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8 (dev/prod) + SQLite in-memory (tests), Sanctum SPA cookie auth (Phase 1), React 19, Vite, Axios (existing `client/src/lib/api.js` with CSRF cookie flow), react-router-dom v7, Tailwind v4.

## Global Constraints

- Branch: continue on `Phase-3` if this is the next slice OR create `Phase-4` off the current tip — check `git status` first and confirm with the user before branching. All commits use trailer `[Sprint 4] <what>`. No `-i`. No `--no-verify`.
- All commands run via project Makefile: `make artisan CMD="..."`, `make composer CMD="..."`, `make test`, `make shell`. Never raw `php artisan` / `docker exec`.
- **Tier thresholds are absolute and load-bearing**, not percentiles. Copy verbatim:
  - **GPU (G3D Mark):** Low `< 8000`, Mid `8000–13999`, High `14000–21999`, Enthusiast `≥ 22000`.
  - **CPU (single-thread PassMark):** Low `< 2800`, Mid `2800–3399`, High `3400–3999`, Enthusiast `≥ 4000`.
  - Rationale documented in Task 8 (`DECISIONS.md` entry). Percentiles across a long-tail benchmark dataset skew modern hardware low (a GTX 1060 lands in "high tier") — do not do that.
- Tier enum values (all lowercase, string): `'low' | 'mid' | 'high' | 'enthusiast'`. Same set for both `gpus` and `cpus`.
- Table shape is symmetric across `gpus` and `cpus`: `id, name (unique), manufacturer, {g3d_mark|single_thread_mark} (unsigned int), tier (enum), released_year (unsigned smallint), created_at, updated_at`. Keep the shapes symmetric — future changes to one table should be trivially portable to the other.
- Data files live at `database/data/gpus.json` and `database/data/cpus.json`. **The tier field is NOT stored in the JSON.** Seeders derive `tier` by calling the classifier on the benchmark number — this keeps JSON edits small (adding a GPU is one row of raw benchmark data) and keeps threshold changes to a single classifier constant.
- All new endpoints under `auth:sanctum` middleware. Response shape matches the existing conventions in `app/Http/Controllers/GameController.php`: JSON arrays for typeahead results, structured `{ error_code, message }` for errors.
- All feature tests use `RefreshDatabase` + Laravel factories. Test naming: `test_<subject>_<condition>_<outcome>`. Cache-busting: seeders that read JSON MUST be idempotent — the seeder tests will run them twice.
- React new files follow the existing style: functional components with hooks, Tailwind classes inline, no CSS modules. Axios calls go through `client/src/lib/api.js`. No `vitest` in the repo — component behavior is verified by the /hardware page manual smoke test at the end of Task 7.
- CI is `.github/workflows/ci.yml` running `php artisan test`. Any push to any branch triggers it.
- Do not touch anything under Phase 5 scope: no `RecommendationEngine`, no `setting_presets`, no LLM code. The `HardwareAutocomplete` is designed to be reusable by Phase 5's recommender form, but do NOT wire it into a recommender in this phase.
- Do not touch anything under Phase 4's *other* section (PCGamingWiki, anchor dataset, heuristic recommender). This plan covers ONLY the "Hardware tier database" + "Browser-side hardware auto-detect" sub-sections of Phase 4.

---

### Task 1: `gpus` and `cpus` schemas, models, factories

**Files:**
- Create: `database/migrations/2026_07_03_100000_create_gpus_table.php`
- Create: `database/migrations/2026_07_03_100100_create_cpus_table.php`
- Create: `app/Models/Gpu.php`
- Create: `app/Models/Cpu.php`
- Create: `database/factories/GpuFactory.php`
- Create: `database/factories/CpuFactory.php`
- Test: `tests/Feature/Hardware/GpuSchemaTest.php`
- Test: `tests/Feature/Hardware/CpuSchemaTest.php`

**Interfaces:**
- Consumes: Nothing from earlier tasks (this is the foundation).
- Produces:
  - Table `gpus`: `id, name (string, unique), manufacturer (string), g3d_mark (unsigned int), tier (enum: low/mid/high/enthusiast), released_year (unsigned smallint), created_at, updated_at`. Index on `(tier, g3d_mark)` and on `name`.
  - Table `cpus`: `id, name (string, unique), manufacturer (string), single_thread_mark (unsigned int), tier (enum: low/mid/high/enthusiast), released_year (unsigned smallint), created_at, updated_at`. Index on `(tier, single_thread_mark)` and on `name`.
  - `App\Models\Gpu` with `#[Fillable(['name', 'manufacturer', 'g3d_mark', 'tier', 'released_year'])]`, `RESPONSE_FIELDS = ['id', 'name', 'manufacturer', 'g3d_mark', 'tier', 'released_year']`, casts (`g3d_mark => integer`, `released_year => integer`). Public const `TIERS = ['low', 'mid', 'high', 'enthusiast']`.
  - `App\Models\Cpu` — same shape, `single_thread_mark` instead of `g3d_mark`.
  - `GpuFactory` producing valid rows (name, manufacturer, g3d_mark random within a tier band, tier derived, released_year 2018–2024).
  - `CpuFactory` — same shape.

- [ ] **Step 1: Write the GPU schema test (failing)**

Create `tests/Feature/Hardware/GpuSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Hardware;

use App\Models\Gpu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GpuSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_gpus_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('gpus'));
        $this->assertTrue(Schema::hasColumns('gpus', [
            'id', 'name', 'manufacturer', 'g3d_mark', 'tier', 'released_year',
            'created_at', 'updated_at',
        ]));
    }

    public function test_gpu_factory_creates_a_row(): void
    {
        $gpu = Gpu::factory()->create();

        $this->assertNotNull($gpu->id);
        $this->assertContains($gpu->tier, Gpu::TIERS);
        $this->assertIsInt($gpu->g3d_mark);
        $this->assertGreaterThan(0, $gpu->g3d_mark);
    }

    public function test_gpu_name_is_unique(): void
    {
        Gpu::factory()->create(['name' => 'RTX 4090']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Gpu::factory()->create(['name' => 'RTX 4090']);
    }
}
```

- [ ] **Step 2: Write the CPU schema test (failing)**

Create `tests/Feature/Hardware/CpuSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Hardware;

use App\Models\Cpu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CpuSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cpus_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('cpus'));
        $this->assertTrue(Schema::hasColumns('cpus', [
            'id', 'name', 'manufacturer', 'single_thread_mark', 'tier', 'released_year',
            'created_at', 'updated_at',
        ]));
    }

    public function test_cpu_factory_creates_a_row(): void
    {
        $cpu = Cpu::factory()->create();

        $this->assertNotNull($cpu->id);
        $this->assertContains($cpu->tier, Cpu::TIERS);
        $this->assertIsInt($cpu->single_thread_mark);
        $this->assertGreaterThan(0, $cpu->single_thread_mark);
    }

    public function test_cpu_name_is_unique(): void
    {
        Cpu::factory()->create(['name' => 'Ryzen 9 7950X']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Cpu::factory()->create(['name' => 'Ryzen 9 7950X']);
    }
}
```

- [ ] **Step 3: Run both tests — expect fail on missing tables**

Run: `make test -- --filter="Hardware/(Gpu|Cpu)SchemaTest"`
Expected: FAIL — "Base table or view not found: 'gpus'" / "'cpus'".

- [ ] **Step 4: Write the GPU migration**

Create `database/migrations/2026_07_03_100000_create_gpus_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpus', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('manufacturer');
            $table->unsignedInteger('g3d_mark');
            $table->enum('tier', ['low', 'mid', 'high', 'enthusiast']);
            $table->unsignedSmallInteger('released_year');
            $table->timestamps();

            $table->index(['tier', 'g3d_mark']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpus');
    }
};
```

- [ ] **Step 5: Write the CPU migration**

Create `database/migrations/2026_07_03_100100_create_cpus_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cpus', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('manufacturer');
            $table->unsignedInteger('single_thread_mark');
            $table->enum('tier', ['low', 'mid', 'high', 'enthusiast']);
            $table->unsignedSmallInteger('released_year');
            $table->timestamps();

            $table->index(['tier', 'single_thread_mark']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cpus');
    }
};
```

- [ ] **Step 6: Write the Gpu model**

Create `app/Models/Gpu.php`:

```php
<?php

namespace App\Models;

use Database\Factories\GpuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'manufacturer', 'g3d_mark', 'tier', 'released_year'])]
class Gpu extends Model
{
    /** @use HasFactory<GpuFactory> */
    use HasFactory;

    public const TIERS = ['low', 'mid', 'high', 'enthusiast'];

    public const RESPONSE_FIELDS = [
        'id',
        'name',
        'manufacturer',
        'g3d_mark',
        'tier',
        'released_year',
    ];

    protected function casts(): array
    {
        return [
            'g3d_mark' => 'integer',
            'released_year' => 'integer',
        ];
    }
}
```

- [ ] **Step 7: Write the Cpu model**

Create `app/Models/Cpu.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CpuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'manufacturer', 'single_thread_mark', 'tier', 'released_year'])]
class Cpu extends Model
{
    /** @use HasFactory<CpuFactory> */
    use HasFactory;

    public const TIERS = ['low', 'mid', 'high', 'enthusiast'];

    public const RESPONSE_FIELDS = [
        'id',
        'name',
        'manufacturer',
        'single_thread_mark',
        'tier',
        'released_year',
    ];

    protected function casts(): array
    {
        return [
            'single_thread_mark' => 'integer',
            'released_year' => 'integer',
        ];
    }
}
```

- [ ] **Step 8: Write the GPU factory**

Create `database/factories/GpuFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Gpu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gpu>
 */
class GpuFactory extends Factory
{
    public function definition(): array
    {
        $g3dMark = fake()->numberBetween(3000, 35000);
        $tier = match (true) {
            $g3dMark < 8000 => 'low',
            $g3dMark < 14000 => 'mid',
            $g3dMark < 22000 => 'high',
            default => 'enthusiast',
        };

        return [
            'name' => fake()->unique()->bothify('Model ##??'),
            'manufacturer' => fake()->randomElement(['NVIDIA', 'AMD', 'Intel']),
            'g3d_mark' => $g3dMark,
            'tier' => $tier,
            'released_year' => fake()->numberBetween(2018, 2024),
        ];
    }
}
```

- [ ] **Step 9: Write the CPU factory**

Create `database/factories/CpuFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Cpu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cpu>
 */
class CpuFactory extends Factory
{
    public function definition(): array
    {
        $singleThreadMark = fake()->numberBetween(1800, 5000);
        $tier = match (true) {
            $singleThreadMark < 2800 => 'low',
            $singleThreadMark < 3400 => 'mid',
            $singleThreadMark < 4000 => 'high',
            default => 'enthusiast',
        };

        return [
            'name' => fake()->unique()->bothify('CPU ##??'),
            'manufacturer' => fake()->randomElement(['AMD', 'Intel']),
            'single_thread_mark' => $singleThreadMark,
            'tier' => $tier,
            'released_year' => fake()->numberBetween(2018, 2024),
        ];
    }
}
```

- [ ] **Step 10: Run both schema tests — expect PASS**

Run: `make test -- --filter="Hardware/(Gpu|Cpu)SchemaTest"`
Expected: PASS (6 tests total).

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_07_03_100000_create_gpus_table.php \
        database/migrations/2026_07_03_100100_create_cpus_table.php \
        app/Models/Gpu.php app/Models/Cpu.php \
        database/factories/GpuFactory.php database/factories/CpuFactory.php \
        tests/Feature/Hardware/GpuSchemaTest.php tests/Feature/Hardware/CpuSchemaTest.php
git commit -m "[Sprint 4] add gpus and cpus schemas, models, factories"
```

---

### Task 2: `GpuTierClassifier` + `gpus.json` data + `GpuSeeder`

**Files:**
- Create: `app/Support/Hardware/GpuTierClassifier.php`
- Create: `database/data/gpus.json`
- Create: `database/seeders/GpuSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` (register `GpuSeeder`)
- Test: `tests/Unit/Support/Hardware/GpuTierClassifierTest.php`
- Test: `tests/Feature/Hardware/GpuSeederTest.php`

**Interfaces:**
- Consumes: `App\Models\Gpu` (Task 1).
- Produces:
  - `App\Support\Hardware\GpuTierClassifier::classify(int $g3dMark): string` — pure function returning `'low' | 'mid' | 'high' | 'enthusiast'`. Boundaries: `<8000 → low`, `<14000 → mid`, `<22000 → high`, else `enthusiast`. Public const `THRESHOLDS = ['low_max' => 7999, 'mid_max' => 13999, 'high_max' => 21999]` for cross-reference in tests and docs.
  - `database/data/gpus.json` — array of `{ name, manufacturer, g3d_mark, released_year }` objects. ~60 rows spanning NVIDIA Pascal→Ada, AMD Polaris→RDNA3, plus Intel Arc + modern iGPUs. **No `tier` field in the JSON.**
  - `database/seeders/GpuSeeder::run()` — reads the JSON, derives tier via the classifier, `upsert()`s by `name`. Idempotent (safe to run twice).
  - `DatabaseSeeder` calls `$this->call(GpuSeeder::class);`.

- [ ] **Step 1: Write the classifier unit test (failing)**

Create `tests/Unit/Support/Hardware/GpuTierClassifierTest.php`:

```php
<?php

namespace Tests\Unit\Support\Hardware;

use App\Support\Hardware\GpuTierClassifier;
use PHPUnit\Framework\TestCase;

class GpuTierClassifierTest extends TestCase
{
    public function test_below_8000_is_low(): void
    {
        $this->assertSame('low', GpuTierClassifier::classify(0));
        $this->assertSame('low', GpuTierClassifier::classify(7999));
    }

    public function test_8000_to_13999_is_mid(): void
    {
        $this->assertSame('mid', GpuTierClassifier::classify(8000));
        $this->assertSame('mid', GpuTierClassifier::classify(13999));
    }

    public function test_14000_to_21999_is_high(): void
    {
        $this->assertSame('high', GpuTierClassifier::classify(14000));
        $this->assertSame('high', GpuTierClassifier::classify(21999));
    }

    public function test_22000_or_above_is_enthusiast(): void
    {
        $this->assertSame('enthusiast', GpuTierClassifier::classify(22000));
        $this->assertSame('enthusiast', GpuTierClassifier::classify(999999));
    }
}
```

- [ ] **Step 2: Run classifier test — expect fail**

Run: `make test -- --filter=GpuTierClassifierTest`
Expected: FAIL — "Class not found".

- [ ] **Step 3: Write the classifier**

Create `app/Support/Hardware/GpuTierClassifier.php`:

```php
<?php

namespace App\Support\Hardware;

final class GpuTierClassifier
{
    public const THRESHOLDS = [
        'low_max' => 7999,
        'mid_max' => 13999,
        'high_max' => 21999,
    ];

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

- [ ] **Step 4: Run classifier test — expect PASS**

Run: `make test -- --filter=GpuTierClassifierTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Write `gpus.json` data file**

Create `database/data/gpus.json`. **60 rows** minimum. Benchmark numbers below are current PassMark G3D Mark values as a sanity guide — verify against passmark.com if updating.

```json
[
  { "name": "GeForce GTX 1050 Ti", "manufacturer": "NVIDIA", "g3d_mark": 3830, "released_year": 2016 },
  { "name": "GeForce GTX 1060 3GB", "manufacturer": "NVIDIA", "g3d_mark": 6320, "released_year": 2016 },
  { "name": "GeForce GTX 1060 6GB", "manufacturer": "NVIDIA", "g3d_mark": 7050, "released_year": 2016 },
  { "name": "GeForce GTX 1070", "manufacturer": "NVIDIA", "g3d_mark": 9950, "released_year": 2016 },
  { "name": "GeForce GTX 1070 Ti", "manufacturer": "NVIDIA", "g3d_mark": 11150, "released_year": 2017 },
  { "name": "GeForce GTX 1080", "manufacturer": "NVIDIA", "g3d_mark": 11720, "released_year": 2016 },
  { "name": "GeForce GTX 1080 Ti", "manufacturer": "NVIDIA", "g3d_mark": 14000, "released_year": 2017 },
  { "name": "GeForce GTX 1650", "manufacturer": "NVIDIA", "g3d_mark": 6200, "released_year": 2019 },
  { "name": "GeForce GTX 1660", "manufacturer": "NVIDIA", "g3d_mark": 9860, "released_year": 2019 },
  { "name": "GeForce GTX 1660 Super", "manufacturer": "NVIDIA", "g3d_mark": 10530, "released_year": 2019 },
  { "name": "GeForce GTX 1660 Ti", "manufacturer": "NVIDIA", "g3d_mark": 10730, "released_year": 2019 },
  { "name": "GeForce RTX 2060", "manufacturer": "NVIDIA", "g3d_mark": 12240, "released_year": 2019 },
  { "name": "GeForce RTX 2060 Super", "manufacturer": "NVIDIA", "g3d_mark": 13970, "released_year": 2019 },
  { "name": "GeForce RTX 2070", "manufacturer": "NVIDIA", "g3d_mark": 14180, "released_year": 2018 },
  { "name": "GeForce RTX 2070 Super", "manufacturer": "NVIDIA", "g3d_mark": 15960, "released_year": 2019 },
  { "name": "GeForce RTX 2080", "manufacturer": "NVIDIA", "g3d_mark": 16350, "released_year": 2018 },
  { "name": "GeForce RTX 2080 Super", "manufacturer": "NVIDIA", "g3d_mark": 17690, "released_year": 2019 },
  { "name": "GeForce RTX 2080 Ti", "manufacturer": "NVIDIA", "g3d_mark": 20000, "released_year": 2018 },
  { "name": "GeForce RTX 3050", "manufacturer": "NVIDIA", "g3d_mark": 9670, "released_year": 2022 },
  { "name": "GeForce RTX 3060", "manufacturer": "NVIDIA", "g3d_mark": 13980, "released_year": 2021 },
  { "name": "GeForce RTX 3060 Ti", "manufacturer": "NVIDIA", "g3d_mark": 18110, "released_year": 2020 },
  { "name": "GeForce RTX 3070", "manufacturer": "NVIDIA", "g3d_mark": 21870, "released_year": 2020 },
  { "name": "GeForce RTX 3070 Ti", "manufacturer": "NVIDIA", "g3d_mark": 22470, "released_year": 2021 },
  { "name": "GeForce RTX 3080", "manufacturer": "NVIDIA", "g3d_mark": 25150, "released_year": 2020 },
  { "name": "GeForce RTX 3080 Ti", "manufacturer": "NVIDIA", "g3d_mark": 26750, "released_year": 2021 },
  { "name": "GeForce RTX 3090", "manufacturer": "NVIDIA", "g3d_mark": 26980, "released_year": 2020 },
  { "name": "GeForce RTX 3090 Ti", "manufacturer": "NVIDIA", "g3d_mark": 28270, "released_year": 2022 },
  { "name": "GeForce RTX 4060", "manufacturer": "NVIDIA", "g3d_mark": 19670, "released_year": 2023 },
  { "name": "GeForce RTX 4060 Ti", "manufacturer": "NVIDIA", "g3d_mark": 22800, "released_year": 2023 },
  { "name": "GeForce RTX 4070", "manufacturer": "NVIDIA", "g3d_mark": 26620, "released_year": 2023 },
  { "name": "GeForce RTX 4070 Super", "manufacturer": "NVIDIA", "g3d_mark": 30500, "released_year": 2024 },
  { "name": "GeForce RTX 4070 Ti", "manufacturer": "NVIDIA", "g3d_mark": 30630, "released_year": 2023 },
  { "name": "GeForce RTX 4070 Ti Super", "manufacturer": "NVIDIA", "g3d_mark": 32820, "released_year": 2024 },
  { "name": "GeForce RTX 4080", "manufacturer": "NVIDIA", "g3d_mark": 34440, "released_year": 2022 },
  { "name": "GeForce RTX 4080 Super", "manufacturer": "NVIDIA", "g3d_mark": 34940, "released_year": 2024 },
  { "name": "GeForce RTX 4090", "manufacturer": "NVIDIA", "g3d_mark": 39150, "released_year": 2022 },
  { "name": "Radeon RX 570", "manufacturer": "AMD", "g3d_mark": 6420, "released_year": 2017 },
  { "name": "Radeon RX 580", "manufacturer": "AMD", "g3d_mark": 7350, "released_year": 2017 },
  { "name": "Radeon RX 590", "manufacturer": "AMD", "g3d_mark": 8100, "released_year": 2018 },
  { "name": "Radeon RX 5500 XT", "manufacturer": "AMD", "g3d_mark": 8300, "released_year": 2019 },
  { "name": "Radeon RX 5600 XT", "manufacturer": "AMD", "g3d_mark": 11940, "released_year": 2020 },
  { "name": "Radeon RX 5700", "manufacturer": "AMD", "g3d_mark": 13580, "released_year": 2019 },
  { "name": "Radeon RX 5700 XT", "manufacturer": "AMD", "g3d_mark": 14620, "released_year": 2019 },
  { "name": "Radeon RX 6600", "manufacturer": "AMD", "g3d_mark": 14330, "released_year": 2021 },
  { "name": "Radeon RX 6600 XT", "manufacturer": "AMD", "g3d_mark": 16480, "released_year": 2021 },
  { "name": "Radeon RX 6700 XT", "manufacturer": "AMD", "g3d_mark": 19510, "released_year": 2021 },
  { "name": "Radeon RX 6800", "manufacturer": "AMD", "g3d_mark": 22100, "released_year": 2020 },
  { "name": "Radeon RX 6800 XT", "manufacturer": "AMD", "g3d_mark": 24940, "released_year": 2020 },
  { "name": "Radeon RX 6900 XT", "manufacturer": "AMD", "g3d_mark": 25720, "released_year": 2020 },
  { "name": "Radeon RX 6950 XT", "manufacturer": "AMD", "g3d_mark": 26670, "released_year": 2022 },
  { "name": "Radeon RX 7600", "manufacturer": "AMD", "g3d_mark": 16650, "released_year": 2023 },
  { "name": "Radeon RX 7700 XT", "manufacturer": "AMD", "g3d_mark": 22550, "released_year": 2023 },
  { "name": "Radeon RX 7800 XT", "manufacturer": "AMD", "g3d_mark": 25050, "released_year": 2023 },
  { "name": "Radeon RX 7900 GRE", "manufacturer": "AMD", "g3d_mark": 27300, "released_year": 2024 },
  { "name": "Radeon RX 7900 XT", "manufacturer": "AMD", "g3d_mark": 30690, "released_year": 2022 },
  { "name": "Radeon RX 7900 XTX", "manufacturer": "AMD", "g3d_mark": 33400, "released_year": 2022 },
  { "name": "Arc A380", "manufacturer": "Intel", "g3d_mark": 5820, "released_year": 2022 },
  { "name": "Arc A750", "manufacturer": "Intel", "g3d_mark": 15490, "released_year": 2022 },
  { "name": "Arc A770", "manufacturer": "Intel", "g3d_mark": 16850, "released_year": 2022 },
  { "name": "Iris Xe Graphics G7 96EU", "manufacturer": "Intel", "g3d_mark": 1800, "released_year": 2020 },
  { "name": "Radeon 780M (integrated)", "manufacturer": "AMD", "g3d_mark": 3500, "released_year": 2023 }
]
```

- [ ] **Step 6: Write the seeder test (failing)**

Create `tests/Feature/Hardware/GpuSeederTest.php`:

```php
<?php

namespace Tests\Feature\Hardware;

use App\Models\Gpu;
use Database\Seeders\GpuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GpuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_populates_at_least_60_gpus(): void
    {
        $this->seed(GpuSeeder::class);

        $this->assertGreaterThanOrEqual(60, Gpu::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(GpuSeeder::class);
        $countAfterFirst = Gpu::count();

        $this->seed(GpuSeeder::class);
        $countAfterSecond = Gpu::count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_gtx_1060_6gb_is_classified_as_low(): void
    {
        $this->seed(GpuSeeder::class);

        $gpu = Gpu::where('name', 'GeForce GTX 1060 6GB')->first();

        $this->assertNotNull($gpu);
        $this->assertSame('low', $gpu->tier);
    }

    public function test_rtx_3070_is_classified_as_high(): void
    {
        $this->seed(GpuSeeder::class);

        $gpu = Gpu::where('name', 'GeForce RTX 3070')->first();

        $this->assertNotNull($gpu);
        $this->assertSame('high', $gpu->tier);
    }

    public function test_rtx_4090_is_classified_as_enthusiast(): void
    {
        $this->seed(GpuSeeder::class);

        $gpu = Gpu::where('name', 'GeForce RTX 4090')->first();

        $this->assertNotNull($gpu);
        $this->assertSame('enthusiast', $gpu->tier);
    }
}
```

- [ ] **Step 7: Run seeder test — expect fail**

Run: `make test -- --filter=GpuSeederTest`
Expected: FAIL — "Class 'Database\Seeders\GpuSeeder' not found".

- [ ] **Step 8: Write the seeder**

Create `database/seeders/GpuSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Support\Hardware\GpuTierClassifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GpuSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/gpus.json');
        $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $now = now();
        $records = array_map(fn (array $row) => [
            'name' => $row['name'],
            'manufacturer' => $row['manufacturer'],
            'g3d_mark' => $row['g3d_mark'],
            'tier' => GpuTierClassifier::classify($row['g3d_mark']),
            'released_year' => $row['released_year'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::table('gpus')->upsert(
            $records,
            uniqueBy: ['name'],
            update: ['manufacturer', 'g3d_mark', 'tier', 'released_year', 'updated_at'],
        );
    }
}
```

- [ ] **Step 9: Register the seeder in `DatabaseSeeder`**

Edit `database/seeders/DatabaseSeeder.php` — add the call inside `run()` **before** the existing `User::factory()->create(...)` line so the seed order is: reference data first, users second.

```php
public function run(): void
{
    $this->call([
        GpuSeeder::class,
    ]);

    User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);
}
```

- [ ] **Step 10: Run seeder test — expect PASS**

Run: `make test -- --filter=GpuSeederTest`
Expected: PASS (5 tests).

- [ ] **Step 11: Commit**

```bash
git add app/Support/Hardware/GpuTierClassifier.php \
        database/data/gpus.json \
        database/seeders/GpuSeeder.php \
        database/seeders/DatabaseSeeder.php \
        tests/Unit/Support/Hardware/GpuTierClassifierTest.php \
        tests/Feature/Hardware/GpuSeederTest.php
git commit -m "[Sprint 4] add gpus.json data, tier classifier, and idempotent seeder"
```

---

### Task 3: `CpuTierClassifier` + `cpus.json` data + `CpuSeeder`

**Files:**
- Create: `app/Support/Hardware/CpuTierClassifier.php`
- Create: `database/data/cpus.json`
- Create: `database/seeders/CpuSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` (register `CpuSeeder` alongside `GpuSeeder`)
- Test: `tests/Unit/Support/Hardware/CpuTierClassifierTest.php`
- Test: `tests/Feature/Hardware/CpuSeederTest.php`

**Interfaces:**
- Consumes: `App\Models\Cpu` (Task 1).
- Produces:
  - `App\Support\Hardware\CpuTierClassifier::classify(int $singleThreadMark): string` — pure function. Boundaries: `<2800 → low`, `<3400 → mid`, `<4000 → high`, else `enthusiast`. Public const `THRESHOLDS = ['low_max' => 2799, 'mid_max' => 3399, 'high_max' => 3999]`.
  - `database/data/cpus.json` — ~40 CPUs, Ryzen 2000+ and Intel 8th gen+, no `tier` field.
  - `database/seeders/CpuSeeder::run()` — idempotent upsert-by-name.

- [ ] **Step 1: Write the classifier unit test (failing)**

Create `tests/Unit/Support/Hardware/CpuTierClassifierTest.php`:

```php
<?php

namespace Tests\Unit\Support\Hardware;

use App\Support\Hardware\CpuTierClassifier;
use PHPUnit\Framework\TestCase;

class CpuTierClassifierTest extends TestCase
{
    public function test_below_2800_is_low(): void
    {
        $this->assertSame('low', CpuTierClassifier::classify(0));
        $this->assertSame('low', CpuTierClassifier::classify(2799));
    }

    public function test_2800_to_3399_is_mid(): void
    {
        $this->assertSame('mid', CpuTierClassifier::classify(2800));
        $this->assertSame('mid', CpuTierClassifier::classify(3399));
    }

    public function test_3400_to_3999_is_high(): void
    {
        $this->assertSame('high', CpuTierClassifier::classify(3400));
        $this->assertSame('high', CpuTierClassifier::classify(3999));
    }

    public function test_4000_or_above_is_enthusiast(): void
    {
        $this->assertSame('enthusiast', CpuTierClassifier::classify(4000));
        $this->assertSame('enthusiast', CpuTierClassifier::classify(99999));
    }
}
```

- [ ] **Step 2: Run classifier test — expect fail**

Run: `make test -- --filter=CpuTierClassifierTest`
Expected: FAIL — "Class not found".

- [ ] **Step 3: Write the classifier**

Create `app/Support/Hardware/CpuTierClassifier.php`:

```php
<?php

namespace App\Support\Hardware;

final class CpuTierClassifier
{
    public const THRESHOLDS = [
        'low_max' => 2799,
        'mid_max' => 3399,
        'high_max' => 3999,
    ];

    public static function classify(int $singleThreadMark): string
    {
        return match (true) {
            $singleThreadMark <= self::THRESHOLDS['low_max'] => 'low',
            $singleThreadMark <= self::THRESHOLDS['mid_max'] => 'mid',
            $singleThreadMark <= self::THRESHOLDS['high_max'] => 'high',
            default => 'enthusiast',
        };
    }
}
```

- [ ] **Step 4: Run classifier test — expect PASS**

Run: `make test -- --filter=CpuTierClassifierTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Write `cpus.json` data file**

Create `database/data/cpus.json` (~40 rows; single-thread mark values are PassMark ST scores as a sanity guide):

```json
[
  { "name": "Ryzen 5 2600", "manufacturer": "AMD", "single_thread_mark": 2145, "released_year": 2018 },
  { "name": "Ryzen 7 2700X", "manufacturer": "AMD", "single_thread_mark": 2320, "released_year": 2018 },
  { "name": "Ryzen 5 3600", "manufacturer": "AMD", "single_thread_mark": 2680, "released_year": 2019 },
  { "name": "Ryzen 7 3700X", "manufacturer": "AMD", "single_thread_mark": 2720, "released_year": 2019 },
  { "name": "Ryzen 9 3900X", "manufacturer": "AMD", "single_thread_mark": 2760, "released_year": 2019 },
  { "name": "Ryzen 9 3950X", "manufacturer": "AMD", "single_thread_mark": 2830, "released_year": 2019 },
  { "name": "Ryzen 5 5600", "manufacturer": "AMD", "single_thread_mark": 3320, "released_year": 2022 },
  { "name": "Ryzen 5 5600X", "manufacturer": "AMD", "single_thread_mark": 3430, "released_year": 2020 },
  { "name": "Ryzen 7 5700X", "manufacturer": "AMD", "single_thread_mark": 3380, "released_year": 2022 },
  { "name": "Ryzen 7 5800X", "manufacturer": "AMD", "single_thread_mark": 3520, "released_year": 2020 },
  { "name": "Ryzen 7 5800X3D", "manufacturer": "AMD", "single_thread_mark": 3420, "released_year": 2022 },
  { "name": "Ryzen 9 5900X", "manufacturer": "AMD", "single_thread_mark": 3480, "released_year": 2020 },
  { "name": "Ryzen 9 5950X", "manufacturer": "AMD", "single_thread_mark": 3510, "released_year": 2020 },
  { "name": "Ryzen 5 7600", "manufacturer": "AMD", "single_thread_mark": 4020, "released_year": 2023 },
  { "name": "Ryzen 5 7600X", "manufacturer": "AMD", "single_thread_mark": 4200, "released_year": 2022 },
  { "name": "Ryzen 7 7700X", "manufacturer": "AMD", "single_thread_mark": 4200, "released_year": 2022 },
  { "name": "Ryzen 7 7800X3D", "manufacturer": "AMD", "single_thread_mark": 4090, "released_year": 2023 },
  { "name": "Ryzen 9 7900X", "manufacturer": "AMD", "single_thread_mark": 4310, "released_year": 2022 },
  { "name": "Ryzen 9 7950X", "manufacturer": "AMD", "single_thread_mark": 4400, "released_year": 2022 },
  { "name": "Ryzen 9 7950X3D", "manufacturer": "AMD", "single_thread_mark": 4280, "released_year": 2023 },
  { "name": "Core i5-8400", "manufacturer": "Intel", "single_thread_mark": 2465, "released_year": 2017 },
  { "name": "Core i7-8700K", "manufacturer": "Intel", "single_thread_mark": 2790, "released_year": 2017 },
  { "name": "Core i5-9600K", "manufacturer": "Intel", "single_thread_mark": 2775, "released_year": 2018 },
  { "name": "Core i7-9700K", "manufacturer": "Intel", "single_thread_mark": 2830, "released_year": 2018 },
  { "name": "Core i9-9900K", "manufacturer": "Intel", "single_thread_mark": 2925, "released_year": 2018 },
  { "name": "Core i5-10600K", "manufacturer": "Intel", "single_thread_mark": 3060, "released_year": 2020 },
  { "name": "Core i7-10700K", "manufacturer": "Intel", "single_thread_mark": 3200, "released_year": 2020 },
  { "name": "Core i9-10900K", "manufacturer": "Intel", "single_thread_mark": 3250, "released_year": 2020 },
  { "name": "Core i5-11600K", "manufacturer": "Intel", "single_thread_mark": 3350, "released_year": 2021 },
  { "name": "Core i7-11700K", "manufacturer": "Intel", "single_thread_mark": 3400, "released_year": 2021 },
  { "name": "Core i9-11900K", "manufacturer": "Intel", "single_thread_mark": 3510, "released_year": 2021 },
  { "name": "Core i5-12600K", "manufacturer": "Intel", "single_thread_mark": 3900, "released_year": 2021 },
  { "name": "Core i7-12700K", "manufacturer": "Intel", "single_thread_mark": 3990, "released_year": 2021 },
  { "name": "Core i9-12900K", "manufacturer": "Intel", "single_thread_mark": 4060, "released_year": 2021 },
  { "name": "Core i5-13600K", "manufacturer": "Intel", "single_thread_mark": 4260, "released_year": 2022 },
  { "name": "Core i7-13700K", "manufacturer": "Intel", "single_thread_mark": 4320, "released_year": 2022 },
  { "name": "Core i9-13900K", "manufacturer": "Intel", "single_thread_mark": 4500, "released_year": 2022 },
  { "name": "Core i5-14600K", "manufacturer": "Intel", "single_thread_mark": 4370, "released_year": 2023 },
  { "name": "Core i7-14700K", "manufacturer": "Intel", "single_thread_mark": 4440, "released_year": 2023 },
  { "name": "Core i9-14900K", "manufacturer": "Intel", "single_thread_mark": 4650, "released_year": 2023 }
]
```

- [ ] **Step 6: Write the seeder test (failing)**

Create `tests/Feature/Hardware/CpuSeederTest.php`:

```php
<?php

namespace Tests\Feature\Hardware;

use App\Models\Cpu;
use Database\Seeders\CpuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CpuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_populates_at_least_40_cpus(): void
    {
        $this->seed(CpuSeeder::class);

        $this->assertGreaterThanOrEqual(40, Cpu::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(CpuSeeder::class);
        $countAfterFirst = Cpu::count();

        $this->seed(CpuSeeder::class);
        $countAfterSecond = Cpu::count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_ryzen_5_2600_is_classified_as_low(): void
    {
        $this->seed(CpuSeeder::class);

        $cpu = Cpu::where('name', 'Ryzen 5 2600')->first();

        $this->assertNotNull($cpu);
        $this->assertSame('low', $cpu->tier);
    }

    public function test_ryzen_7_5800x_is_classified_as_mid(): void
    {
        $this->seed(CpuSeeder::class);

        $cpu = Cpu::where('name', 'Ryzen 7 5800X')->first();

        $this->assertNotNull($cpu);
        $this->assertSame('high', $cpu->tier);
    }

    public function test_ryzen_9_7950x_is_classified_as_enthusiast(): void
    {
        $this->seed(CpuSeeder::class);

        $cpu = Cpu::where('name', 'Ryzen 9 7950X')->first();

        $this->assertNotNull($cpu);
        $this->assertSame('enthusiast', $cpu->tier);
    }
}
```

- [ ] **Step 7: Run seeder test — expect fail**

Run: `make test -- --filter=CpuSeederTest`
Expected: FAIL — "Class 'Database\Seeders\CpuSeeder' not found".

- [ ] **Step 8: Write the seeder**

Create `database/seeders/CpuSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Support\Hardware\CpuTierClassifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CpuSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/cpus.json');
        $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $now = now();
        $records = array_map(fn (array $row) => [
            'name' => $row['name'],
            'manufacturer' => $row['manufacturer'],
            'single_thread_mark' => $row['single_thread_mark'],
            'tier' => CpuTierClassifier::classify($row['single_thread_mark']),
            'released_year' => $row['released_year'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::table('cpus')->upsert(
            $records,
            uniqueBy: ['name'],
            update: ['manufacturer', 'single_thread_mark', 'tier', 'released_year', 'updated_at'],
        );
    }
}
```

- [ ] **Step 9: Register the seeder in `DatabaseSeeder`**

Edit `database/seeders/DatabaseSeeder.php` — extend the existing `call([...])` array from Task 2:

```php
$this->call([
    GpuSeeder::class,
    CpuSeeder::class,
]);
```

- [ ] **Step 10: Run seeder test — expect PASS**

Run: `make test -- --filter=CpuSeederTest`
Expected: PASS (5 tests).

- [ ] **Step 11: Commit**

```bash
git add app/Support/Hardware/CpuTierClassifier.php \
        database/data/cpus.json \
        database/seeders/CpuSeeder.php \
        database/seeders/DatabaseSeeder.php \
        tests/Unit/Support/Hardware/CpuTierClassifierTest.php \
        tests/Feature/Hardware/CpuSeederTest.php
git commit -m "[Sprint 4] add cpus.json data, tier classifier, and idempotent seeder"
```

---

### Task 4: `GET /api/hardware/gpus` typeahead endpoint

**Files:**
- Create: `app/Http/Controllers/HardwareController.php`
- Modify: `routes/api.php` (add the route inside the `auth:sanctum` group)
- Test: `tests/Feature/Hardware/GpuTypeaheadTest.php`

**Interfaces:**
- Consumes: `App\Models\Gpu` (Task 1), `GpuSeeder` (Task 2, indirectly — tests seed inside the test class).
- Produces:
  - `HardwareController::gpus(Request $request): JsonResponse` — validates `search` (`nullable|string|max:100`), builds an escaped `LIKE '%<search>%'` query on `name`, orders by `g3d_mark DESC`, limits to 20, returns JSON `[{ id, name, manufacturer, g3d_mark, tier, released_year }, ...]` (a bare array, not paginated — this is a typeahead, not a resource list).
  - Route `GET /api/hardware/gpus`, name `hardware.gpus.search`, under `auth:sanctum`. No throttle (typeahead calls fire on every keystroke; existing Sanctum session auth is sufficient rate control for a portfolio project).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Hardware/GpuTypeaheadTest.php`:

```php
<?php

namespace Tests\Feature\Hardware;

use App\Models\Gpu;
use App\Models\User;
use Database\Seeders\GpuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GpuTypeaheadTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_search_returns_401(): void
    {
        $this->getJson('/api/hardware/gpus')->assertStatus(401);
    }

    public function test_authenticated_empty_search_returns_top_20_by_g3d_mark_desc(): void
    {
        $this->seed(GpuSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/hardware/gpus');

        $response->assertOk();
        $data = $response->json();

        $this->assertIsArray($data);
        $this->assertLessThanOrEqual(20, count($data));
        $this->assertGreaterThan(0, count($data));

        $marks = array_column($data, 'g3d_mark');
        $sorted = $marks;
        rsort($sorted);
        $this->assertSame($sorted, $marks, 'Results must be sorted g3d_mark DESC');

        $first = $data[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('manufacturer', $first);
        $this->assertArrayHasKey('g3d_mark', $first);
        $this->assertArrayHasKey('tier', $first);
        $this->assertArrayHasKey('released_year', $first);
    }

    public function test_search_filters_by_case_insensitive_substring_match(): void
    {
        $this->seed(GpuSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/hardware/gpus?search=rtx 4070');

        $response->assertOk();
        $names = array_column($response->json(), 'name');

        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertStringContainsStringIgnoringCase('rtx 4070', $name);
        }
    }

    public function test_search_containing_wildcard_characters_is_escaped(): void
    {
        $this->seed(GpuSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/hardware/gpus?search=%25');

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_search_over_100_chars_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/hardware/gpus?search='.str_repeat('a', 101))
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run tests — expect fail**

Run: `make test -- --filter=GpuTypeaheadTest`
Expected: FAIL — "Route [GET /api/hardware/gpus] not defined" or 404s.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/HardwareController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Gpu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HardwareController extends Controller
{
    public function gpus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Gpu::query()->select(Gpu::RESPONSE_FIELDS);

        if (! empty($validated['search'])) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $validated['search']);
            $query->whereRaw("name like ? escape '!'", ["%{$search}%"]);
        }

        $rows = $query->orderByDesc('g3d_mark')->limit(20)->get();

        return response()->json($rows);
    }
}
```

- [ ] **Step 4: Register the route**

Edit `routes/api.php`. Add these two lines inside the existing `Route::middleware('auth:sanctum')->group(function (): void { ... });` block (near the other authenticated routes):

```php
Route::get('/hardware/gpus', [HardwareController::class, 'gpus'])
    ->name('hardware.gpus.search');
```

Also add `use App\Http\Controllers\HardwareController;` to the top of the file with the other controller imports.

- [ ] **Step 5: Run tests — expect PASS**

Run: `make test -- --filter=GpuTypeaheadTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HardwareController.php \
        routes/api.php \
        tests/Feature/Hardware/GpuTypeaheadTest.php
git commit -m "[Sprint 4] add GET /api/hardware/gpus typeahead endpoint"
```

---

### Task 5: `GET /api/hardware/cpus` typeahead endpoint

**Files:**
- Modify: `app/Http/Controllers/HardwareController.php` (add `cpus()` method)
- Modify: `routes/api.php` (register `GET /api/hardware/cpus`)
- Test: `tests/Feature/Hardware/CpuTypeaheadTest.php`

**Interfaces:**
- Consumes: `App\Models\Cpu` (Task 1), `CpuSeeder` (Task 3).
- Produces:
  - `HardwareController::cpus(Request $request): JsonResponse` — same shape as `gpus()` but orders by `single_thread_mark DESC` and returns CPU response fields.
  - Route `GET /api/hardware/cpus`, name `hardware.cpus.search`, under `auth:sanctum`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Hardware/CpuTypeaheadTest.php`:

```php
<?php

namespace Tests\Feature\Hardware;

use App\Models\User;
use Database\Seeders\CpuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CpuTypeaheadTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_search_returns_401(): void
    {
        $this->getJson('/api/hardware/cpus')->assertStatus(401);
    }

    public function test_authenticated_empty_search_returns_top_20_by_single_thread_mark_desc(): void
    {
        $this->seed(CpuSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/hardware/cpus');

        $response->assertOk();
        $data = $response->json();

        $this->assertIsArray($data);
        $this->assertLessThanOrEqual(20, count($data));
        $this->assertGreaterThan(0, count($data));

        $marks = array_column($data, 'single_thread_mark');
        $sorted = $marks;
        rsort($sorted);
        $this->assertSame($sorted, $marks, 'Results must be sorted single_thread_mark DESC');

        $first = $data[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('manufacturer', $first);
        $this->assertArrayHasKey('single_thread_mark', $first);
        $this->assertArrayHasKey('tier', $first);
        $this->assertArrayHasKey('released_year', $first);
    }

    public function test_search_filters_by_case_insensitive_substring_match(): void
    {
        $this->seed(CpuSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/hardware/cpus?search=ryzen');

        $response->assertOk();
        $names = array_column($response->json(), 'name');

        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertStringContainsStringIgnoringCase('ryzen', $name);
        }
    }

    public function test_search_containing_wildcard_characters_is_escaped(): void
    {
        $this->seed(CpuSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/hardware/cpus?search=%25');

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_search_over_100_chars_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/hardware/cpus?search='.str_repeat('a', 101))
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run tests — expect fail**

Run: `make test -- --filter=CpuTypeaheadTest`
Expected: FAIL — "Route [GET /api/hardware/cpus] not defined".

- [ ] **Step 3: Extend the controller with `cpus()`**

Edit `app/Http/Controllers/HardwareController.php` — add the import and method:

```php
use App\Models\Cpu;
// ...

public function cpus(Request $request): JsonResponse
{
    $validated = $request->validate([
        'search' => ['nullable', 'string', 'max:100'],
    ]);

    $query = Cpu::query()->select(Cpu::RESPONSE_FIELDS);

    if (! empty($validated['search'])) {
        $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $validated['search']);
        $query->whereRaw("name like ? escape '!'", ["%{$search}%"]);
    }

    $rows = $query->orderByDesc('single_thread_mark')->limit(20)->get();

    return response()->json($rows);
}
```

- [ ] **Step 4: Register the route**

Edit `routes/api.php`. Add inside the `auth:sanctum` group, next to the GPU route from Task 4:

```php
Route::get('/hardware/cpus', [HardwareController::class, 'cpus'])
    ->name('hardware.cpus.search');
```

- [ ] **Step 5: Run tests — expect PASS**

Run: `make test -- --filter=CpuTypeaheadTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HardwareController.php \
        routes/api.php \
        tests/Feature/Hardware/CpuTypeaheadTest.php
git commit -m "[Sprint 4] add GET /api/hardware/cpus typeahead endpoint"
```

---

### Task 6: React `hardware.js` API client + `HardwareAutocomplete` component

**Files:**
- Create: `client/src/lib/hardware.js`
- Create: `client/src/components/hardware/HardwareAutocomplete.jsx`

**Interfaces:**
- Consumes: existing `client/src/lib/api.js` (Axios instance with CSRF flow); `GET /api/hardware/gpus`, `GET /api/hardware/cpus` (Tasks 4–5).
- Produces:
  - `searchGpus({ search, signal }): Promise<Array<{id, name, manufacturer, g3d_mark, tier, released_year}>>`
  - `searchCpus({ search, signal }): Promise<Array<{id, name, manufacturer, single_thread_mark, tier, released_year}>>`
  - `<HardwareAutocomplete kind="gpu" | "cpu" value={selectedRow|null} onChange={(row) => ...} placeholder?="" />` — controlled component. Debounces search input by 300ms, cancels in-flight requests on the next keystroke via `AbortController`, renders results in a dropdown list, shows selected row in the input, exposes a "clear" button when a value is selected. Uses `benchmarkField` (`g3d_mark` for GPU, `single_thread_mark` for CPU) internally to render the score next to each row. Matches the debounce/AbortController pattern already used in `client/src/pages/Library.jsx` (search).

- [ ] **Step 1: Write the API client**

Create `client/src/lib/hardware.js`:

```javascript
import { api } from './api'

export async function searchGpus({ search, signal } = {}) {
  const { data } = await api.get('/api/hardware/gpus', {
    params: { search: search || undefined },
    signal,
  })
  return data
}

export async function searchCpus({ search, signal } = {}) {
  const { data } = await api.get('/api/hardware/cpus', {
    params: { search: search || undefined },
    signal,
  })
  return data
}
```

- [ ] **Step 2: Write the autocomplete component**

Create `client/src/components/hardware/HardwareAutocomplete.jsx`:

```jsx
import { useEffect, useRef, useState } from 'react'
import { searchGpus, searchCpus } from '../../lib/hardware'

const CONFIG = {
  gpu: { fetch: searchGpus, benchmarkField: 'g3d_mark', label: 'GPU' },
  cpu: { fetch: searchCpus, benchmarkField: 'single_thread_mark', label: 'CPU' },
}

export function HardwareAutocomplete({ kind, value, onChange, placeholder }) {
  const config = CONFIG[kind]
  if (!config) throw new Error(`HardwareAutocomplete: unknown kind "${kind}"`)

  const [query, setQuery] = useState('')
  const [results, setResults] = useState([])
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const abortRef = useRef(null)
  const containerRef = useRef(null)

  useEffect(() => {
    if (value) {
      setQuery(value.name)
    } else {
      setQuery('')
    }
  }, [value])

  useEffect(() => {
    if (!open) return
    if (abortRef.current) abortRef.current.abort()
    const controller = new AbortController()
    abortRef.current = controller

    const timer = setTimeout(async () => {
      setLoading(true)
      setError(null)
      try {
        const rows = await config.fetch({ search: query, signal: controller.signal })
        setResults(rows)
      } catch (err) {
        if (err.name !== 'CanceledError' && err.name !== 'AbortError') {
          setError('Search failed. Try again.')
        }
      } finally {
        setLoading(false)
      }
    }, 300)

    return () => {
      clearTimeout(timer)
      controller.abort()
    }
  }, [query, open, config])

  useEffect(() => {
    function onClickOutside(e) {
      if (containerRef.current && !containerRef.current.contains(e.target)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onClickOutside)
    return () => document.removeEventListener('mousedown', onClickOutside)
  }, [])

  function handleSelect(row) {
    onChange(row)
    setOpen(false)
  }

  function handleClear() {
    onChange(null)
    setQuery('')
    setOpen(true)
  }

  return (
    <div ref={containerRef} className="relative">
      <label className="mb-1 block text-sm font-medium text-slate-700">
        {config.label}
      </label>
      <div className="flex items-center gap-2">
        <input
          type="text"
          value={query}
          onChange={(e) => { setQuery(e.target.value); setOpen(true) }}
          onFocus={() => setOpen(true)}
          placeholder={placeholder ?? `Search for a ${config.label}…`}
          className="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
        />
        {value && (
          <button
            type="button"
            onClick={handleClear}
            className="text-xs text-slate-500 hover:text-slate-700"
          >
            Clear
          </button>
        )}
      </div>
      {open && (
        <div className="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded border border-slate-300 bg-white shadow-lg">
          {loading && <div className="p-2 text-sm text-slate-500">Loading…</div>}
          {error && <div className="p-2 text-sm text-rose-600">{error}</div>}
          {!loading && !error && results.length === 0 && (
            <div className="p-2 text-sm text-slate-500">No matches.</div>
          )}
          {!loading && !error && results.map((row) => (
            <button
              key={row.id}
              type="button"
              onClick={() => handleSelect(row)}
              className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-100"
            >
              <span>
                <span className="font-medium">{row.name}</span>
                <span className="ml-2 text-xs text-slate-500">{row.manufacturer} · {row.released_year}</span>
              </span>
              <span className="text-xs text-slate-600">
                <span className="mr-2 uppercase">{row.tier}</span>
                <span>{row[config.benchmarkField].toLocaleString()}</span>
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
```

- [ ] **Step 3: Verify oxlint passes**

Run: `cd client && npm run lint`
Expected: exits 0 with no new warnings on the two new files (`hardware.js`, `HardwareAutocomplete.jsx`). Pre-existing warnings in `AuthContext.jsx` are unrelated to this diff and OK to ignore.

- [ ] **Step 4: Commit**

```bash
git add client/src/lib/hardware.js \
        client/src/components/hardware/HardwareAutocomplete.jsx
git commit -m "[Sprint 4] add HardwareAutocomplete component and hardware API client"
```

---

### Task 7: `/hardware` demo page + browser auto-detect signals

**Files:**
- Create: `client/src/lib/browserHardware.js`
- Create: `client/src/pages/Hardware.jsx`
- Modify: `client/src/App.jsx` (import + route)
- Modify: `client/src/pages/Dashboard.jsx` (add a link to `/hardware`)

**Interfaces:**
- Consumes: `HardwareAutocomplete` (Task 6), the two typeahead endpoints (Tasks 4–5).
- Produces:
  - `client/src/lib/browserHardware.js` — exports:
    - `readBrowserHints(): { cpuCores: number|null, deviceMemoryGb: number|null }` — synchronous read of `navigator.hardwareConcurrency` and `navigator.deviceMemory` (both may be undefined in some browsers; return `null` for missing values).
    - `probeWebGpu(): Promise<{ supported: boolean, adapterInfo: string|null }>` — best-effort. Calls `navigator.gpu?.requestAdapter()`, then `adapter.requestAdapterInfo?.()` if available, returns `{ supported: false, adapterInfo: null }` when the WebGPU API is missing or throws. **Do not attempt to identify the GPU model** — modern browsers deliberately mask this.
  - `client/src/pages/Hardware.jsx` — page renders:
    1. A "Browser detected" panel showing CPU cores, device memory, WebGPU support (and a short note *"The browser cannot reliably identify your specific GPU model for privacy reasons. Please select it manually below."*).
    2. `<HardwareAutocomplete kind="gpu" .../>` + `<HardwareAutocomplete kind="cpu" .../>`.
    3. A "Selected" summary card showing the two picks and their resolved tiers (e.g., "GPU tier: High · CPU tier: Enthusiast").
    4. Nothing is persisted server-side in this phase — selection lives in component state only. Phase 5 will lift this into the recommender form.
  - Route `/hardware` added under `ProtectedRoute` in `App.jsx`.
  - Dashboard gets a card/link pointing at `/hardware` so the page is reachable via navigation.

- [ ] **Step 1: Write the browser hints module**

Create `client/src/lib/browserHardware.js`:

```javascript
export function readBrowserHints() {
  const cpuCores = typeof navigator !== 'undefined' && typeof navigator.hardwareConcurrency === 'number'
    ? navigator.hardwareConcurrency
    : null
  const deviceMemoryGb = typeof navigator !== 'undefined' && typeof navigator.deviceMemory === 'number'
    ? navigator.deviceMemory
    : null
  return { cpuCores, deviceMemoryGb }
}

export async function probeWebGpu() {
  if (typeof navigator === 'undefined' || !navigator.gpu) {
    return { supported: false, adapterInfo: null }
  }
  try {
    const adapter = await navigator.gpu.requestAdapter()
    if (!adapter) return { supported: false, adapterInfo: null }
    if (typeof adapter.requestAdapterInfo === 'function') {
      const info = await adapter.requestAdapterInfo()
      const parts = [info.vendor, info.architecture, info.device].filter(Boolean)
      return { supported: true, adapterInfo: parts.length ? parts.join(' · ') : 'Adapter present (vendor-masked)' }
    }
    return { supported: true, adapterInfo: 'Adapter present (vendor-masked)' }
  } catch {
    return { supported: false, adapterInfo: null }
  }
}
```

- [ ] **Step 2: Write the Hardware page**

Create `client/src/pages/Hardware.jsx`:

```jsx
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { HardwareAutocomplete } from '../components/hardware/HardwareAutocomplete'
import { readBrowserHints, probeWebGpu } from '../lib/browserHardware'

function TierBadge({ tier }) {
  if (!tier) return null
  const colors = {
    low: 'bg-slate-200 text-slate-700',
    mid: 'bg-sky-200 text-sky-800',
    high: 'bg-emerald-200 text-emerald-800',
    enthusiast: 'bg-fuchsia-200 text-fuchsia-800',
  }
  return (
    <span className={`inline-block rounded px-2 py-0.5 text-xs uppercase ${colors[tier] ?? 'bg-slate-200 text-slate-700'}`}>
      {tier}
    </span>
  )
}

export default function Hardware() {
  const [gpu, setGpu] = useState(null)
  const [cpu, setCpu] = useState(null)
  const [hints, setHints] = useState({ cpuCores: null, deviceMemoryGb: null })
  const [webgpu, setWebgpu] = useState({ supported: false, adapterInfo: null })

  useEffect(() => {
    setHints(readBrowserHints())
    probeWebGpu().then(setWebgpu)
  }, [])

  return (
    <div className="mx-auto max-w-3xl p-8">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-semibold text-slate-900">Hardware profile</h1>
        <Link to="/dashboard" className="text-sm text-slate-500 hover:text-slate-700">← Dashboard</Link>
      </div>

      <section className="mb-6 rounded border border-slate-200 bg-slate-50 p-4">
        <h2 className="mb-2 text-sm font-semibold text-slate-700">Browser detected</h2>
        <ul className="mb-3 space-y-1 text-sm text-slate-700">
          <li><strong>CPU cores:</strong> {hints.cpuCores ?? 'Not exposed by this browser'}</li>
          <li><strong>Device memory (GB, rounded):</strong> {hints.deviceMemoryGb ?? 'Not exposed by this browser'}</li>
          <li><strong>WebGPU:</strong> {webgpu.supported ? webgpu.adapterInfo : 'Not supported / disabled'}</li>
        </ul>
        <p className="text-xs text-slate-500">
          The browser cannot reliably identify your specific GPU model for privacy reasons. Please
          select it manually below.
        </p>
      </section>

      <section className="mb-6 space-y-4 rounded border border-slate-200 p-4">
        <HardwareAutocomplete kind="gpu" value={gpu} onChange={setGpu} />
        <HardwareAutocomplete kind="cpu" value={cpu} onChange={setCpu} />
      </section>

      <section className="rounded border border-slate-200 bg-white p-4">
        <h2 className="mb-3 text-sm font-semibold text-slate-700">Selected</h2>
        <div className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <div className="text-slate-500">GPU</div>
            {gpu ? (
              <div>
                <div className="font-medium">{gpu.name}</div>
                <div className="mt-1 flex items-center gap-2 text-xs text-slate-600">
                  <TierBadge tier={gpu.tier} />
                  <span>{gpu.g3d_mark.toLocaleString()} G3D</span>
                </div>
              </div>
            ) : <div className="text-slate-400">Not selected</div>}
          </div>
          <div>
            <div className="text-slate-500">CPU</div>
            {cpu ? (
              <div>
                <div className="font-medium">{cpu.name}</div>
                <div className="mt-1 flex items-center gap-2 text-xs text-slate-600">
                  <TierBadge tier={cpu.tier} />
                  <span>{cpu.single_thread_mark.toLocaleString()} ST</span>
                </div>
              </div>
            ) : <div className="text-slate-400">Not selected</div>}
          </div>
        </div>
        <p className="mt-4 text-xs text-slate-400">
          Selection is not saved server-side in this phase. It will feed the recommender in Phase 5.
        </p>
      </section>
    </div>
  )
}
```

- [ ] **Step 3: Wire the route in `App.jsx`**

Edit `client/src/App.jsx`:

Add the import near the other page imports:

```jsx
import Hardware from './pages/Hardware'
```

Inside the `<Routes>` block, add the new route alongside the other protected routes (e.g., right after the `/history` route):

```jsx
<Route path="/hardware" element={<ProtectedRoute><Hardware /></ProtectedRoute>} />
```

- [ ] **Step 4: Add a Dashboard link**

Edit `client/src/pages/Dashboard.jsx`. Find the section where existing feature links (Library / History) are rendered and add an analogous entry pointing at `/hardware`. If the Dashboard uses cards, add a "Hardware profile" card with copy: *"Pick your GPU and CPU. Tier is derived from an absolute benchmark table."* If no card pattern exists, add a plain `<Link to="/hardware">Hardware profile</Link>` in the same grouping as the other section links.

- [ ] **Step 5: Verify oxlint passes**

Run: `cd client && npm run lint`
Expected: exits 0 with no new warnings on the three new/modified files.

- [ ] **Step 6: Manual browser smoke test**

Start the stack + Vite dev server (host):

```bash
make up
cd client && npm run dev
```

In the browser (logged in as a seeded user), navigate to `http://localhost:5173/hardware` and verify:

1. **Browser detected panel** shows *some* CPU-cores number (all browsers expose `hardwareConcurrency`; Firefox may report a coarsened value). Chrome may show device memory; Firefox and Safari may not — the "Not exposed by this browser" fallback text must render for whichever fields are absent.
2. **GPU autocomplete** with empty query shows the top 20 GPUs sorted enthusiast → high → mid → low.
3. **Typing "4070"** in the GPU picker debounces (~300ms), then shows only rows matching "4070" (RTX 4070 / 4070 Super / 4070 Ti / 4070 Ti Super).
4. **Selecting a row** puts it in the "Selected" card at the bottom with the correct tier badge.
5. **CPU autocomplete** behaves analogously.
6. **Clear button** empties the selection and reopens the dropdown.
7. Open DevTools Network → typing rapidly must show earlier requests **cancelled** (not accumulating), only the last request completing.

Record any failure with a screenshot in the commit message.

- [ ] **Step 7: Commit**

```bash
git add client/src/lib/browserHardware.js \
        client/src/pages/Hardware.jsx \
        client/src/App.jsx \
        client/src/pages/Dashboard.jsx
git commit -m "[Sprint 4] add /hardware demo page with autocomplete and browser hints"
```

---

### Task 8: DECISIONS.md entries, phase-tracker updates, close

**Files:**
- Modify: `docs/DECISIONS.md`
- Modify: `docs/cortex-lite-build-plan.md` (check off the Hardware tier database + Browser auto-detect items)
- Modify: `README.md` (sprint changelog append)

**Interfaces:**
- Consumes: nothing from earlier tasks (this is documentation).
- Produces: three new ADR entries in `DECISIONS.md` (absolute-tier-threshold rationale for CPU, browser-cannot-identify-GPU-model, tier-derived-at-seed-time), boxes checked in the build plan, a Sprint 4 line in the README changelog.

- [ ] **Step 1: Add the CPU absolute-thresholds decision to `DECISIONS.md`**

Append to `docs/DECISIONS.md` (below the existing `Phase 4.0 spike` entry):

```markdown
### CPU tier absolute thresholds (single-thread PassMark)
**Date:** 2026-07-03
**Decision:** Classify CPUs into 4 tiers (Low / Mid / High / Enthusiast) using absolute PassMark single-thread scores. Boundaries: Low `< 2800`, Mid `2800–3399`, High `3400–3999`, Enthusiast `≥ 4000`.
**Rationale:** Same reason absolute thresholds were chosen for GPUs — a percentile cut across the whole PassMark dataset would put a Ryzen 5 5600X (Zen 3, 2020) in "high tier" simply because half the dataset is 15-year-old CPUs. The chosen bands map cleanly to how gamers actually reason about their chips: pre-Zen 3 / pre-12th-gen (Low + Mid), Zen 3 + 11th–12th gen (High), Zen 4 + 13th gen and newer (Enthusiast). Single-thread is used (not multi-thread) because game engines still bottleneck on the primary game thread.
**Alternatives considered:** Multi-thread PassMark (rejected — a 16-core Threadripper looks amazing on multi-thread but is often *worse* than an 8-core Zen 4 for gaming). Percentile (rejected — same skew problem as GPUs). Cinebench scores (rejected — same shape but harder to source for older parts).
**Consequences:** `database/data/cpus.json` stores the raw single-thread score; `CpuTierClassifier` derives the tier at seed time. Threshold changes are a one-line edit in the classifier plus a `make artisan CMD="db:seed --class=CpuSeeder"` rerun.
```

- [ ] **Step 2: Add the "browser cannot identify GPU model" decision**

Append to `docs/DECISIONS.md`:

```markdown
### Browser cannot identify the GPU model (best-effort auto-detect only)
**Date:** 2026-07-03
**Decision:** The `/hardware` page reads `navigator.hardwareConcurrency`, `navigator.deviceMemory`, and probes for WebGPU adapter presence, but does not attempt to identify the specific GPU model. The UI shows an explicit note that GPU selection is manual.
**Rationale:** Modern browsers deliberately mask the GPU vendor/model behind a "vendor-masked" WebGPU adapter for fingerprinting-resistance reasons. `WEBGL_debug_renderer_info` was the workaround in the WebGL era but is either blocked or coarsened in current Chrome/Firefox/Safari. Silently guessing produces wrong answers; asking the user is honest and takes three keystrokes on a typeahead.
**Alternatives considered:** Query `WEBGL_debug_renderer_info` (rejected — deprecated / gated behind privacy flags, wrong signal for a portfolio interview). Ship a browser extension that reads the OS-level device (rejected — massively out of scope for the web layer, and dedicated to the "why didn't you build the native agent" hand-wave). Use `navigator.userAgentData.getHighEntropyValues(['bitness'])` (rejected — still doesn't identify the GPU).
**Consequences:** The interview answer to *"how do you get the user's GPU?"* is: *"You can't from the browser. That's what the native agent is for — see `NATIVE_AGENT_CONTRACT.md`. The web layer takes a manual pick."* Turning a limitation into a demonstrated system-boundary answer.
```

- [ ] **Step 3: Add the "tier derived at seed time" decision**

Append to `docs/DECISIONS.md`:

```markdown
### Tier column derived at seed time (not stored in JSON data files)
**Date:** 2026-07-03
**Decision:** `database/data/gpus.json` and `cpus.json` store only the raw benchmark number and identifying metadata. The `tier` column is derived by `GpuTierClassifier` / `CpuTierClassifier` at seed time.
**Rationale:** Adding a new GPU is a one-line raw-data edit; the classifier assigns the tier deterministically. Adjusting a threshold is a single-constant edit in the classifier plus a seeder rerun — no JSON churn, no risk of drift between the stored tier and the boundary rules. Symmetrically shaped for CPUs.
**Alternatives considered:** Store the tier in JSON (rejected — every threshold tweak becomes a mass JSON edit with drift risk). Compute tier on read via an accessor (rejected — the endpoint filters and orders by tier, so having it materialised is faster and simpler).
**Consequences:** Any change to `GpuTierClassifier::THRESHOLDS` requires `make artisan CMD="db:seed --class=GpuSeeder"` to propagate. Documented in the seeder docblocks.
```

- [ ] **Step 4: Check off the build-plan items**

Edit `docs/cortex-lite-build-plan.md`. In the **Hardware tier database** section under Phase 4, change these unchecked boxes to checked:

- `[x] **Hand-curate \`gpus.json\`** …`
- `[x] **Hand-curate \`cpus.json\`** …`
- `[x] Document tier-threshold rationale in \`DECISIONS.md\`: …`
- `[x] Schemas: \`gpus\` and \`cpus\` tables matching the JSON shape.`
- `[x] Laravel seeders ingest the JSON files. Run them as part of the deployment process.`
- `[x] Build \`GET /api/hardware/gpus?search=...\` and \`GET /api/hardware/cpus?search=...\` — typeahead endpoints for the hardware-selection UI.`
- `[x] React: hardware input form with autocomplete dropdowns. Order results by \`g3d_mark\` desc within filter matches.`

In the **Browser-side hardware auto-detect (best-effort)** subsection:

- `[x] Use \`navigator.hardwareConcurrency\`, \`navigator.deviceMemory\`, and the WebGPU API …`
- `[x] **Be honest about the limits.** …`

Do NOT check off PCGamingWiki, Anchor settings dataset, or Heuristic recommender — those are separate slices of Phase 4 handled by later plans.

- [ ] **Step 5: Append a Sprint 4 line to the README changelog**

Edit `README.md`. Locate the sprint changelog section (added at the end of each phase). Append:

```markdown
### Sprint 4a — Hardware tier database
Hand-curated `gpus.json` (~60 rows) and `cpus.json` (~40 rows) seeded via idempotent Laravel seeders. Tier is derived by pure PHP classifiers using absolute benchmark thresholds (GPU G3D Mark and CPU single-thread PassMark) — the classifier is the single source of truth for boundary rules. Two auth-gated typeahead endpoints (`GET /api/hardware/gpus`, `GET /api/hardware/cpus`) power a reusable React `HardwareAutocomplete` component and a `/hardware` demo page that also surfaces browser-detected CPU cores, device memory, and WebGPU adapter presence — with an explicit note that browsers can't identify the GPU model.
```

- [ ] **Step 6: Run the full test suite**

Run: `make test`
Expected: all tests green, including the 34+ Phase 4 tests added across Tasks 1–5 (schema x2, classifier x2, seeder x2, typeahead x2). Total new tests: ~28 feature + 8 unit.

- [ ] **Step 7: Verify docs are internally consistent**

Run: `grep -n "tier" docs/DECISIONS.md | head -20`
Expected: at least the three new tier-related headings appear in order.

Run: `grep -n "\[x\]" docs/cortex-lite-build-plan.md | wc -l`
Expected: the count went up by exactly 9 vs. before Task 8 (7 hardware + 2 browser auto-detect).

- [ ] **Step 8: Commit and push**

```bash
git add docs/DECISIONS.md docs/cortex-lite-build-plan.md README.md
git commit -m "[Sprint 4] document hardware tier database decisions and close phase-4 hardware slice"

git push -u origin $(git rev-parse --abbrev-ref HEAD)
```

Deliverable: Hardware Tier Database slice of Phase 4 shipped, tests green, docs coherent. Phase 4 remaining work (PCGamingWiki client + game_metadata schema + scheduled ingestion job, anchor settings dataset + heuristic recommender) is untouched and handled in follow-up plans.

---

## Self-review notes

- **Spec coverage:** every unchecked bullet under Phase 4 → "Hardware tier database" and "Browser-side hardware auto-detect (best-effort)" is covered by a task (verified against Task 8 checklist).
- **PCGamingWiki, anchor dataset, heuristic recommender:** intentionally out of scope — separate plans, called out in Global Constraints and Task 8.
- **Types:** GPU response field is `g3d_mark`, CPU is `single_thread_mark` — consistently used in Task 6's `HardwareAutocomplete` `benchmarkField` config, in Task 7's tier badge display, and asserted in Tasks 4–5 tests.
- **Tier enum:** the four strings `low / mid / high / enthusiast` are consistent across model constants (Task 1), classifier return values (Tasks 2–3), factory logic (Task 1), seeder-test assertions (Tasks 2–3), and UI colour map (Task 7).
