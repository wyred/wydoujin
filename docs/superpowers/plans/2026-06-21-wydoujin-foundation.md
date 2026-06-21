# wydoujin — Foundation & Data Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a runnable Laravel 13 app with the full MySQL data model, frontend tooling, optional auth, and a single-image Docker build wired to GitHub Actions — the foundation every later plan builds on.

**Architecture:** A single Laravel 13 monolith served by FrankenPHP. One Docker image runs web + queue worker + scheduler under s6-overlay. MySQL is external by configuration. Blade + Tailwind + Alpine.js for the UI. This plan delivers the scaffold, schema, models, auth gate, and CI/build pipeline — no scanning/reader logic yet.

**Tech Stack:** Laravel 13 (PHP 8.3+), MySQL 8, Blade, Tailwind CSS, Alpine.js, Vite, FrankenPHP, s6-overlay, Docker, GitHub Actions, Intervention Image (installed here, used in a later plan).

## Global Constraints

- **Framework:** Laravel 13. Pin in `composer.json` (`laravel/framework: ^13.0`). Verify the installed version before relying on version-specific APIs.
- **PHP:** 8.3 or newer.
- **JS libraries:** Alpine.js is the **only** permitted JS library. No SPA framework, no jQuery.
- **Design system:** Use the vendored Apple Design System (`resources/design-system/`). Reference its CSS variables (`var(--color-primary)`, `var(--radius-pill)`, `var(--type-*)`, etc.) — never inline a raw hex or size. Dark mode is `data-dark="true"` on `<html>`. Weight ladder is 300/400/600/700 (no 500).
- **Database:** MySQL only. All connection details come from env (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Never hardcode. Tests run against MySQL (a `wydoujin_test` database), not SQLite.
- **Deployment:** One Docker image. Library mounted read-only at `/library`; writable data at `/data`.
- **Auth:** Single-user. `APP_PASSWORD` unset → open; set → one password gate. No users table.
- **Identity:** A work is identified by `content_hash` (the zip entry-list hash), never by path. (Used in later plans; the column is created here.)
- **Workflow:** TDD. Small, frequent commits. DRY. YAGNI.

---

## File Structure

Created or modified in this plan:

- `composer.json` / `composer.lock` — PHP deps (Laravel 13, intervention/image).
- `package.json` / `vite.config.js` — frontend build (Tailwind, Alpine).
- `tailwind.config.js`, `resources/css/app.css`, `resources/js/app.js` — frontend entrypoints.
- `resources/design-system/**` — vendored Apple Design System. Already in the repo. CSS tokens (`styles.css`, `ds-tokens/*.css`) are imported live by `app.css`; `components/` are React reference for Blade translation in Plan 5.
- `resources/views/layouts/app.blade.php` — base layout.
- `resources/views/welcome.blade.php` — replaced with a minimal home.
- `.env.example` — documents all env vars incl. external MySQL.
- `phpunit.xml` — test DB config (MySQL `wydoujin_test`).
- `routes/web.php` — health route + login routes.
- `app/Http/Middleware/RequirePassword.php` — optional password gate.
- `app/Http/Controllers/Auth/PasswordLoginController.php` — login form + submit.
- `resources/views/auth/login.blade.php` — login form.
- `bootstrap/app.php` — register middleware.
- `database/migrations/*` — mangaka, series, works, reading_progress, scans.
- `app/Models/{Mangaka,Series,Work,ReadingProgress,Scan}.php` — Eloquent models.
- `database/factories/*` — model factories for tests.
- `Dockerfile`, `.dockerignore` — FrankenPHP image.
- `docker/s6/**` — s6-overlay service definitions (web, worker, scheduler).
- `docker-compose.yml` — app + optional mysql, volumes.
- `.github/workflows/ci.yml`, `.github/workflows/build.yml` — tests + image build/push.

---

## Task 1: Scaffold Laravel 13 app with a health route

**Files:**
- Create: whole Laravel skeleton (via installer)
- Modify: `routes/web.php`
- Test: `tests/Feature/HealthTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `GET /health` route returning JSON `{"status":"ok"}` (200). Later tasks rely on the app booting and `php artisan test` working.

- [ ] **Step 1: Create the project**

```bash
composer create-project "laravel/laravel:^13.0" . 
```
If the directory is non-empty (it contains `docs/`), scaffold in a temp dir and move files in:
```bash
composer create-project "laravel/laravel:^13.0" /tmp/wydoujin-skel
cp -R /tmp/wydoujin-skel/. .
rm -rf /tmp/wydoujin-skel
```

- [ ] **Step 2: Generate app key and verify it boots**

Run:
```bash
php artisan key:generate
php artisan --version
```
Expected: prints `Laravel Framework 13.x.x`.

- [ ] **Step 3: Write the failing test**

`tests/Feature/HealthTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test --filter=HealthTest`
Expected: FAIL (404 — route not defined).

- [ ] **Step 5: Add the route**

Append to `routes/web.php`:
```php
Route::get('/health', fn () => response()->json(['status' => 'ok']));
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=HealthTest`
Expected: PASS.

- [ ] **Step 7: Pin Laravel and commit**

Ensure `composer.json` `require` has `"laravel/framework": "^13.0"`.
```bash
git add -A
git commit -m "feat: scaffold Laravel 13 app with health route"
```

---

## Task 2: MySQL test configuration

**Files:**
- Modify: `.env.example`, `phpunit.xml`

**Interfaces:**
- Consumes: the booted app from Task 1.
- Produces: `php artisan test` runs against a MySQL `wydoujin_test` database via `RefreshDatabase`. Later DB tasks depend on this.

- [ ] **Step 1: Document env vars in `.env.example`**

Set these keys in `.env.example`:
```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=wydoujin
DB_USERNAME=wydoujin
DB_PASSWORD=

APP_PASSWORD=
LIBRARY_PATH=/library
DATA_PATH=/data
```

- [ ] **Step 2: Configure the test database in `phpunit.xml`**

In the `<php>` section of `phpunit.xml`, set/replace these env entries (remove any `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` lines):
```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="wydoujin_test"/>
```

- [ ] **Step 3: Create the test database locally**

Run:
```bash
mysql -h 127.0.0.1 -u root -e "CREATE DATABASE IF NOT EXISTS wydoujin_test;"
```
Expected: no error. (Use real local credentials; this database is only for tests.)

- [ ] **Step 4: Verify the test suite still runs against MySQL**

Run: `php artisan test --filter=HealthTest`
Expected: PASS (now using the MySQL connection).

- [ ] **Step 5: Commit**

```bash
git add .env.example phpunit.xml
git commit -m "chore: configure MySQL test database"
```

---

## Task 3: Data model migrations

**Files:**
- Create: `database/migrations/2026_06_21_000001_create_mangaka_table.php`
- Create: `database/migrations/2026_06_21_000002_create_series_table.php`
- Create: `database/migrations/2026_06_21_000003_create_works_table.php`
- Create: `database/migrations/2026_06_21_000004_create_reading_progress_table.php`
- Create: `database/migrations/2026_06_21_000005_create_scans_table.php`
- Test: `tests/Feature/SchemaTest.php`

**Interfaces:**
- Consumes: MySQL connection from Task 2.
- Produces: tables `mangaka`, `series`, `works`, `reading_progress`, `scans` with the columns below. Models in Task 4 map to these.

- [ ] **Step 1: Write the failing schema test**

`tests/Feature/SchemaTest.php`:
```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_tables_and_key_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('mangaka'));
        $this->assertTrue(Schema::hasTable('series'));
        $this->assertTrue(Schema::hasTable('works'));
        $this->assertTrue(Schema::hasTable('reading_progress'));
        $this->assertTrue(Schema::hasTable('scans'));

        $this->assertTrue(Schema::hasColumns('works', [
            'content_hash', 'mangaka_id', 'series_id', 'relative_path',
            'title', 'title_raw', 'sort_title', 'event', 'circle', 'author',
            'parody', 'language', 'flags', 'entries', 'page_count',
            'cover_path', 'file_size', 'file_mtime', 'last_seen_at',
            'is_missing', 'series_locked',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SchemaTest`
Expected: FAIL (tables don't exist).

- [ ] **Step 3: Create the `mangaka` migration**

`database/migrations/2026_06_21_000001_create_mangaka_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mangaka', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mangaka');
    }
};
```

- [ ] **Step 4: Create the `series` migration**

`database/migrations/2026_06_21_000002_create_series_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mangaka_id')->constrained('mangaka')->cascadeOnDelete();
            $table->string('name');
            $table->string('sort_name')->nullable();
            $table->boolean('is_auto')->default(true);
            $table->unsignedBigInteger('cover_work_id')->nullable();
            $table->timestamps();
            $table->index('mangaka_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};
```

- [ ] **Step 5: Create the `works` migration**

`database/migrations/2026_06_21_000003_create_works_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->string('content_hash', 64)->unique();
            $table->foreignId('mangaka_id')->constrained('mangaka')->cascadeOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('series')->nullOnDelete();
            $table->string('relative_path', 1024);
            $table->string('filename');
            $table->string('title');
            $table->string('title_raw');
            $table->string('sort_title')->nullable();
            $table->string('event')->nullable();
            $table->string('circle')->nullable();
            $table->string('author')->nullable();
            $table->string('parody')->nullable();
            $table->string('language')->nullable();
            $table->json('flags')->nullable();
            $table->json('entries')->nullable();
            $table->unsignedInteger('page_count')->default(0);
            $table->string('cover_path')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedBigInteger('file_mtime')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_missing')->default(false);
            $table->boolean('series_locked')->default(false);
            $table->timestamps();

            $table->index('mangaka_id');
            $table->index('series_id');
            $table->index('parody');
            $table->index('circle');
            $table->index('event');
            $table->index('is_missing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('works');
    }
};
```

- [ ] **Step 6: Create the `reading_progress` migration**

`database/migrations/2026_06_21_000004_create_reading_progress_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reading_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->unique()->constrained('works')->cascadeOnDelete();
            $table->unsignedInteger('current_page')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_progress');
    }
};
```

- [ ] **Step 7: Create the `scans` migration**

`database/migrations/2026_06_21_000005_create_scans_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('queued'); // queued|running|completed|failed
            $table->string('triggered_by')->default('manual'); // manual|scheduled
            $table->json('stats')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=SchemaTest`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add database/migrations tests/Feature/SchemaTest.php
git commit -m "feat: add core data model migrations"
```

