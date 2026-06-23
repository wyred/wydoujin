# wydoujin — Reader (F2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the immersive single-page reader (spec §9 JS): a full-viewport Alpine reader launched from the work-detail "Read" CTA that swaps the page image in place, navigates by keys + click zones with RTL/LTR direction, preloads ahead, toggles fit-height/width + direction (persisted), auto-hides its chrome, and debounce-saves reading progress.

**Architecture:** A thin `ReaderController@show` resolves the resume page and renders `resources/views/reader/show.blade.php` — an `@extends('layouts.app')` view that draws NO browse nav, just a fixed full-viewport Alpine reader on a dark backdrop. The Alpine component (registered via `alpine:init` → `Alpine.data('reader', …)`) holds page/direction/fit/chrome state and drives the existing `GET /work/{work}/page/{n}` (image) and `POST /work/{work}/progress` (debounced save) endpoints from Plan 6. The work-detail CTA repoints to `/work/{id}/read`.

**Tech Stack:** Laravel 13 Blade + Alpine.js + Tailwind v4; design tokens. No new dependencies. Reuses `layouts.app`, the page/progress routes, and the `Work`/`ReadingProgress` models.

**Spec:** `docs/superpowers/specs/2026-06-23-wydoujin-reader-design.md`. Parent: `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §9.

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+ (local dev 8.5). No `declare(strict_types=1)`.
- **Broken local toolchain:** prefix EVERY php command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5.4). Env doesn't persist between Bash calls — repeat it. Run tests via `php artisan test`. Node/npm are on the normal PATH.
- **Avoid `cd` in compound bash;** use absolute paths / `git -C`.
- **Commit trailer:** every commit ends with a blank line then exactly:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **Design tokens are mandatory (§13).** Never inline a raw hex/size — reference a token (semantic aliases: `--surface-card`, `--text-body`, `--text-muted`, `--color-primary`, `--color-on-primary`, `--color-hairline`, `--color-on-dark`, `--color-black`, `var(--type-*)`, `var(--radius-*)`, `var(--space-*)`). The ONE allowed new raw color is the app-specific `--reader-scrim` token added to `app.css` (the design system has no translucent-dark token). Tailwind plain utilities only for structural layout.
- **React-free:** the reader is Blade + Alpine. Register the component via `document.addEventListener('alpine:init', () => Alpine.data('reader', …))` so it's defined before `Alpine.start()` (called in `resources/js/app.js`).
- **Reader is always-dark + immersive:** backdrop is `var(--color-black)` regardless of the site light/dark theme; it renders no `<x-nav>`.
- **Views link via literal paths** (`/work/{id}`, `/work/{id}/page/{n}`, `/work/{id}/progress`, `/work/{id}/read`); routes are still named.
- **DB portability:** Eloquent only. Feature tests use `RefreshDatabase` on in-memory SQLite + `$this->withoutVite()`.
- **Auth gate is global** (`RequirePassword` on the `web` group) — `/work/{work}/read` is auto-gated; no per-route auth.
- **Workflow:** TDD, DRY, YAGNI, bite-sized commits.

## Scope Decisions (locked, per spec)

1. **F2 = the reader only.** F3 (search, filters, scan/maintenance, manual series merge) is a later sub-project.
2. **Navigation maps physical input → reading order.** Page numbers are sequential; "next" = page+1. Inputs: left third / `←` = go-left; right third / `→` = go-right; center third = toggle chrome. RTL (default): go-left = next, go-right = prev. LTR: reversed. Bounds-clamped.
3. **Resume:** open at saved `current_page` when in-progress (`current_page > 0`, not completed), else page 1; `?page=N` overrides (clamped 1..page_count, min 1).
4. **Direction + fit persist** in `localStorage['wyd-reader-dir']` (`rtl`|`ltr`, default rtl) and `['wyd-reader-fit']` (`height`|`width`, default height).
5. **Progress:** debounced (800ms) `POST /work/{id}/progress {current_page}` with the CSRF token (added as a `<meta>` in `layouts.app`); failures are swallowed (best-effort). Backend marks completed at the last page (Plan 6).
6. **CTA:** work-detail "Read" → `/work/{id}/read`; label **Read** / **Continue** / **Read again** by progress.
7. **Interactive behaviors are verified in the controller render+drive gate, not PHPUnit** (browser-only). PHPUnit covers the route/resume/wiring.

**Out of scope (later):** continuous/long-strip mode, double-page spreads, zoom/pan, thumbnail grid, next-in-series at end.

## File Structure

- `app/Http/Controllers/ReaderController.php` — **create**. `show(Request, Work)` → resume logic → `reader.show`.
- `resources/views/reader/show.blade.php` — **create**. The immersive Alpine reader + its `<script>`.
- `routes/web.php` — **modify**. Add `GET /work/{work}/read` (name `work.read`).
- `resources/css/app.css` — **modify**. Add `--reader-scrim` to the existing `:root` override block.
- `resources/views/layouts/app.blade.php` — **modify**. Add the `<meta name="csrf-token">` to `<head>`.
- `resources/views/work/show.blade.php` — **modify**. Repoint the Read CTA to `/read` + dynamic label.
- `tests/Feature/Reader/ReaderViewTest.php` — **create**. Route/resume/wiring smoke tests.
- `tests/Feature/Browse/SeriesAndWorkTest.php` — **modify**. Update the work-detail CTA assertion (`/page/1` → `/read`, label `Continue`).

**Reference — existing shapes (verbatim):**
- `App\Models\Work` (`$guarded=[]`): `id`, `title`, `page_count` (int cast); `readingProgress()` HasOne. `App\Models\ReadingProgress`: `current_page` (int), `is_completed` (bool). Factories: `Work::factory()`, `Mangaka::factory()`, `ReadingProgress::create([...])`.
- Endpoints (Plan 6): `GET /work/{work}/page/{n}` name `work.page` (streams the n-th page, 1-based); `POST /work/{work}/progress` name `work.progress` (`current_page` required|integer|min:1|max:page_count; returns JSON; sets `is_completed` when `current_page >= page_count`).
- `layouts.app`: `<html>` with a pre-paint theme `<head>` script + `@vite([...])`; `<body … style="background:var(--surface-page);…">@yield('content')</body>`. It renders no nav (pages add `<x-nav>` themselves; the reader adds none).
- `resources/js/app.js`: `import Alpine from 'alpinejs'; window.Alpine = Alpine; Alpine.start();`.
- `resources/css/app.css` `:root` block currently defines `--font-display`, `--font-text`, `--color-error`.
- `work/show.blade.php` Read CTA (current): `<x-button href="/work/{{ $work->id }}/page/1">▶ Read</x-button>`.

---

## Task 1: The immersive reader (route, controller, view, CTA, tests)

**Files:**
- Create: `app/Http/Controllers/ReaderController.php`, `resources/views/reader/show.blade.php`, `tests/Feature/Reader/ReaderViewTest.php`
- Modify: `routes/web.php`, `resources/css/app.css`, `resources/views/layouts/app.blade.php`, `resources/views/work/show.blade.php`, `tests/Feature/Browse/SeriesAndWorkTest.php`

**Interfaces:**
- Consumes: `Work` (`id`, `title`, `page_count`, `readingProgress`); the `work.page` + `work.progress` routes; `layouts.app`.
- Produces: route `work.read` → `ReaderController::show(Request, Work): View`; the `reader.show` view exposing `x-data="reader(id, pages, initialPage)"`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Reader/ReaderViewTest.php`:
```php
<?php

namespace Tests\Feature\Reader;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReaderViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function work(int $pages = 24, array $overrides = []): Work
    {
        return Work::factory()->for(Mangaka::factory())->create(array_merge([
            'title' => '四畳半物語', 'page_count' => $pages,
        ], $overrides));
    }

    public function test_reader_renders_with_page_data_and_back_link(): void
    {
        $work = $this->work(24);

        $this->get("/work/{$work->id}/read")->assertOk()
            ->assertSee("reader({$work->id}, 24, 1)", false)   // Alpine init, resume page 1
            ->assertSee('四畳半物語')
            ->assertSee('href="/work/'.$work->id.'"', false);   // back to detail
    }

    public function test_resumes_at_saved_page(): void
    {
        $work = $this->work(24);
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 5]);

        $this->get("/work/{$work->id}/read")->assertOk()
            ->assertSee("reader({$work->id}, 24, 5)", false);
    }

    public function test_completed_work_starts_at_page_one(): void
    {
        $work = $this->work(24);
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 24, 'is_completed' => true]);

        $this->get("/work/{$work->id}/read")->assertOk()
            ->assertSee("reader({$work->id}, 24, 1)", false);
    }

    public function test_page_query_overrides_and_clamps(): void
    {
        $work = $this->work(24);

        $this->get("/work/{$work->id}/read?page=10")->assertSee("reader({$work->id}, 24, 10)", false);
        $this->get("/work/{$work->id}/read?page=999")->assertSee("reader({$work->id}, 24, 24)", false);
        $this->get("/work/{$work->id}/read?page=0")->assertSee("reader({$work->id}, 24, 1)", false);
    }

    public function test_zero_page_work_shows_no_pages(): void
    {
        $work = $this->work(0);

        $this->get("/work/{$work->id}/read")->assertOk()->assertSee('No pages');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ReaderViewTest`
