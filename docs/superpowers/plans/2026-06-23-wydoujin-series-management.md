# wydoujin — Manual Series Management (F3c) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the user manually merge/split/move works between series and rename a series, all per-mangaka, via a "Manage" mode on the mangaka page — every op locking the decision so auto-detection never undoes it, and writing only DB rows (never the read-only `/library`).

**Architecture:** A `SeriesManagementController` exposes four POST endpoints (group / add / ungroup / rename) that mutate `series` rows + `works.series_id`/`series_locked`, set `is_auto=false` on touched series, clean now-empty auto series, and validate per-mangaka. The mangaka page gains an Alpine "Manage" mode (flat checkable work list + a sticky action bar); the series page gains an inline rename. After each action the page reloads.

**Tech Stack:** Laravel 13 Blade + Alpine.js + Tailwind v4; design tokens. No new dependencies, no schema changes.

**Spec:** `docs/superpowers/specs/2026-06-23-wydoujin-series-management-design.md`. Parent: `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §8, §10.

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+ (local dev 8.5). No `declare(strict_types=1)`.
- **Broken local toolchain:** prefix EVERY php command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5.4). Env doesn't persist between Bash calls — repeat it. Tests via `php artisan test`. Node/npm on the normal PATH.
- **Avoid `cd` in compound bash;** use absolute paths / `git -C`.
- **Commit trailer:** every commit ends with a blank line then exactly:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **PHP style:** single quotes unless interpolation; inline typed properties; short **bilingual (EN / JP)** doc comments on new classes/methods.
- **THE LOCK CONTRACT (mandatory):** every op sets `series_locked = true` on affected works; any series that gains manual works or is renamed gets `is_auto = false`; now-empty `is_auto` series are deleted. This is exactly what `SeriesDetector::detect()` needs to preserve manual decisions (it clusters only `series_locked=false` works, clears `series_id` only on non-locked works, creates/deletes only `is_auto=true` series). The tests MUST assert a `detect()` after each op leaves the decision intact.
- **Read-only library, DB-only:** never write to `/library` or the filesystem — all changes are DB rows.
- **Per-mangaka:** every op validates all `work_ids` share one `mangaka_id`; `add`/`rename` series must be that same mangaka (422 otherwise).
- **Design tokens mandatory (§13):** no raw hex/size — reference tokens. **One blue accent:** checkboxes + primary actions use `var(--color-primary)`; errors use `--color-error`. Tailwind plain utilities for structural layout only.
- **React-free:** Alpine components registered via `document.addEventListener('alpine:init', …)`; `@js(...)` embeds initial state; POSTs send `X-CSRF-TOKEN` from the `<meta name=csrf-token>`.
- **DB portability:** Eloquent only, no raw SQL. Feature tests use `RefreshDatabase` (in-memory SQLite) + `$this->withoutVite()` for HTTP-render tests.
- **Auth gate is global** (`RequirePassword`) — all new routes auto-gated.
- **Workflow:** TDD, DRY, YAGNI, bite-sized commits.

## Scope Decisions (locked, per spec)

1. **F3c = manual series merge/split/move + rename only.** F3 is complete after this.
2. **Manage mode** on `/mangaka/{slug}` (flat checkable work list + sticky action bar: New series / Add to existing / Remove from series). **Rename** on `/series/{id}`.
3. Default new-series name = the **stem** of the first selected work (`TitleNormalizer::stem`, computed server-side per work); editable; required non-empty.
4. After a successful action the page **reloads**.
5. **Out of scope:** series cover (`cover_work_id`); deleting a series; drag-drop; cross-mangaka moves; bulk rename; undo.

## File Structure

- `app/Http/Controllers/SeriesManagementController.php` — **create**. `group`/`add`/`ungroup`/`rename` + private validators/cleanup.
- `routes/web.php` — **modify**. 4 POST routes + import.
- `app/Http/Controllers/MangakaController.php` — **modify**. `show()` also passes `manageWorks` + `manageSeries`.
- `resources/views/mangaka/show.blade.php` — **modify**. Manage toggle + flat list + action bar + inline `seriesManager` Alpine.
- `resources/views/series/show.blade.php` — **modify**. Inline rename (`seriesRename` Alpine).
- `tests/Feature/Series/SeriesManagementTest.php` — **create**. Endpoint + lock-contract + validation tests (Task 1).
- `tests/Feature/Series/SeriesManageUiTest.php` — **create**. View-render smoke (Task 2).

**Reference — existing shapes (verbatim, do not re-derive):**

- `App\Parsing\ParsedName::deriveSortTitle(string $title): string` — **static**. Use for `sort_name`.
- `App\Series\TitleNormalizer` — `public function stem(string $title): string` (instance method; **no-arg constructor** → `new TitleNormalizer()`). The detector injects it; here, use `new TitleNormalizer()` in `MangakaController`.
- `App\Series\SeriesDetectorContract` — resolve via `app(SeriesDetectorContract::class)->detect()` in tests (returns `['series_created'=>int,'works_grouped'=>int]`).
- `Series` (`$guarded=[]`, casts `is_auto=boolean`): `mangaka()` BelongsTo, `works()` HasMany. Columns: `mangaka_id`, `name`, `sort_name`, `is_auto`, `cover_work_id`.
- `Work` (`$guarded=[]`, casts `series_locked=boolean`, `is_missing=boolean`): `series()` BelongsTo, `mangaka()` BelongsTo. `series_id` (nullable, nullOnDelete), `series_locked` (default false).
- `SeriesDetector::detect()` (the contract to honor): per mangaka, clusters `Work::where('series_locked', false)`; clears `series_id` only on non-locked works; `Series::firstOrCreate([... 'is_auto'=>true], ['sort_name'=>ParsedName::deriveSortTitle($stem)])`; deletes `Series::where('is_auto',true)->whereDoesntHave('works')`. So **locked works + `is_auto=false` series are untouchable.**
- `SeedsMangakaWorks` trait (`tests/Feature/Series/SeedsMangakaWorks.php`): `$this->mangaka($name)` (firstOrCreate), `$this->seedWork($mangaka, $title, $overrides=[])` (creates a `Work` directly; pass `series_id`/`series_locked` via overrides). Used with `RefreshDatabase`.
- `MangakaController@show` (current): builds `$series` (with works) + `$standalone` (`whereNull('series_id')`) and `return view('mangaka.show', compact('mangaka','series','standalone'))`. `Mangaka` has `works()` + `series()`.
- `routes/web.php`: import block + `Route::get('/series/{series}', [SeriesController::class, 'show'])->name('series.show')` at line 43.
- Layout: `layouts.app` `<head>` has `<meta name="csrf-token">`. CSRF POST pattern (reader/F3a/F3b): `'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''`.
- `mangaka/show.blade.php` + `series/show.blade.php`: current full views (quoted in the tasks below).