---

## Task 4: Eloquent models and factories

**Files:**
- Create: `app/Models/Mangaka.php`, `Series.php`, `Work.php`, `ReadingProgress.php`, `Scan.php`
- Create: `database/factories/MangakaFactory.php`, `SeriesFactory.php`, `WorkFactory.php`
- Test: `tests/Feature/ModelRelationsTest.php`

**Interfaces:**
- Consumes: tables from Task 3.
- Produces:
  - `Mangaka` hasMany `works`, hasMany `series`.
  - `Series` belongsTo `mangaka`, hasMany `works`.
  - `Work` belongsTo `mangaka`, belongsTo `series` (nullable), hasOne `readingProgress`. Casts: `flags` array, `entries` array, `is_missing` bool, `series_locked` bool, `last_seen_at` datetime.
  - `ReadingProgress` belongsTo `work`. Casts: `is_completed` bool, timestamps datetime.
  - `Scan` casts: `stats` array, `started_at`/`finished_at` datetime.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ModelRelationsTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationships_and_casts(): void
    {
        $mangaka = Mangaka::factory()->create();
        $series = Series::factory()->for($mangaka)->create();
        $work = Work::factory()
            ->for($mangaka)
            ->for($series)
            ->create(['flags' => ['DL版'], 'entries' => ['001.jpg', '002.jpg']]);

        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 3]);

        $this->assertTrue($mangaka->works->contains($work));
        $this->assertTrue($mangaka->series->contains($series));
        $this->assertTrue($series->works->contains($work));
        $this->assertEquals($mangaka->id, $work->mangaka->id);
        $this->assertEquals($series->id, $work->series->id);
        $this->assertSame(['DL版'], $work->flags);
        $this->assertSame(['001.jpg', '002.jpg'], $work->entries);
        $this->assertSame(3, $work->readingProgress->current_page);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ModelRelationsTest`
Expected: FAIL (model classes / factories missing).

- [ ] **Step 3: Create the `Mangaka` model**

`app/Models/Mangaka.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mangaka extends Model
{
    use HasFactory;

    protected $table = 'mangaka';
    protected $guarded = [];

    public function works(): HasMany
    {
        return $this->hasMany(Work::class);
    }

    public function series(): HasMany
    {
        return $this->hasMany(Series::class);
    }
}
```

- [ ] **Step 4: Create the `Series` model**

`app/Models/Series.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Series extends Model
{
    use HasFactory;

    protected $table = 'series';
    protected $guarded = [];
    protected $casts = ['is_auto' => 'boolean'];

    public function mangaka(): BelongsTo
    {
        return $this->belongsTo(Mangaka::class);
    }

    public function works(): HasMany
    {
        return $this->hasMany(Work::class);
    }
}
```

- [ ] **Step 5: Create the `Work` model**

`app/Models/Work.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Work extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'flags' => 'array',
        'entries' => 'array',
        'is_missing' => 'boolean',
        'series_locked' => 'boolean',
        'last_seen_at' => 'datetime',
        'page_count' => 'integer',
        'file_size' => 'integer',
        'file_mtime' => 'integer',
    ];

    public function mangaka(): BelongsTo
    {
        return $this->belongsTo(Mangaka::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function readingProgress(): HasOne
    {
        return $this->hasOne(ReadingProgress::class);
    }
}
```

- [ ] **Step 6: Create the `ReadingProgress` model**

`app/Models/ReadingProgress.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingProgress extends Model
{
    protected $table = 'reading_progress';
    protected $guarded = [];

    protected $casts = [
        'is_completed' => 'boolean',
        'current_page' => 'integer',
        'started_at' => 'datetime',
        'last_read_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }
}
```

- [ ] **Step 7: Create the `Scan` model**

`app/Models/Scan.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stats' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
```

- [ ] **Step 8: Create the factories**

`database/factories/MangakaFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Mangaka;
use Illuminate\Database\Eloquent\Factories\Factory;

class MangakaFactory extends Factory
{
    protected $model = Mangaka::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);
        return ['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999)];
    }
}
```

`database/factories/SeriesFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Mangaka;
use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeriesFactory extends Factory
{
    protected $model = Series::class;

    public function definition(): array
    {
        return [
            'mangaka_id' => Mangaka::factory(),
            'name' => $this->faker->words(2, true),
            'is_auto' => true,
        ];
    }
}
```

`database/factories/WorkFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WorkFactory extends Factory
{
    protected $model = Work::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(3);
        return [
            'content_hash' => hash('sha256', Str::uuid()->toString()),
            'mangaka_id' => Mangaka::factory(),
            'relative_path' => $this->faker->word().'/'.$title.'.zip',
            'filename' => $title.'.zip',
            'title' => $title,
            'title_raw' => $title,
            'page_count' => $this->faker->numberBetween(1, 200),
            'file_size' => $this->faker->numberBetween(1000, 5_000_000),
            'file_mtime' => time(),
            'last_seen_at' => now(),
        ];
    }
}
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test --filter=ModelRelationsTest`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Models database/factories tests/Feature/ModelRelationsTest.php
git commit -m "feat: add Eloquent models, relationships, and factories"
```