Expected: FAIL — route `/work/{work}/read` not defined (404).

- [ ] **Step 3: Add the scrim token + CSRF meta**

In `resources/css/app.css`, add `--reader-scrim` to the existing `:root { … }` override block (alongside `--color-error`):
```css
    /* Reader chrome scrim — translucent dark (the design system has no such token). */
    --reader-scrim: rgba(0, 0, 0, 0.55);
```

In `resources/views/layouts/app.blade.php`, add inside `<head>` (after the `<title>` line):
```blade
    <meta name="csrf-token" content="{{ csrf_token() }}">
```

- [ ] **Step 4: Create `ReaderController`**

`app/Http/Controllers/ReaderController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Work;
use Illuminate\Http\Request;

/** Immersive single-page reader (spec §9). / 没入型ビューア。 */
final class ReaderController extends Controller
{
    public function show(Request $request, Work $work)
    {
        $pages = (int) $work->page_count;

        // Resume at the saved page when in-progress; else page 1. / 続きから再開。
        $progress = $work->readingProgress;
        $resume = ($progress && $progress->current_page > 0 && ! $progress->is_completed)
            ? (int) $progress->current_page
            : 1;

        // ?page=N overrides; clamp to 1..pages (min 1 even for a 0-page work). / 範囲内に丸める。
        $initialPage = max(1, min((int) $request->query('page', $resume), max(1, $pages)));

        return view('reader.show', ['work' => $work, 'pages' => $pages, 'initialPage' => $initialPage]);
    }
}
```

