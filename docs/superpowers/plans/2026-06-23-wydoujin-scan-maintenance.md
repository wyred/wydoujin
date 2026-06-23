# wydoujin — Scan & Maintenance (F3b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `/maintenance` surface — a web "Scan now" trigger with live status polling (queued→running→completed/failed + stats), recent-scan history, and an informational missing-works list — on top of the existing async scan pipeline.

**Architecture:** A one-line-additive change to `ScanLibrary` (an optional second `scanId` arg so a `queued` row exists from click-time; CLI/scheduler/existing tests untouched). A `MaintenanceController` exposes `GET /maintenance` (page), `POST /scan` (creates the queued row + dispatches, with a no-double-dispatch guard), and `GET /maintenance/status` (latest scan JSON). An Alpine `maintenance` component polls status ~2s and renders the live panel + history; missing-works are server-rendered.

**Tech Stack:** Laravel 13 Blade + Alpine.js + Tailwind v4; design tokens. No new dependencies, no schema changes.

**Spec:** `docs/superpowers/specs/2026-06-23-wydoujin-scan-maintenance-design.md`. Parent: `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §7, §10.

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+ (local dev 8.5). No `declare(strict_types=1)`.
- **Broken local toolchain:** prefix EVERY php command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5.4). Env doesn't persist between Bash calls — repeat it. Tests via `php artisan test`. Node/npm on the normal PATH.
- **Avoid `cd` in compound bash;** use absolute paths / `git -C`.
- **Commit trailer:** every commit ends with a blank line then exactly:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **PHP style:** single quotes unless interpolation; inline typed properties; short **bilingual (EN / JP)** doc comments on new classes/methods (match `MangakaController`/`ScanLibrary`).
- **Backward compatibility (critical):** the `ScanLibrary` change is **additive** — `triggeredBy` stays the FIRST constructor arg; `scanId` is an optional SECOND arg. Existing callers (`ScanLibrary::dispatch('manual')`, `new ScanLibrary('scheduled')`) and the existing `ScanLibraryJobTest` (no id) MUST keep passing unchanged.
- **Design tokens mandatory (§13).** Never inline a raw hex/size — reference a token. **One blue accent:** status colors use `--color-error` for `failed`; `queued`/`running`/`completed` use neutral/accent tokens — **no new green**. Tailwind plain utilities for structural layout only.
- **React-free:** register the Alpine component via `document.addEventListener('alpine:init', () => Alpine.data('maintenance', …))`. `@js($initial)` embeds initial state into `x-data`.
- **CSRF:** the `POST /scan` fetch sends `X-CSRF-TOKEN` from the `<meta name="csrf-token">` already in `layouts.app` (established pattern).
- **DB portability:** Eloquent only, no raw SQL. Feature tests use `RefreshDatabase` on in-memory SQLite + `$this->withoutVite()` for HTTP-render tests.
- **Auth gate is global** (`RequirePassword` on the `web` group) — `/maintenance`, `/scan`, `/maintenance/status` are auto-gated.
- **Workflow:** TDD, DRY, YAGNI, bite-sized commits.

## Scope Decisions (locked, per spec)

1. **F3b = scan trigger + status/history + missing-works only.** F3c (manual series merge/split/rename) is a separate later sub-project.
2. **Single `/maintenance` surface**; new **Maintenance** nav link.
3. **Live status via ~2s polling** while a scan is `queued`/`running`; stop at terminal.
4. **Record at dispatch:** `POST /scan` creates a `queued` Scan row, then `ScanLibrary::dispatch('manual', $scan->id)`.
5. **Additive backend change** (back-compatible — see Global Constraints).
6. **No-double-dispatch guard:** don't dispatch if a `queued`/`running` scan exists; return it.
7. **Missing works informational only** (never deleted; auto-clear on rescan).
8. **History + status panel Alpine-rendered from embedded JSON; missing-works server-rendered** (Blade + F1 pager).

**Out of scope (later):** F3c; cancel/retry a scan; delete/"forget" missing works; WebSockets/SSE; per-scan detail page.

## File Structure

- `app/Jobs/ScanLibrary.php` — **modify**. Add optional `?int $scanId` (2nd ctor arg); `handle()` updates that row if given, else creates one (today's behavior).
- `app/Http/Controllers/MaintenanceController.php` — **create**. `index` / `scan` / `status`.
- `routes/web.php` — **modify**. Add the 3 routes + the import.
- `resources/views/components/nav.blade.php` — **modify**. Add the **Maintenance** link.
- `resources/views/maintenance/index.blade.php` — **create**. Scan panel + history (Alpine) + missing-works (server-rendered) + the inline `maintenance` component.
- `tests/Feature/Scanning/ScanLibraryJobTest.php` — **modify**. Add tests for the `scanId` update path (Task 1).
- `tests/Feature/Maintenance/MaintenanceTest.php` — **create**. HTTP/JSON tests (Task 2).

**Reference — existing shapes (verbatim, do not re-derive):**

- `App\Jobs\ScanLibrary` (current): `final class ScanLibrary implements ShouldQueue` with `Dispatchable`/`InteractsWithQueue`/`Queueable`/`SerializesModels`; `__construct(public readonly string $triggeredBy = 'manual')`; `handle(ScannerContract $scanner, SeriesDetectorContract $detector): void` creates a `Scan` (status `running`), runs `array_merge($scanner->scan(), $detector->detect())`, updates to `completed` (or `failed` + `['error'=>...]` in a `catch (Throwable $e)` that `report($e)`s and does not re-throw).
- `App\Models\Scan` (`$guarded = []`): casts `stats=array`, `started_at`/`finished_at`=datetime. Columns: `status` (queued|running|completed|failed), `triggered_by` (manual|scheduled), `stats` (json), `started_at`, `finished_at`, timestamps.
- `scanner->scan()` stats keys: `added`, `updated`, `moved`, `missing`, `failed`. `detector->detect()` keys: `series_created`, `works_grouped`.
- `App\Models\Work`: `$casts['is_missing'=>'boolean']`; `mangaka()` BelongsTo. Missing query: `Work::where('is_missing', true)->with('mangaka')`.
- Existing `ScanLibraryJobTest` uses `RefreshDatabase` + the `BuildsLibraryFixtures` trait (`$this->bootLibrary()` in setUp, `$this->cleanLibrary()` in tearDown, `$this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg'])` to create a real zip the scanner can read).
- `ScanCommand`: `ScanLibrary::dispatch('manual')` (UNCHANGED by this plan). `routes/console.php`: `Schedule::job(new ScanLibrary('scheduled'))->daily();` (UNCHANGED).
- `routes/web.php` imports are a `use App\Http\Controllers\…;` block (alphabetical-ish); routes are registered with `->name(...)`.
- `<x-nav active="…" />`: links use `{{ $active === 'X' ? '[color:var(--color-on-dark)]' : '[color:var(--color-body-muted)]' }}` + `style="font:var(--type-nav);"`. Current links: Home, Mangaka, Browse.
- Page shell: `@extends('layouts.app')` → `@section('content')` → `<x-nav active="…" />` then `<main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">…</main>`. `layouts.app` `<head>` has `<meta name="csrf-token">`.
- F1 pager (from `mangaka/index.blade.php`): a centered `<nav>` with Prev / `Page {{ $p->currentPage() }} of {{ $p->lastPage() }}` / Next using `$p->previousPageUrl()`/`$p->nextPageUrl()` + `$p->onFirstPage()`/`$p->hasMorePages()`, styled with `var(--type-caption)`/`var(--text-link)`/`var(--text-muted)`.
- `<x-button>` (pill, primary/secondary, optional `href`, `:disabled` via attributes), `<x-section-heading>` (slot heading), `<x-badge>` (blue-tint pill).