---

## Task 5: Frontend tooling (Tailwind + Alpine) and base layout

**Files:**
- Modify: `package.json`, `vite.config.js`, `resources/css/app.css`, `resources/js/app.js`
- Create: `tailwind.config.js`, `resources/views/layouts/app.blade.php`
- Modify: `resources/views/welcome.blade.php`
- Test: `tests/Feature/HomePageTest.php`

**Interfaces:**
- Consumes: app from Task 1.
- Produces: `GET /` returns 200 and renders the base layout containing `<title>wydoujin</title>`. Alpine is registered globally; Tailwind compiles. Later UI plans extend `layouts/app.blade.php`.

- [ ] **Step 1: Install frontend deps**

Run:
```bash
npm install -D tailwindcss @tailwindcss/vite
npm install alpinejs
```
(Tailwind v4 ships a Vite plugin; no PostCSS config needed.)

- [ ] **Step 2: Configure Vite**

`vite.config.js`:
```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
```

- [ ] **Step 3: Configure CSS and JS entrypoints**

`resources/css/app.css`:
```css
@import "tailwindcss";

/* Apple Design System — vendored tokens (see resources/design-system/SOURCE.md).
   This pulls in colors/typography/shape/spacing + the [data-dark] dark theme.
   Imported AFTER Tailwind so the design system owns the base look. */
@import "../design-system/styles.css";
```