- [ ] **Step 5: Create the reader view**

`resources/views/reader/show.blade.php`:
```blade
@extends('layouts.app')

@section('content')
@if ($pages < 1)
    <div class="fixed inset-0 flex flex-col items-center justify-center" style="background:var(--color-black); color:var(--color-on-dark); gap:var(--space-md);">
        <p style="font:var(--type-body);">No pages.</p>
        <a href="/work/{{ $work->id }}" class="no-underline" style="color:var(--color-on-dark); font:var(--type-caption);">← Back</a>
    </div>
@else
<div
    x-data="reader({{ $work->id }}, {{ $pages }}, {{ $initialPage }})"
    @keydown.window.arrow-left.prevent="goLeft()"
    @keydown.window.arrow-right.prevent="goRight()"
    @mousemove="showChrome()"
    class="fixed inset-0 overflow-hidden select-none"
    :class="chrome ? '' : 'cursor-none'"
    style="background:var(--color-black);"
>
    {{-- Page image --}}
    <div class="absolute inset-0 flex justify-center" :class="fit === 'width' ? 'overflow-y-auto items-start' : 'items-center'">
        <img :src="pageUrl(page)" :alt="'page ' + page" draggable="false"
             :class="fit === 'width' ? 'w-full h-auto' : 'max-h-screen max-w-full object-contain'">
    </div>

    {{-- Click/tap zones (above image, below chrome) --}}
    <button type="button" class="absolute inset-y-0 left-0 w-1/3 z-10" style="background:none;border:none;" @click="goLeft()" :aria-label="dir === 'rtl' ? 'Next page' : 'Previous page'"></button>
    <button type="button" class="absolute inset-y-0 left-1/3 w-1/3 z-10" style="background:none;border:none;" @click="toggleChrome()" aria-label="Toggle controls"></button>
    <button type="button" class="absolute inset-y-0 right-0 w-1/3 z-10" style="background:none;border:none;" @click="goRight()" :aria-label="dir === 'rtl' ? 'Previous page' : 'Next page'"></button>

    {{-- Top chrome --}}
    <div x-show="chrome" x-transition.opacity class="absolute top-0 inset-x-0 z-20 flex items-center"
         style="gap:var(--space-md); padding:var(--space-sm) var(--space-md); color:var(--color-on-dark); background:var(--reader-scrim);">
        <a href="/work/{{ $work->id }}" class="no-underline shrink-0" style="color:var(--color-on-dark); font:var(--type-body);" aria-label="Back">←</a>
        <span class="truncate" style="flex:1; font:var(--type-caption-strong);">{{ $work->title }}</span>
        <span class="shrink-0" style="font:var(--type-caption);" x-text="page + ' / ' + pages"></span>
        <button type="button" class="shrink-0" @click="settings = !settings" aria-label="Reader settings"
                style="background:none;border:none;cursor:pointer;color:var(--color-on-dark);font-size:18px;line-height:1;">⚙</button>
    </div>

    {{-- Settings popover --}}
    <div x-show="settings" x-transition @click.outside="settings = false" class="absolute z-30"
         style="top:44px; right:var(--space-md); min-width:180px; display:flex; flex-direction:column; gap:var(--space-sm); padding:var(--space-md); background:var(--surface-card); color:var(--text-body); border:1px solid var(--color-hairline); border-radius:var(--radius-md);">
        <div style="font:var(--type-fine); color:var(--text-muted);">Direction</div>
        <div class="flex" style="gap:var(--space-xs);">
            <button type="button" @click="setDir('rtl')" :style="dir === 'rtl' ? activeChip : chip">RTL</button>
            <button type="button" @click="setDir('ltr')" :style="dir === 'ltr' ? activeChip : chip">LTR</button>
        </div>
        <div style="font:var(--type-fine); color:var(--text-muted); margin-top:var(--space-xs);">Fit</div>
        <div class="flex" style="gap:var(--space-xs);">
            <button type="button" @click="setFit('height')" :style="fit === 'height' ? activeChip : chip">Height</button>
            <button type="button" @click="setFit('width')" :style="fit === 'width' ? activeChip : chip">Width</button>
        </div>
    </div>

    {{-- Bottom page slider --}}
    <div x-show="chrome" x-transition.opacity class="absolute bottom-0 inset-x-0 z-20 flex items-center"
         style="gap:var(--space-md); padding:var(--space-sm) var(--space-lg); background:var(--reader-scrim);">
        <input type="range" min="1" :max="pages" x-model.number="page" class="w-full" :dir="dir === 'rtl' ? 'rtl' : 'ltr'" aria-label="Jump to page">
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('reader', (id, pages, initial) => ({
        id, pages, page: initial,
        dir: localStorage.getItem('wyd-reader-dir') || 'rtl',
        fit: localStorage.getItem('wyd-reader-fit') || 'height',
        chrome: true,
        settings: false,
        _idle: null,
        _save: null,
        chip: 'flex:1;cursor:pointer;padding:6px 10px;border-radius:var(--radius-sm);border:1px solid var(--color-hairline);background:var(--surface-page);color:var(--text-body);font:var(--type-caption);',
        activeChip: 'flex:1;cursor:pointer;padding:6px 10px;border-radius:var(--radius-sm);border:1px solid var(--color-primary);background:var(--color-primary);color:var(--color-on-primary);font:var(--type-caption);',

        init() {
            this.$watch('page', () => { this.preload(); this.saveProgress(); });
            this.preload();
            this.armIdle();
        },
        pageUrl(n) { return '/work/' + this.id + '/page/' + n; },
        next() { if (this.page < this.pages) this.page++; },
        prev() { if (this.page > 1) this.page--; },
        goLeft() { this.dir === 'rtl' ? this.next() : this.prev(); this.showChrome(); },
        goRight() { this.dir === 'rtl' ? this.prev() : this.next(); this.showChrome(); },
        preload() {
            [this.page + 1, this.page + 2].forEach((n) => {
                if (n <= this.pages) { const img = new Image(); img.src = this.pageUrl(n); }
            });
        },
        saveProgress() {
            clearTimeout(this._save);
            this._save = setTimeout(() => {
                fetch('/work/' + this.id + '/progress', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    body: JSON.stringify({ current_page: this.page }),
                }).catch(() => {});
            }, 800);
        },
        setDir(d) { this.dir = d; localStorage.setItem('wyd-reader-dir', d); },
        setFit(f) { this.fit = f; localStorage.setItem('wyd-reader-fit', f); },
        showChrome() { this.chrome = true; this.armIdle(); },
        toggleChrome() { this.chrome ? (this.chrome = false) : this.showChrome(); },
        armIdle() { clearTimeout(this._idle); this._idle = setTimeout(() => { this.chrome = false; this.settings = false; }, 2500); },
    }));
});
</script>
@endif
@endsection
```