---

## Task 1: `SeriesManagementController` + routes + endpoint/contract tests

**Files:**
- Create: `app/Http/Controllers/SeriesManagementController.php`, `tests/Feature/Series/SeriesManagementTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Produces: routes `series.group` (`POST /series/group`), `series.add` (`POST /series/{series}/add`), `series.ungroup` (`POST /series/ungroup`), `series.rename` (`POST /series/{series}/rename`). All consume JSON (`work_ids: int[]`, `name: string`), return JSON, set `series_locked=true` + `is_auto=false` per the lock contract.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Series/SeriesManagementTest.php`:
```php
<?php

namespace Tests\Feature\Series;

use App\Models\Series;
use App\Models\Work;
use App\Series\SeriesDetectorContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesManagementTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMangakaWorks;

    private function detect(): void
    {
        app(SeriesDetectorContract::class)->detect();
    }

    public function test_group_creates_a_locked_manual_series_that_survives_redetect(): void
    {
        $a = $this->seedWork('Z.A.P.', '四畳半物語');
        $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

        $this->postJson('/series/group', ['work_ids' => [$a->id, $b->id], 'name' => '四畳半物語'])
            ->assertStatus(201);

        $series = Series::firstOrFail();
        $this->assertFalse($series->is_auto);
        $this->assertSame('四畳半物語', $series->name);
        $this->assertSame($series->id, $a->refresh()->series_id);
        $this->assertTrue($a->refresh()->series_locked);
        $this->assertTrue($b->refresh()->series_locked);

        // Lock contract: a re-detect leaves the manual series + links intact.
        $this->detect();
        $this->assertNotNull(Series::find($series->id));
        $this->assertSame($series->id, $a->refresh()->series_id);
        $this->assertSame($series->id, $b->refresh()->series_id);
    }

    public function test_add_to_existing_series_flips_is_auto_and_locks(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $series = Series::create(['mangaka_id' => $m->id, 'name' => 'Stuff', 'is_auto' => true]);
        $w = $this->seedWork('Z.A.P.', 'ぽつん');

        $this->postJson('/series/'.$series->id.'/add', ['work_ids' => [$w->id]])->assertOk();

        $this->assertFalse($series->refresh()->is_auto);
        $this->assertSame($series->id, $w->refresh()->series_id);
        $this->assertTrue($w->refresh()->series_locked);
    }

    public function test_ungroup_clears_series_and_locks(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $series = Series::create(['mangaka_id' => $m->id, 'name' => 'S', 'is_auto' => false]);
        $w = $this->seedWork('Z.A.P.', 'x', ['series_id' => $series->id]);

        $this->postJson('/series/ungroup', ['work_ids' => [$w->id]])->assertOk();

        $this->assertNull($w->refresh()->series_id);
        $this->assertTrue($w->refresh()->series_locked);
    }

    public function test_group_deletes_an_emptied_auto_series(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $auto = Series::create(['mangaka_id' => $m->id, 'name' => 'Auto', 'is_auto' => true]);
        $a = $this->seedWork('Z.A.P.', 'a', ['series_id' => $auto->id]);
        $b = $this->seedWork('Z.A.P.', 'b', ['series_id' => $auto->id]);

        $this->postJson('/series/group', ['work_ids' => [$a->id, $b->id], 'name' => 'New'])->assertStatus(201);

        $this->assertNull(Series::find($auto->id)); // emptied auto series cleaned
    }

    public function test_rename_updates_name_sort_and_locks_members(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $series = Series::create(['mangaka_id' => $m->id, 'name' => 'Old', 'is_auto' => true]);
        $w = $this->seedWork('Z.A.P.', 'x', ['series_id' => $series->id]);

        $this->postJson('/series/'.$series->id.'/rename', ['name' => 'New Name'])->assertOk();

        $this->assertSame('New Name', $series->refresh()->name);
        $this->assertFalse($series->refresh()->is_auto);
        $this->assertTrue($w->refresh()->series_locked);
    }

    public function test_rejects_cross_mangaka_work_ids(): void
    {
        $a = $this->seedWork('Artist A', 'x');
        $b = $this->seedWork('Artist B', 'y');

        $this->postJson('/series/group', ['work_ids' => [$a->id, $b->id], 'name' => 'Mix'])
            ->assertStatus(422);
        $this->assertSame(0, Series::count());
    }

    public function test_rejects_blank_name(): void
    {
        $a = $this->seedWork('Z.A.P.', 'x');
        $this->postJson('/series/group', ['work_ids' => [$a->id], 'name' => '   '])->assertStatus(422);
    }

    public function test_add_rejects_series_from_another_mangaka(): void
    {
        $other = $this->mangaka('Artist B');
        $series = Series::create(['mangaka_id' => $other->id, 'name' => 'B series', 'is_auto' => false]);
        $w = $this->seedWork('Artist A', 'x');

        $this->postJson('/series/'.$series->id.'/add', ['work_ids' => [$w->id]])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesManagementTest`