> The `resources/design-system/` folder is already vendored in the repo (Apple
> Design System pulled from claude.ai/design). The token CSS is consumed live by
> this import; the React files under `components/` are reference only and are not
> imported anywhere.

`resources/js/app.js`:
```js
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();
```

- [ ] **Step 4: Create the base layout**

`resources/views/layouts/app.blade.php`:
```blade
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'wydoujin' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-neutral-950 text-neutral-100">
    <main>
        @yield('content')
    </main>
</body>
</html>
```

- [ ] **Step 5: Replace the welcome view**

`resources/views/welcome.blade.php`:
```blade
@extends('layouts.app')

@section('content')
    <div class="p-8">
        <h1 class="text-2xl font-semibold">wydoujin</h1>
    </div>
@endsection
```

- [ ] **Step 6: Write the failing test**

`tests/Feature/HomePageTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_home_renders_layout(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<title>wydoujin</title>', false);
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=HomePageTest`
Expected: PASS. (The default `/` route already returns `welcome`; it now renders the layout.)

- [ ] **Step 8: Build assets to confirm the toolchain works**

Run: `npm run build`
Expected: completes without error; writes `public/build/manifest.json`.

- [ ] **Step 9: Commit**

```bash
git add package.json package-lock.json vite.config.js tailwind.config.js resources tests/Feature/HomePageTest.php
git commit -m "feat: add Tailwind + Alpine frontend tooling and base layout"
```