- [ ] **Step 6: Register the route**

In `routes/web.php`, add the import after `use App\Http\Controllers\ReadingProgressController;`:
```php
use App\Http\Controllers\ReaderController;
```
Append at the end of the file:
```php
Route::get('/work/{work}/read', [ReaderController::class, 'show'])->name('work.read');
```

- [ ] **Step 7: Repoint the work-detail CTA + dynamic label**

In `resources/views/work/show.blade.php`, replace the CTA block:
```blade
                <div style="margin-top:var(--space-lg);">
                    <x-button href="/work/{{ $work->id }}/page/1">▶ Read</x-button>
                </div>
```
with:
```blade
                @php
                    $rp = $work->readingProgress;
                    $cta = (! $rp || $rp->current_page < 1) ? 'Read' : ($rp->is_completed ? 'Read again' : 'Continue');
                @endphp
                <div style="margin-top:var(--space-lg);">
                    <x-button href="/work/{{ $work->id }}/read">▶ {{ $cta }}</x-button>
                </div>
```

- [ ] **Step 8: Update the F1 work-detail CTA assertion**

In `tests/Feature/Browse/SeriesAndWorkTest.php`, the `test_work_detail_shows_metadata_badges_progress_and_read_cta` fixture creates `ReadingProgress … 'current_page' => 3` (in-progress), so the CTA is now "Continue" → `/work/{id}/read`. Replace the line:
```php
            ->assertSee('href="/work/'.$work->id.'/page/1"', false); // Read CTA → reader seam
```
with:
```php
            ->assertSee('href="/work/'.$work->id.'/read"', false)   // Read CTA → reader
            ->assertSee('Continue');                                // dynamic label (in-progress)
```