---

## Task 1: `ScanLibrary` — optional scan id (record-at-dispatch support)

**Files:**
- Modify: `app/Jobs/ScanLibrary.php`
- Test: `tests/Feature/Scanning/ScanLibraryJobTest.php`

**Interfaces:**
- Produces: `ScanLibrary::__construct(string $triggeredBy = 'manual', ?int $scanId = null)`; `dispatch('manual', $scanId)` updates the row `$scanId` points to (→ running → completed/failed); with no id it creates a row (unchanged). Public readonly props `$triggeredBy`, `$scanId`.

- [ ] **Step 1: Write the failing tests**

Add these two methods to `tests/Feature/Scanning/ScanLibraryJobTest.php` (inside the class; the file already imports `ScanLibrary`, `Scan`, `Work`, `ScannerContract`, `SeriesDetectorContract`, uses `RefreshDatabase` + `BuildsLibraryFixtures`):
```php
    public function test_job_updates_a_pre_created_scan_row_when_given_its_id(): void
    {
        $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg']);

        $scan = Scan::create(['status' => 'queued', 'triggered_by' => 'manual']);

        (new ScanLibrary('manual', $scan->id))->handle(
            app(\App\Scanning\LibraryScanner::class),
            app(SeriesDetectorContract::class),
        );

        $this->assertSame(1, Scan::count()); // updated the existing row, did NOT create a second
        $scan->refresh();
        $this->assertSame('completed', $scan->status);
        $this->assertNotNull($scan->started_at);
        $this->assertSame(1, $scan->stats['added']);
    }

    public function test_job_creates_a_row_when_the_given_scan_id_is_missing(): void
    {
        $this->makeDoujin('Z.A.P.', '[Z.A.P. (ズッキーニ)] 四畳半物語', ['001.jpg']);

        (new ScanLibrary('manual', 999))->handle(
            app(\App\Scanning\LibraryScanner::class),
            app(SeriesDetectorContract::class),
        );

        $scan = Scan::firstOrFail(); // fell back to creating one
        $this->assertSame('completed', $scan->status);
        $this->assertSame(1, $scan->stats['added']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ScanLibraryJobTest`