---

## Task 6: Optional single-password auth gate

**Files:**
- Create: `app/Http/Middleware/RequirePassword.php`, `app/Http/Controllers/Auth/PasswordLoginController.php`, `resources/views/auth/login.blade.php`
- Modify: `bootstrap/app.php`, `routes/web.php`
- Test: `tests/Feature/AuthGateTest.php`

**Interfaces:**
- Consumes: `config('app.password')` (reads `APP_PASSWORD` env).
- Produces: middleware alias `auth.password` applied to the web group. When `APP_PASSWORD` is empty → all routes open. When set → unauthenticated requests to non-allowlisted routes redirect to `/login`; correct password sets session flag `password_ok=true`. `/health`, `/login` are always reachable.

- [ ] **Step 1: Add the config binding**

In `config/app.php`, add to the returned array:
```php
'password' => env('APP_PASSWORD'),
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/AuthGateTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthGateTest extends TestCase
{
    public function test_routes_open_when_no_password_set(): void
    {
        config(['app.password' => null]);
        $this->get('/')->assertOk();
    }

    public function test_protected_route_redirects_when_password_set(): void
    {
        config(['app.password' => 'secret']);
        $this->get('/')->assertRedirect('/login');
    }

    public function test_correct_password_grants_access(): void
    {
        config(['app.password' => 'secret']);
        $this->post('/login', ['password' => 'secret'])->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_wrong_password_is_rejected(): void
    {
        config(['app.password' => 'secret']);
        $this->from('/login')
            ->post('/login', ['password' => 'nope'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('password');
    }

    public function test_health_is_always_reachable(): void
    {
        config(['app.password' => 'secret']);
        $this->getJson('/health')->assertOk();
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=AuthGateTest`
Expected: FAIL (middleware/routes missing).