- [ ] **Step 9: Run tests**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ReaderViewTest` → PASS (5 tests).
Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesAndWorkTest` → PASS (2 tests, updated CTA).
Then the full suite: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test` → all green.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/ReaderController.php resources/views/reader/show.blade.php routes/web.php resources/css/app.css resources/views/layouts/app.blade.php resources/views/work/show.blade.php tests/Feature/Reader/ReaderViewTest.php tests/Feature/Browse/SeriesAndWorkTest.php
git commit -m "$(cat <<'EOF'
feat: immersive Alpine reader (GET /work/{work}/read)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Asset build + full-suite gate

**Files:** none (verification only).

- [ ] **Step 1: Build the frontend assets**

Run: `npm run build`
Expected: Vite compiles with no errors. Then confirm the design tokens are still bundled (regression check for the Plan-7 token-bundling fix) and the scrim token is present:
```bash
f=$(/bin/ls public/build/assets/app-*.css); grep -oc -- '--color-primary' "$f"; grep -oc -- '--reader-scrim' "$f"
```
Expected: both > 0.

- [ ] **Step 2: Run the full test suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: ALL suites green (archive, parsing, scanning, series, reader-backend, browse, reader-view, auth). Output pristine.

- [ ] **Step 3: Commit (only if the build emitted tracked changes)**

`public/build` is git-ignored, so normally nothing to commit. If `git status --porcelain` is non-empty for tracked files, commit them with the trailer.

> **Visual + interaction gate (controller, post-implementation):** after the tasks pass, seed a multi-page work + cover/page fixtures, `php artisan serve`, open `/work/{id}/read`, and drive it in the browser: `←`/`→` and the left/right click zones change the page (and RTL vs LTR flips which side advances); center-tap + idle toggles/hides the chrome; the ⚙ settings popover switches direction + fit (fit-width scrolls); the page slider jumps; and a page change fires the debounced `POST .../progress` (confirm the `reading_progress` row updates). Verify in both the site's light and dark theme (the reader backdrop stays dark either way). File any defect as a fix-subagent task before merge.

---

## Self-Review

**Spec coverage (F2 design doc):**
- Immersive full-screen reader, no nav, dark backdrop → Task 1 view (`fixed inset-0`, `var(--color-black)`, no `<x-nav>`).
- In-place `<img>` swap; `←`/`→` + L/R/center zones; RTL(default)/LTR mapping; preload 1–2; debounced progress; fit-height/width toggle; direction toggle; auto-hiding chrome; page slider → Task 1 Alpine component.
- Resume + `?page` clamp; dynamic CTA label → `ReaderController` + `work/show.blade.php`; `ReaderViewTest`.
- Persisted `dir`/`fit` (`localStorage`); CSRF via `<meta>` → Task 1.
- 0-page guard → Task 1 view `@if ($pages < 1)`; `test_zero_page_work_shows_no_pages`.
- Tokens-only + the one `--reader-scrim` token → Task 1; build-time regression check → Task 2.
- Interactive behaviors → controller render+drive gate (Task 2 note) — browser-only, not PHPUnit.

**Placeholder scan:** none — every step has complete code + exact commands/expected output.

**Type consistency:** `reader(id, pages, initialPage)` matches the controller's `['work','pages','initialPage']` and the test assertions `reader({id}, 24, N)`. Route name `work.read`; literal `/work/{id}/read` used in the CTA + tests. `--reader-scrim` defined (app.css) and used (reader view). The progress POST shape (`{current_page}`) matches the Plan-6 endpoint. `goLeft/goRight/next/prev/preload/saveProgress/setDir/setFit/showChrome/toggleChrome/armIdle` are all defined in the one component.

**Cross-task note:** Task 1 updates `SeriesAndWorkTest` (the F1 CTA assertion) because the CTA target changed `/page/1` → `/read`; without it the full suite would fail in Step 9.

**Out of scope (later):** long-strip/continuous, double-page, zoom/pan, thumbnails, next-in-series; F3 surfaces.