Expected: the **update-path** test (`test_job_updates_a_pre_created_scan_row_when_given_its_id`) FAILS — the current constructor takes only `$triggeredBy`, so PHP silently ignores the second arg and `handle()` creates its own row → `Scan::count()` is 2, not 1. (The fallback test `test_job_creates_a_row_when_the_given_scan_id_is_missing` documents the id-not-found branch and passes either way — a safety net, not the RED.)

- [ ] **Step 3: Implement the additive change**

In `app/Jobs/ScanLibrary.php`, replace the constructor (line 23-25) with:
```php
    public function __construct(
        public readonly string $triggeredBy = 'manual',
        public readonly ?int $scanId = null,
    ) {
    }
```
Replace the `handle()` body's row creation (the `$scan = Scan::create([...])` block, lines 29-33) with:
```php
        // Update the row created at dispatch (web "Scan now"); else create one
        // (CLI/scheduler, or if the row vanished). / 起動時に作成済みの行を更新、無ければ作成。
        $scan = $this->scanId ? Scan::find($this->scanId) : null;

        if ($scan) {
            $scan->update(['status' => 'running', 'started_at' => now()]);
        } else {
            $scan = Scan::create([
                'status' => 'running',
                'triggered_by' => $this->triggeredBy,
                'started_at' => now(),
            ]);
        }
```
(The `try { … } catch (Throwable $e) { … }` block below it is unchanged — it already operates on `$scan`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ScanLibraryJobTest`
Expected: PASS (4 tests — the 2 new + the 2 existing, which pass no id and still hit the create branch).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ScanLibrary.php tests/Feature/Scanning/ScanLibraryJobTest.php
git commit -m "$(cat <<'EOF'
feat: ScanLibrary accepts an optional scan id to update (record-at-dispatch)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `MaintenanceController`, routes, nav, view + tests

**Files:**
- Create: `app/Http/Controllers/MaintenanceController.php`, `resources/views/maintenance/index.blade.php`, `tests/Feature/Maintenance/MaintenanceTest.php`
- Modify: `routes/web.php`, `resources/views/components/nav.blade.php`

**Interfaces:**
- Consumes: `ScanLibrary::dispatch('manual', $scanId)` (Task 1); `Scan`, `Work` models; `<x-nav>`, `<x-button>`, `<x-section-heading>`, `layouts.app`.
- Produces: routes `maintenance.index` (`GET /maintenance`), `scan.store` (`POST /scan`), `maintenance.status` (`GET /maintenance/status`); the `maintenance.index` view with `x-data="maintenance(<initial>)"`; JSON `{ scan: {id,status,triggered_by,stats,started_at,finished_at,created_at} | null }`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Maintenance/MaintenanceTest.php`:
```php
<?php

namespace Tests\Feature\Maintenance;

use App\Jobs\ScanLibrary;
use App\Models\Mangaka;
use App\Models\Scan;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_maintenance_page_renders_nav_history_and_missing_works(): void
    {
        $m = Mangaka::factory()->create(['name' => 'GhostMangaka']);
        Work::factory()->for($m)->create(['title' => 'MissingWork', 'sort_title' => 'MissingWork', 'is_missing' => true]);
        Work::factory()->for($m)->create(['title' => 'PresentWork', 'sort_title' => 'PresentWork', 'is_missing' => false]);
        Scan::create(['status' => 'completed', 'triggered_by' => 'manual', 'stats' => ['added' => 2], 'started_at' => now(), 'finished_at' => now()]);

        $this->get('/maintenance')->assertOk()
            ->assertSee('href="/maintenance"', false) // nav link
            ->assertSee('MissingWork')                // server-rendered missing list
            ->assertDontSee('PresentWork')            // not-missing excluded
            ->assertSee('Missing works')              // section heading
            ->assertSee('completed');                 // latest scan embedded for Alpine
    }

    public function test_empty_states(): void
    {
        $this->get('/maintenance')->assertOk()
            ->assertSee('No scans yet')
            ->assertSee('No missing works');
    }

    public function test_scan_creates_a_queued_row_and_dispatches_with_its_id(): void
    {
        Queue::fake();

        $this->postJson('/scan')->assertStatus(202)->assertJsonPath('scan.status', 'queued');

        $scan = Scan::firstOrFail();
        $this->assertSame('queued', $scan->status);
        Queue::assertPushed(ScanLibrary::class, fn (ScanLibrary $job) => $job->scanId === $scan->id && $job->triggeredBy === 'manual');
    }

    public function test_scan_does_not_double_dispatch_when_one_is_active(): void
    {
        Queue::fake();
        $active = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

        $this->postJson('/scan')->assertStatus(202)->assertJsonPath('scan.id', $active->id);

        $this->assertSame(1, Scan::count()); // no new row
        Queue::assertNothingPushed();
    }

    public function test_status_returns_latest_scan_or_null(): void
    {
        $this->getJson('/maintenance/status')->assertOk()->assertJsonPath('scan', null);

        Scan::create(['status' => 'completed', 'triggered_by' => 'manual', 'stats' => ['added' => 3], 'started_at' => now(), 'finished_at' => now()]);

        $this->getJson('/maintenance/status')->assertOk()
            ->assertJsonPath('scan.status', 'completed')
            ->assertJsonPath('scan.stats.added', 3);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=MaintenanceTest`
Expected: FAIL — routes `/maintenance`, `/scan`, `/maintenance/status` not defined (404).

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/MaintenanceController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Jobs\ScanLibrary;
use App\Models\Scan;
use App\Models\Work;

/** Library maintenance: scan trigger + status/history + missing works (F3b). / ライブラリ保守。 */
final class MaintenanceController extends Controller
{
    private const HISTORY_LIMIT = 20;

    public function index()
    {
        $missing = Work::query()
            ->where('is_missing', true)
            ->with('mangaka')
            ->orderBy('mangaka_id')
            ->orderBy('sort_title')
            ->paginate(50);

        return view('maintenance.index', [
            'latest' => $this->serialize(Scan::latest()->first()),
            'history' => Scan::latest()->limit(self::HISTORY_LIMIT)->get()
                ->map(fn (Scan $s) => $this->serialize($s))->all(),
            'missing' => $missing,
            'missingCount' => Work::where('is_missing', true)->count(),
        ]);
    }

    public function scan()
    {
        // No second scan while one is queued/running. / 二重起動防止。
        $active = Scan::whereIn('status', ['queued', 'running'])->latest()->first();
        if ($active) {
            return response()->json(['scan' => $this->serialize($active)], 202);
        }

        $scan = Scan::create(['status' => 'queued', 'triggered_by' => 'manual']);
        ScanLibrary::dispatch('manual', $scan->id);

        return response()->json(['scan' => $this->serialize($scan)], 202);
    }

    public function status()
    {
        return response()->json(['scan' => $this->serialize(Scan::latest()->first())]);
    }

    /** @return array<string,mixed>|null */
    private function serialize(?Scan $scan): ?array
    {
        if ($scan === null) {
            return null;
        }

        return [
            'id' => $scan->id,
            'status' => $scan->status,
            'triggered_by' => $scan->triggered_by,
            'stats' => $scan->stats,
            'started_at' => $scan->started_at?->toIso8601String(),
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'created_at' => $scan->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add the import (in the `use App\Http\Controllers\…;` block):
```php
use App\Http\Controllers\MaintenanceController;
```
Add the routes (after the `browse.index` route):
```php
Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
Route::get('/maintenance/status', [MaintenanceController::class, 'status'])->name('maintenance.status');
Route::post('/scan', [MaintenanceController::class, 'scan'])->name('scan.store');
```

- [ ] **Step 5: Add the Maintenance nav link**

In `resources/views/components/nav.blade.php`, after the Browse link, add:
```blade
        <a href="/maintenance" class="no-underline {{ $active === 'maintenance' ? '[color:var(--color-on-dark)]' : '[color:var(--color-body-muted)]' }} hover:[color:var(--color-on-dark)]" style="font:var(--type-nav);">Maintenance</a>
```

- [ ] **Step 6: Create the maintenance view (panel + history Alpine, missing server-rendered)**

`resources/views/maintenance/index.blade.php`:
```blade
@extends('layouts.app')

@php
    $initial = ['latest' => $latest, 'history' => $history];
@endphp

@section('content')
    <x-nav active="maintenance" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">

        {{-- Scan panel + history (Alpine, live) --}}
        <div x-data="maintenance(@js($initial))">
            <x-section-heading>Library</x-section-heading>

            <div class="flex items-center" style="gap:var(--space-md); margin-bottom:var(--space-xl); flex-wrap:wrap;">
                <x-button type="button" x-on:click="scan()" x-bind:disabled="scanning || busy"
                          x-bind:style="(scanning || busy) ? 'opacity:0.5; pointer-events:none;' : ''">▶ Scan now</x-button>
                <span style="font:var(--type-caption); color:var(--text-muted);" x-text="panelText()"></span>
            </div>

            <x-section-heading>Recent scans</x-section-heading>
            <div style="margin-bottom:var(--space-xxl);">
                <template x-if="history.length === 0">
                    <p style="font:var(--type-body); color:var(--text-muted);">No scans yet — run one.</p>
                </template>
                <template x-for="s in history" x-bind:key="s.id">
                    <div class="flex items-center" style="gap:var(--space-md); padding:var(--space-sm) 0; border-bottom:1px solid var(--color-hairline); flex-wrap:wrap;">
                        <span style="font:var(--type-caption-strong);" x-bind:style="s.status === 'failed' ? 'color:var(--color-error);' : 'color:var(--text-heading);'" x-text="s.status"></span>
                        <span style="font:var(--type-fine); color:var(--text-muted);" x-text="s.triggered_by"></span>
                        <span style="font:var(--type-fine); color:var(--text-muted);" x-text="when(s.started_at)"></span>
                        <span style="font:var(--type-fine); color:var(--text-muted);" x-text="duration(s)"></span>
                        <span style="flex:1; font:var(--type-fine); color:var(--text-muted);" x-text="summary(s)"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Missing works (server-rendered) --}}
        <x-section-heading>Missing works ({{ $missingCount }})</x-section-heading>
        @if ($missing->isEmpty())
            <p style="font:var(--type-body); color:var(--text-muted);">No missing works.</p>
        @else
            <p style="font:var(--type-fine); color:var(--text-muted); margin-bottom:var(--space-md);">These reappear automatically on the next scan when their files return.</p>
            <div>
                @foreach ($missing as $work)
                    <a href="/work/{{ $work->id }}" class="no-underline flex items-center" style="gap:var(--space-md); padding:var(--space-sm) 0; border-bottom:1px solid var(--color-hairline);">
                        <span class="truncate" style="flex:1; font:var(--type-caption-strong); color:var(--text-heading);">{{ $work->title }}</span>
                        <span style="font:var(--type-fine); color:var(--text-muted);">{{ $work->mangaka?->name }}</span>
                    </a>
                @endforeach
            </div>
            @if ($missing->hasPages())
                <nav class="flex items-center justify-center" style="gap:var(--space-md); margin-top:var(--space-xl);">
                    @if ($missing->onFirstPage())
                        <span style="font:var(--type-caption); color:var(--text-muted);">Prev</span>
                    @else
                        <a href="{{ $missing->previousPageUrl() }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">Prev</a>
                    @endif
                    <span style="font:var(--type-caption); color:var(--text-muted);">Page {{ $missing->currentPage() }} of {{ $missing->lastPage() }}</span>
                    @if ($missing->hasMorePages())
                        <a href="{{ $missing->nextPageUrl() }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">Next</a>
                    @else
                        <span style="font:var(--type-caption); color:var(--text-muted);">Next</span>
                    @endif
                </nav>
            @endif
        @endif
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('maintenance', (initial) => ({
            latest: initial.latest ?? null,
            history: initial.history ?? [],
            busy: false,
            _poll: null,

            init() {
                if (this.isActive(this.latest)) this.startPolling();
            },
            isActive(s) { return !!s && (s.status === 'queued' || s.status === 'running'); },
            get scanning() { return this.isActive(this.latest); },

            async scan() {
                if (this.scanning || this.busy) return;
                this.busy = true;
                try {
                    const res = await fetch('/scan', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                        },
                    });
                    const data = await res.json();
                    this.latest = data.scan;
                    this.startPolling();
                } catch (e) { /* best-effort */ }
                finally { this.busy = false; }
            },
            startPolling() {
                clearInterval(this._poll);
                this._poll = setInterval(() => this.tick(), 2000);
            },
            async tick() {
                try {
                    const res = await fetch('/maintenance/status', { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    this.latest = data.scan;
                    if (this.latest && !this.isActive(this.latest)) {
                        clearInterval(this._poll);
                        if (!this.history.some((s) => s.id === this.latest.id)) {
                            this.history.unshift(this.latest);
                        }
                    }
                } catch (e) { /* best-effort; retry next tick */ }
            },

            panelText() {
                const s = this.latest;
                if (!s) return 'Ready.';
                if (s.status === 'queued') return 'Queued…';
                if (s.status === 'running') return 'Running…';
                if (s.status === 'failed') return 'Failed: ' + ((s.stats && s.stats.error) || 'unknown error');
                return 'Completed — ' + this.summary(s);
            },
            summary(s) {
                const st = s.stats || {};
                if (s.status === 'failed') return (st.error || 'failed');
                return ['added ' + (st.added ?? 0), 'updated ' + (st.updated ?? 0),
                        'missing ' + (st.missing ?? 0), 'series +' + (st.series_created ?? 0)].join(' · ');
            },
            duration(s) {
                if (!s.started_at || !s.finished_at) return '';
                return Math.max(0, Math.round((new Date(s.finished_at) - new Date(s.started_at)) / 1000)) + 's';
            },
            when(iso) { return iso ? new Date(iso).toLocaleString() : ''; },
        }));
    });
    </script>
@endsection
```

- [ ] **Step 7: Run tests**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=MaintenanceTest` → PASS (5 tests).
Then the full suite: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test` → all green.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/MaintenanceController.php resources/views/maintenance/index.blade.php routes/web.php resources/views/components/nav.blade.php tests/Feature/Maintenance/MaintenanceTest.php
git commit -m "$(cat <<'EOF'
feat: /maintenance — scan trigger, live status/history, missing works

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Asset build + full-suite gate + browser render-verify gate

**Files:** none (verification only; file any defect as a fix before merge).

- [ ] **Step 1: Build the frontend assets**

Run: `npm run build`
Expected: Vite compiles with no errors. Confirm tokens still bundle:
```bash
f=$(/bin/ls public/build/assets/app-*.css); grep -oc -- '--color-primary' "$f"; grep -oc -- '--color-error' "$f"
```
Expected: both > 0.

- [ ] **Step 2: Run the full test suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: ALL green (incl. ScanLibraryJobTest 4 + MaintenanceTest 5). Output pristine.

- [ ] **Step 3: Browser render-verify gate**

The scan runs on the **database queue**, so a worker must process it. Stand up:
1. A writable library with 1–2 real zips + a couple of `is_missing=true` works seeded directly in the dev DB (for the missing list). Reuse the F2/F3a local-gate approach (env-injecting `php -S` router pointing `LIBRARY_PATH`/`DATA_PATH` at scratch dirs; see the `wydoujin-local-browser-gate` memory).
2. A queue worker: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan queue:work --stop-when-empty` (run alongside, or `--once` per dispatch) so dispatched scans actually run.

Open `/maintenance` and verify, with **no console errors**, in both light and dark themes:
- Page renders: "Scan now" button, "Recent scans" (history or empty state), "Missing works (N)" with the seeded missing works listed (title + mangaka, linking to `/work/{id}`).
- **Scan now:** click → panel shows `Queued…`/`Running…`, button disables; the worker processes the job; polling flips the panel to `Completed — added … · …`; a new row appears at the top of "Recent scans"; the button re-enables.
- **No-double-dispatch:** while a scan is active, the button is disabled (and a second `POST /scan` would return the active scan — already covered by PHPUnit).
- **Failed scan** (optional): if a scan fails, the panel shows the error in `--color-error` and a `failed` history row is red.
- Missing-works pager works if > 50 missing.

- [ ] **Step 4: Commit (only if the build emitted tracked changes)**

`public/build` is git-ignored, so normally nothing to commit. If `git status --porcelain` shows tracked changes, commit them with the trailer.

---

## Self-Review

**Spec coverage (F3b design doc):**
- `/maintenance` surface + nav link → Task 2 (controller `index`, nav link, view).
- "Scan now" + record-at-dispatch + guard → Task 1 (`ScanLibrary` scanId) + Task 2 (`scan()`: queued row + `dispatch('manual', $scan->id)` + `whereIn(['queued','running'])` guard); tests `test_scan_creates_a_queued_row_and_dispatches_with_its_id`, `test_scan_does_not_double_dispatch_when_one_is_active`.
- Live status polling (queued→running→completed/failed + stats) → Task 2 Alpine `maintenance` (`startPolling`/`tick`/`panelText`); browser-verified (Task 3).
- Scan history (Alpine, embedded JSON) → Task 2 view + `index` `history`; status colors (failed=`--color-error`) → view `x-bind:style`.
- Missing works server-rendered + pager, informational → Task 2 view + `index` `missing`; tests `test_maintenance_page_renders_…`.
- `GET /maintenance/status` latest scan → Task 2 `status`; test `test_status_returns_latest_scan_or_null`.
- Empty states → view + `test_empty_states`.
- Back-compat (CLI/scheduler/existing tests unchanged) → Task 1 keeps `triggeredBy` first + create-branch for no-id; existing `ScanLibraryJobTest` tests untouched + still pass (Step 4).
- Tokens-only + one-accent → view + Global Constraints; build regression → Task 3.

**Placeholder scan:** none — every step has complete code + exact commands/expected output.

**Type consistency:** `ScanLibrary(string $triggeredBy='manual', ?int $scanId=null)` — `dispatch('manual', $scan->id)` (Task 2) matches; `$job->scanId`/`$job->triggeredBy` asserted in the test. Scan JSON keys `{id,status,triggered_by,stats,started_at,finished_at,created_at}` are produced by `serialize()` and consumed by the Alpine component (`s.status`, `s.stats`, `s.started_at`, `s.finished_at`) + the tests (`scan.status`, `scan.stats.added`, `scan.id`). Route names `maintenance.index`/`maintenance.status`/`scan.store`; literal paths `/maintenance`, `/maintenance/status`, `/scan` used in the view fetches + tests. `$missing` is a paginator (pager methods used). `whereIn(['queued','running'])` guard matches the `isActive` client check.

**Interactive verification:** the live Alpine behaviors (poll, panel transitions, history prepend, button disable) are browser-only → Task 3 gate, not PHPUnit (consistent with F2/F3a). The gate requires a running `queue:work` since the queue is async.

**Out of scope (later):** F3c (series merge/split/rename); cancel/retry; delete missing works; WebSockets/SSE; per-scan detail page.