- [ ] **Step 4: Create the middleware**

`app/Http/Middleware/RequirePassword.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = config('app.password');

        if (empty($password)) {
            return $next($request);
        }

        if ($request->is('login', 'health')) {
            return $next($request);
        }

        if ($request->session()->get('password_ok') === true) {
            return $next($request);
        }

        return redirect('/login');
    }
}
```

- [ ] **Step 5: Create the login controller**

`app/Http/Controllers/Auth/PasswordLoginController.php`:
```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PasswordLoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        if (! hash_equals((string) config('app.password'), (string) $request->input('password'))) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $request->session()->put('password_ok', true);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
```

- [ ] **Step 6: Create the login view**

`resources/views/auth/login.blade.php`:
```blade
@extends('layouts.app')

@section('content')
    <div class="flex min-h-screen items-center justify-center p-8">
        <form method="POST" action="/login" class="w-full max-w-sm space-y-4">
            @csrf
            <h1 class="text-xl font-semibold">wydoujin</h1>
            <input type="password" name="password" autofocus
                   class="w-full rounded bg-neutral-800 px-3 py-2"
                   placeholder="Password">
            @error('password')
                <p class="text-sm text-red-400">{{ $message }}</p>
            @enderror
            <button type="submit" class="w-full rounded bg-indigo-600 px-3 py-2">Enter</button>
        </form>
    </div>
@endsection
```

- [ ] **Step 7: Register routes and middleware**

Add to `routes/web.php`:
```php
use App\Http\Controllers\Auth\PasswordLoginController;

Route::get('/login', [PasswordLoginController::class, 'show'])->name('login');
Route::post('/login', [PasswordLoginController::class, 'store']);
```

In `bootstrap/app.php`, inside `->withMiddleware(function (Middleware $middleware) { ... })`, append the gate to the web group:
```php
$middleware->web(append: [
    \App\Http\Middleware\RequirePassword::class,
]);
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=AuthGateTest`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http config/app.php resources/views/auth routes/web.php bootstrap/app.php tests/Feature/AuthGateTest.php
git commit -m "feat: add optional single-password auth gate"
```

---

## Task 7: Install Intervention Image

**Files:**
- Modify: `composer.json`

**Interfaces:**
- Consumes: nothing.
- Produces: `Intervention\Image\ImageManager` available for the scanner plan's cover generation.

- [ ] **Step 1: Require the package**

Run:
```bash
composer require intervention/image
```

- [ ] **Step 2: Verify it resolves**

Run:
```bash
php -r "require 'vendor/autoload.php'; echo class_exists(\Intervention\Image\ImageManager::class) ? 'ok' : 'missing';"
```
Expected: prints `ok`.

- [ ] **Step 3: Run the full suite to confirm nothing broke**

Run: `php artisan test`
Expected: PASS (all tests so far).

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: add intervention/image for cover generation"
```

---

## Task 8: Docker image (FrankenPHP + s6-overlay)

**Files:**
- Create: `Dockerfile`, `.dockerignore`
- Create: `docker/s6/s6-rc.d/web/run`, `docker/s6/s6-rc.d/worker/run`, `docker/s6/s6-rc.d/scheduler/run` and their `type` files
- Create: `docker/s6/s6-rc.d/user/contents.d/{web,worker,scheduler}` (empty marker files)

**Interfaces:**
- Consumes: the built app.
- Produces: an image that, when run, serves HTTP on port 8080, runs one queue worker, and runs the scheduler — all under s6. Health check hits `/health`.

- [ ] **Step 1: Create `.dockerignore`**

`.dockerignore`:
```
/vendor
/node_modules
/.git
/docs
/.env
/storage/logs/*
```

- [ ] **Step 2: Create the Dockerfile**