Expected: FAIL — routes `/series/group` etc. not defined (404, so the 201/200 assertions fail).

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/SeriesManagementController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Series;
use App\Models\Work;
use App\Parsing\ParsedName;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Manual series management — group / add / ungroup / rename (F3c). / 手動シリーズ管理。
 *
 * DB-only (never touches /library). Every op sets series_locked=true (+ is_auto=false
 * on touched series) so SeriesDetector::detect() never undoes the manual decision.
 */
final class SeriesManagementController extends Controller
{
    /** Group works into a new manual series. / 新規シリーズに束ねる。 */
    public function group(Request $request)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Name is required.');
        $works = $this->sameMangakaWorks($data['work_ids']);
        $mangakaId = (int) $works->first()->mangaka_id;

        $series = Series::create([
            'mangaka_id' => $mangakaId,
            'name' => $name,
            'sort_name' => ParsedName::deriveSortTitle($name),
            'is_auto' => false,
        ]);
        Work::whereIn('id', $works->pluck('id'))->update(['series_id' => $series->id, 'series_locked' => true]);
        $this->cleanEmptyAutoSeries($mangakaId);

        return response()->json(['series_id' => $series->id], 201);
    }

    /** Add works to an existing series. / 既存シリーズに追加。 */
    public function add(Request $request, Series $series)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
        ]);
        $works = $this->sameMangakaWorks($data['work_ids']);
        abort_if((int) $works->first()->mangaka_id !== (int) $series->mangaka_id, 422, 'Series belongs to another mangaka.');

        $series->update(['is_auto' => false]);
        Work::whereIn('id', $works->pluck('id'))->update(['series_id' => $series->id, 'series_locked' => true]);
        $this->cleanEmptyAutoSeries((int) $series->mangaka_id);

        return response()->json(['ok' => true]);
    }

    /** Remove works from their series (→ standalone). / シリーズから外す。 */
    public function ungroup(Request $request)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
        ]);
        $works = $this->sameMangakaWorks($data['work_ids']);
        $mangakaId = (int) $works->first()->mangaka_id;

        Work::whereIn('id', $works->pluck('id'))->update(['series_id' => null, 'series_locked' => true]);
        $this->cleanEmptyAutoSeries($mangakaId);

        return response()->json(['ok' => true]);
    }

    /** Rename a series. / シリーズ名を変更。 */
    public function rename(Request $request, Series $series)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Name is required.');

        $series->update([
            'name' => $name,
            'sort_name' => ParsedName::deriveSortTitle($name),
            'is_auto' => false,
        ]);
        $series->works()->update(['series_locked' => true]);

        return response()->json(['ok' => true]);
    }

    /**
     * Load the works by id and ensure they all belong to one mangaka. / 同一マンガ家か検証。
     *
     * @param  int[]  $ids
     */
    private function sameMangakaWorks(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $works = Work::whereIn('id', $ids)->get(['id', 'mangaka_id']);
        abort_if($works->count() !== count($ids), 422, 'Unknown work in selection.');
        abort_if($works->pluck('mangaka_id')->unique()->count() !== 1, 422, 'Works span multiple mangaka.');

        return $works;
    }

    /** Delete now-empty auto series (mirrors the detector's self-cleaning). / 空の自動シリーズを削除。 */
    private function cleanEmptyAutoSeries(int $mangakaId): void
    {
        Series::where('mangaka_id', $mangakaId)
            ->where('is_auto', true)
            ->whereDoesntHave('works')
            ->delete();
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add the import (in the `use App\Http\Controllers\…;` block):
```php
use App\Http\Controllers\SeriesManagementController;
```
Add after the `series.show` route (line 43):
```php
Route::post('/series/group', [SeriesManagementController::class, 'group'])->name('series.group');
Route::post('/series/ungroup', [SeriesManagementController::class, 'ungroup'])->name('series.ungroup');
Route::post('/series/{series}/add', [SeriesManagementController::class, 'add'])->name('series.add');
Route::post('/series/{series}/rename', [SeriesManagementController::class, 'rename'])->name('series.rename');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesManagementTest` → PASS (8 tests).
Then the full suite: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test` → all green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/SeriesManagementController.php routes/web.php tests/Feature/Series/SeriesManagementTest.php
git commit -m "$(cat <<'EOF'
feat: SeriesManagementController — group/add/ungroup/rename (locked, DB-only)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Manage mode (mangaka page) + series rename UI

**Files:**
- Modify: `app/Http/Controllers/MangakaController.php`, `resources/views/mangaka/show.blade.php`, `resources/views/series/show.blade.php`
- Create: `tests/Feature/Series/SeriesManageUiTest.php`

**Interfaces:**
- Consumes: the Task 1 routes (`/series/group`, `/series/{id}/add`, `/series/ungroup`, `/series/{id}/rename`); `Mangaka`/`Work`/`Series`; `TitleNormalizer`; `<x-nav>`, `layouts.app`.
- Produces: `MangakaController@show` view data `manageWorks` (`[{id,title,series,stem}]`) + `manageSeries` (`[{id,name}]`); the `seriesManager` + `seriesRename` Alpine components.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Series/SeriesManageUiTest.php`:
```php
<?php

namespace Tests\Feature\Series;

use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesManageUiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMangakaWorks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_mangaka_page_embeds_manage_data_and_button(): void
    {
        $w = $this->seedWork('Z.A.P.', '四畳半物語');
        $slug = $w->mangaka->slug;

        $this->get('/mangaka/'.$slug)->assertOk()
            ->assertSee('四畳半物語')      // work title (normal view + embedded manage data)
            ->assertSee('Manage')          // manage toggle
            ->assertSee('seriesManager(', false); // Alpine manage component wired
    }

    public function test_series_page_has_inline_rename(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $series = Series::create(['mangaka_id' => $m->id, 'name' => 'My Series', 'is_auto' => false]);
        $this->seedWork('Z.A.P.', 'x', ['series_id' => $series->id]);

        $this->get('/series/'.$series->id)->assertOk()
            ->assertSee('My Series')
            ->assertSee('seriesRename(', false); // rename component wired
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesManageUiTest`
Expected: FAIL — `seriesManager(` / `seriesRename(` not present (views not yet updated).

- [ ] **Step 3: Pass manage data from `MangakaController@show`**

In `app/Http/Controllers/MangakaController.php`, replace the `show()` method's `return view(...)` and add the manage data just before it (keep the existing `$series` + `$standalone` queries unchanged):
```php
        // Flat list for Manage mode: every non-missing work + its current series + a
        // stem suggestion for the default new-series name. / 管理モード用の平坦リスト。
        $normalizer = new \App\Series\TitleNormalizer();
        $manageWorks = $mangaka->works()
            ->where('is_missing', false)
            ->with('series:id,name')
            ->orderBy('sort_title')
            ->get(['id', 'title', 'series_id', 'mangaka_id'])
            ->map(fn (\App\Models\Work $w) => [
                'id' => $w->id,
                'title' => $w->title,
                'series' => $w->series?->name,
                'stem' => $normalizer->stem($w->title),
            ])->all();
        $manageSeries = $series->map(fn (\App\Models\Series $s) => ['id' => $s->id, 'name' => $s->name])->values()->all();

        return view('mangaka.show', compact('mangaka', 'series', 'standalone', 'manageWorks', 'manageSeries'));
```

- [ ] **Step 4: Rewrite the mangaka view with Manage mode**

Replace `resources/views/mangaka/show.blade.php` entirely with:
```blade
@extends('layouts.app')

@php
    $manageInitial = ['works' => $manageWorks, 'series' => $manageSeries];
@endphp

@section('content')
    <x-nav active="mangaka" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);"
          x-data="seriesManager(@js($manageInitial))">

        <div class="flex items-center" style="gap:var(--space-md); margin-bottom:var(--space-xl);">
            <h1 style="flex:1; margin:0; font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">{{ $mangaka->name }}</h1>
            @if (! empty($manageWorks))
                <button type="button" @click="toggleManage()"
                        style="padding:7px 16px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption); cursor:pointer;"
                        x-text="managing ? 'Done' : 'Manage'">Manage</button>
            @endif
        </div>

        {{-- Normal grouped view --}}
        <div x-show="!managing">
            @if ($series->isNotEmpty())
                <section style="margin-bottom:var(--space-xxl);">
                    <x-section-heading>Series</x-section-heading>
                    <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                        @foreach ($series as $s)
                            @php $cover = optional($s->works->first(fn ($w) => $w->cover_path !== null))->cover_path; @endphp
                            <a href="/series/{{ $s->id }}" class="no-underline block">
                                <x-cover :path="$cover" :title="$s->name" />
                                <div class="truncate" style="margin-top:var(--space-xs); font:var(--type-caption-strong); color:var(--text-heading);">{{ $s->name }}</div>
                                <div style="font:var(--type-fine); color:var(--text-muted);">{{ $s->works->count() }} {{ \Illuminate\Support\Str::plural('work', $s->works->count()) }}</div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($standalone->isNotEmpty())
                <section>
                    <x-section-heading>Works</x-section-heading>
                    <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                        @foreach ($standalone as $work)
                            <x-work-card :work="$work" />
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($series->isEmpty() && $standalone->isEmpty())
                <p style="font:var(--type-body); color:var(--text-muted);">No works for this mangaka.</p>
            @endif
        </div>

        {{-- Manage mode: flat checkable list (initial display:none avoids a pre-Alpine flash) --}}
        <div x-show="managing" style="display:none;">
            <template x-for="w in works" :key="w.id">
                <label class="flex items-center" style="gap:var(--space-md); padding:var(--space-sm) 0; border-bottom:1px solid var(--color-hairline); cursor:pointer;">
                    <input type="checkbox" :value="w.id" x-model.number="selected" style="accent-color:var(--color-primary); cursor:pointer;">
                    <span class="truncate" style="flex:1; font:var(--type-caption-strong); color:var(--text-heading);" x-text="w.title"></span>
                    <span style="font:var(--type-fine); color:var(--text-muted);" x-text="w.series ? ('in: ' + w.series) : '—'"></span>
                </label>
            </template>

            <div x-show="selected.length > 0" x-transition
                 style="position:sticky; bottom:0; margin-top:var(--space-lg); padding:var(--space-md); background:var(--surface-card); border:1px solid var(--color-hairline); border-radius:var(--radius-md); display:flex; flex-direction:column; gap:var(--space-sm);">
                <div style="font:var(--type-caption); color:var(--text-muted);" x-text="selected.length + ' selected'"></div>

                <div class="flex items-center" style="gap:var(--space-sm); flex-wrap:wrap;">
                    <input type="text" x-model="newName" placeholder="New series name"
                           style="flex:1; min-width:160px; padding:7px 11px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
                    <button type="button" @click="group()" :disabled="busy || ! newName.trim()"
                            style="padding:7px 16px; border:none; border-radius:var(--radius-pill); background:var(--color-primary); color:var(--color-on-primary); font:var(--type-caption); cursor:pointer;">Create series</button>
                </div>

                <div class="flex items-center" style="gap:var(--space-sm); flex-wrap:wrap;">
                    <select x-model.number="addTarget"
                            style="padding:7px 11px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
                        <option value="">Add to series…</option>
                        <template x-for="s in series" :key="s.id">
                            <option :value="s.id" x-text="s.name"></option>
                        </template>
                    </select>
                    <button type="button" @click="add()" :disabled="busy || ! addTarget"
                            style="padding:7px 16px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption); cursor:pointer;">Add</button>
                    <button type="button" @click="ungroup()" :disabled="busy"
                            style="padding:7px 16px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption); cursor:pointer;">Remove from series</button>
                </div>

                <div x-show="error" x-text="error" style="color:var(--color-error); font:var(--type-caption);"></div>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('seriesManager', (initial) => ({
            works: initial.works ?? [],
            series: initial.series ?? [],
            managing: false,
            selected: [],
            newName: '',
            addTarget: '',
            busy: false,
            error: '',

            init() {
                this.$watch('selected', () => {
                    if (this.selected.length && ! this.newName) this.newName = this.firstStem();
                });
            },
            toggleManage() { this.managing = ! this.managing; this.selected = []; this.error = ''; },
            firstStem() {
                const w = this.works.find((x) => x.id === this.selected[0]);
                return w ? w.stem : '';
            },

            async post(url, body) {
                this.busy = true;
                this.error = '';
                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                        },
                        body: JSON.stringify(body),
                    });
                    if (! res.ok) throw new Error('http ' + res.status);
                    window.location.reload();
                } catch (e) {
                    this.error = 'Action failed — try again.';
                    this.busy = false;
                }
            },
            group() { if (this.newName.trim()) this.post('/series/group', { work_ids: this.selected, name: this.newName.trim() }); },
            add() { if (this.addTarget) this.post('/series/' + this.addTarget + '/add', { work_ids: this.selected }); },
            ungroup() { this.post('/series/ungroup', { work_ids: this.selected }); },
        }));
    });
    </script>
@endsection
```

- [ ] **Step 5: Add inline rename to the series view**

Replace `resources/views/series/show.blade.php` entirely with:
```blade
@extends('layouts.app')

@section('content')
    <x-nav />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <div style="margin-bottom:var(--space-xl);" x-data="seriesRename({{ $series->id }}, @js($series->name))">
            <a href="/mangaka/{{ $series->mangaka->slug }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">{{ $series->mangaka->name }}</a>

            <div class="flex items-center" style="gap:var(--space-sm);">
                <h1 x-show="! editing" @click="start()" title="Rename"
                    style="margin:0; font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md); cursor:pointer;"
                    x-text="name">{{ $series->name }}</h1>
                <button type="button" x-show="! editing" @click="start()"
                        style="background:none; border:none; padding:0; cursor:pointer; color:var(--text-link); font:var(--type-caption);">Rename</button>
            </div>

            <div x-show="editing" style="display:none; gap:var(--space-sm); align-items:center;" class="flex">
                <input x-ref="renameInput" type="text" x-model="draft" @keydown.enter.prevent="save()" @keydown.escape="editing = false"
                       style="font:var(--type-body); padding:6px 10px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body);">
                <button type="button" @click="save()" :disabled="busy || ! draft.trim()"
                        style="padding:6px 14px; border:none; border-radius:var(--radius-pill); background:var(--color-primary); color:var(--color-on-primary); font:var(--type-caption); cursor:pointer;">Save</button>
                <button type="button" @click="editing = false"
                        style="padding:6px 14px; border:none; background:none; color:var(--text-muted); font:var(--type-caption); cursor:pointer;">Cancel</button>
                <span x-show="error" x-text="error" style="color:var(--color-error); font:var(--type-caption);"></span>
            </div>
        </div>

        <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
            @foreach ($works as $work)
                <x-work-card :work="$work" />
            @endforeach
        </div>
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('seriesRename', (id, name) => ({
            id,
            name,
            draft: name,
            editing: false,
            busy: false,
            error: '',

            start() { this.draft = this.name; this.editing = true; this.error = ''; this.$nextTick(() => this.$refs.renameInput?.focus()); },
            async save() {
                const v = this.draft.trim();
                if (! v) return;
                this.busy = true;
                this.error = '';
                try {
                    const res = await fetch('/series/' + this.id + '/rename', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                        },
                        body: JSON.stringify({ name: v }),
                    });
                    if (! res.ok) throw new Error('http ' + res.status);
                    window.location.reload();
                } catch (e) {
                    this.error = 'Rename failed — try again.';
                    this.busy = false;
                }
            },
        }));
    });
    </script>
@endsection
```

- [ ] **Step 6: Run tests**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesManageUiTest` → PASS (2 tests).
Then the full suite: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test` → all green (existing MangakaTest / SeriesAndWorkTest still pass — the views still render the mangaka name + series name + work cards).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/MangakaController.php resources/views/mangaka/show.blade.php resources/views/series/show.blade.php tests/Feature/Series/SeriesManageUiTest.php
git commit -m "$(cat <<'EOF'
feat: series manage mode (mangaka page) + inline series rename

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
Expected: ALL green (incl. SeriesManagementTest 8 + SeriesManageUiTest 2). Output pristine.

- [ ] **Step 3: Browser render-verify gate**

Seed (dev DB; no zips needed) one mangaka with ~5 works — some standalone, some in an auto series — directly in the DB (reuse the `wydoujin-local-browser-gate` approach; plain `php artisan serve`, no library env needed since these pages read only the DB). Open `/mangaka/{slug}` and verify, with **no console errors**, in both light and dark themes:
- Normal view shows series cards + standalone works; a **Manage** button.
- Click **Manage** → flat checkable list of all works (title + "in: \<series\>"/"—"); the normal view hides.
- Select ≥2 standalone works → action bar appears; the **New series** name defaults to the first selection's stem → **Create series** → page reloads → the works are now one series (and any emptied auto series is gone). 
- Re-enter Manage, select a work that's in a series → **Remove from series** → reload → it's standalone.
- Select works → **Add to series…** (pick one) → reload → moved.
- On `/series/{id}`: **Rename** → inline input → Save → reload → new name shows.
- Confirm in the DB (tinker) that affected works have `series_locked = 1` and touched series `is_auto = 0`.
- (Optional) run `php artisan wydoujin:series:detect` and confirm the manual grouping/name is unchanged (the lock contract, live).

- [ ] **Step 4: Commit (only if the build emitted tracked changes)**

`public/build` is git-ignored, so normally nothing to commit. If `git status --porcelain` shows tracked changes, commit them with the trailer.

---

## Self-Review

**Spec coverage (F3c design doc):**
- Manage mode (flat list + action bar) on the mangaka page → Task 2 (MangakaController `manageWorks`/`manageSeries` + mangaka view + `seriesManager`).
- group/add/ungroup/rename + per-mangaka validation + empty-auto-series cleanup → Task 1 (`SeriesManagementController`) + `SeriesManagementTest`.
- **The lock contract** (series_locked + is_auto + detect()-after-op unchanged) → Task 1 controller + `test_group_creates_a_locked_manual_series_that_survives_redetect`.
- Rename on the series page → Task 2 (`series/show.blade.php` + `seriesRename`).
- Default new-series name = first selection's stem → `MangakaController` (`stem` per work) + `seriesManager.firstStem()`.
- Reload after action; token-styled errors; CSRF; one blue accent → Task 2 views; build regression → Task 3.
- Read-only/DB-only → all ops are Eloquent row updates (no filesystem writes), Global Constraints.

**Placeholder scan:** none — every step has complete code + exact commands/expected output.

**Type consistency:** routes `series.group`/`series.add`/`series.ungroup`/`series.rename` with literal paths `/series/group`, `/series/{id}/add`, `/series/ungroup`, `/series/{id}/rename` — used identically in the controller, the views' `fetch` calls, and the tests. `ParsedName::deriveSortTitle` (static) + `new TitleNormalizer()->stem()` match the verified shapes. `manageWorks` items `{id,title,series,stem}` produced by `MangakaController` and consumed by `seriesManager` (`w.title`, `w.series`, `w.stem`, `w.id`). `work_ids`/`name` JSON keys match between views, controller validation, and tests. `sameMangakaWorks` returns a `Collection` of `{id,mangaka_id}`; `->pluck('id')` feeds the mass updates.

**Interactive verification:** the live Alpine (manage toggle, multi-select, the 3 actions + rename, reload) is browser-only → Task 3 gate, not PHPUnit (consistent with F2/F3a/F3b).

**Out of scope (later):** series cover (`cover_work_id`); deleting a series; drag-drop; cross-mangaka moves; bulk rename; undo. F3 is complete after this.