`Dockerfile`:
```dockerfile
# syntax=docker/dockerfile:1

# --- Frontend build ---
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY vite.config.js ./
RUN npm run build

# --- PHP deps ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader

# --- Runtime ---
FROM dunglas/frankenphp:1-php8.3 AS runtime

# s6-overlay
ARG S6_OVERLAY_VERSION=3.2.0.0
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz /tmp/
RUN tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz /tmp/
RUN tar -C / -Jxpf /tmp/s6-overlay-x86_64.tar.xz

# PHP extensions needed by Laravel + image work
RUN install-php-extensions pdo_mysql zip gd intl opcache pcntl

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY docker/s6/s6-rc.d /etc/s6-overlay/s6-rc.d

RUN mkdir -p /data /library \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data

ENV SERVER_NAME=:8080
EXPOSE 8080
ENTRYPOINT ["/init"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD curl -fsS http://127.0.0.1:8080/health || exit 1
```

- [ ] **Step 3: Create the s6 service definitions**

`docker/s6/s6-rc.d/web/type` (contents: `longrun`).
`docker/s6/s6-rc.d/web/run`:
```sh
#!/command/execlineb -P
frankenphp run --config /etc/frankenphp/Caddyfile
```
> If no custom Caddyfile is used, replace the run line with:
> `php artisan octane:frankenphp --host=0.0.0.0 --port=8080`
> only if Octane is installed; otherwise use FrankenPHP's default by setting
> `frankenphp php-server --listen :8080 --root /app/public`.

Use this concrete, dependency-free `web/run` instead:
```sh
#!/command/execlineb -P
frankenphp php-server --listen :8080 --root /app/public
```

`docker/s6/s6-rc.d/worker/type` (contents: `longrun`).
`docker/s6/s6-rc.d/worker/run`:
```sh
#!/command/with-contenv sh
exec php /app/artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

`docker/s6/s6-rc.d/scheduler/type` (contents: `longrun`).
`docker/s6/s6-rc.d/scheduler/run`:
```sh
#!/command/with-contenv sh
exec php /app/artisan schedule:work
```

Marker files (empty) so s6 starts them in the `user` bundle:
- `docker/s6/s6-rc.d/user/contents.d/web`
- `docker/s6/s6-rc.d/user/contents.d/worker`
- `docker/s6/s6-rc.d/user/contents.d/scheduler`

- [ ] **Step 4: Make run scripts executable**

Run:
```bash
chmod +x docker/s6/s6-rc.d/web/run docker/s6/s6-rc.d/worker/run docker/s6/s6-rc.d/scheduler/run
```

- [ ] **Step 5: Build the image**

Run:
```bash
docker build -t wydoujin:dev .
```
Expected: build completes successfully.

- [ ] **Step 6: Commit**

```bash
git add Dockerfile .dockerignore docker/
git commit -m "feat: add FrankenPHP + s6 Docker image"
```

---

## Task 9: docker-compose with optional MySQL and volumes

**Files:**
- Create: `docker-compose.yml`
- Modify: `README.md` (add run instructions)

**Interfaces:**
- Consumes: the image from Task 8.
- Produces: `docker compose up` brings up the app (and optional bundled MySQL) with `/library` and `/data` volumes; health check passes. External MySQL = comment out the `mysql` service and set `DB_HOST` to the external server.

- [ ] **Step 1: Create `docker-compose.yml`**

`docker-compose.yml`:
```yaml
services:
  app:
    image: wydoujin:dev
    build: .
    ports:
      - "8080:8080"
    environment:
      APP_KEY: ${APP_KEY}
      APP_PASSWORD: ${APP_PASSWORD:-}
      DB_HOST: ${DB_HOST:-mysql}
      DB_PORT: ${DB_PORT:-3306}
      DB_DATABASE: ${DB_DATABASE:-wydoujin}
      DB_USERNAME: ${DB_USERNAME:-wydoujin}
      DB_PASSWORD: ${DB_PASSWORD:-secret}
    volumes:
      - ${LIBRARY_PATH:-./library}:/library:ro
      - wydoujin_data:/data
    depends_on:
      - mysql

  # Optional: remove this service and point DB_HOST at your own server to use external MySQL.
  mysql:
    image: mysql:8
    environment:
      MYSQL_DATABASE: ${DB_DATABASE:-wydoujin}
      MYSQL_USER: ${DB_USERNAME:-wydoujin}
      MYSQL_PASSWORD: ${DB_PASSWORD:-secret}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:-rootsecret}
    volumes:
      - wydoujin_db:/var/lib/mysql

volumes:
  wydoujin_data:
  wydoujin_db:
```

- [ ] **Step 2: Validate the compose file**

Run:
```bash
docker compose config
```
Expected: prints the resolved config with no errors.

- [ ] **Step 3: Document usage in `README.md`**

Add a section:
```markdown
## Running

1. Copy `.env.example` to `.env` and run `php artisan key:generate` (or set `APP_KEY`).
2. Set `LIBRARY_PATH` to your `<mangaka>/<doujin>.zip` library.
3. `docker compose up -d`.
4. Run migrations: `docker compose exec app php artisan migrate --force`.

### External MySQL
Remove the `mysql` service from `docker-compose.yml` and set
`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` to your server.
```

- [ ] **Step 4: Commit**

```bash
git add docker-compose.yml README.md
git commit -m "feat: add docker-compose with optional MySQL and volumes"
```

---

## Task 10: GitHub Actions — CI tests and image build

**Files:**
- Create: `.github/workflows/ci.yml`, `.github/workflows/build.yml`

**Interfaces:**
- Consumes: the repo.
- Produces: `ci.yml` runs `php artisan test` against a MySQL service on push/PR. `build.yml` builds and pushes the image to GHCR on push to the default branch and on tags.

- [ ] **Step 1: Create the CI workflow**

`.github/workflows/ci.yml`:
```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8
        env:
          MYSQL_DATABASE: wydoujin_test
          MYSQL_ROOT_PASSWORD: root
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -proot"
          --health-interval=10s --health-timeout=5s --health-retries=10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_mysql, zip, gd, intl
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
      - run: composer install --no-interaction --prefer-dist
      - run: npm ci && npm run build
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan test
        env:
          DB_HOST: 127.0.0.1
          DB_PORT: 3306
          DB_DATABASE: wydoujin_test
          DB_USERNAME: root
          DB_PASSWORD: root
```

- [ ] **Step 2: Create the build/push workflow**

`.github/workflows/build.yml`:
```yaml
name: Build image

on:
  push:
    branches: [main]
    tags: ['v*']

jobs:
  build:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write
    steps:
      - uses: actions/checkout@v4
      - uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}
      - uses: docker/metadata-action@v5
        id: meta
        with:
          images: ghcr.io/${{ github.repository }}
      - uses: docker/build-push-action@v6
        with:
          context: .
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          labels: ${{ steps.meta.outputs.labels }}
```

- [ ] **Step 3: Validate YAML locally**

Run:
```bash
php -r "echo function_exists('yaml_parse') ? 'php-yaml' : 'use git push to validate';"
```
(If `yaml-lint` or `actionlint` is available, run `actionlint`. Otherwise rely on the next push to validate.)

- [ ] **Step 4: Commit**

```bash
git add .github/workflows
git commit -m "ci: add test workflow and GHCR image build"
```

---

## Self-Review Notes

- **Spec coverage:** Deployment (Tasks 1,8,9), external MySQL (Tasks 2,9), full data model incl. `content_hash`/`series_locked`/`entries`/`reading_progress`/`scans` (Task 3), models (Task 4), Tailwind+Alpine (Task 5), optional `APP_PASSWORD` auth (Task 6), Intervention Image dependency (Task 7), GitHub Actions with MySQL service (Task 10). Parser, scanning, series detection, reader, and browse surfaces are intentionally deferred to Plans 2–5.
- **Identity:** `content_hash` is `unique` and indexed (Task 3) per the spec's identity rule.
- **No SQLite:** test config uses MySQL throughout (Tasks 2, 10).
- **Type consistency:** model relationship names (`works`, `series`, `mangaka`, `readingProgress`) and casts match the migration columns used in the Task 4 test.
