# wydoujin — Search + Filters (F3a) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `/browse` surface — a live, debounced title search plus a left-sidebar faceted filter rail (circle / parody / event, multi-select with dynamic counts) driving a grid of work cards, deep-linkable and React-free.

**Architecture:** A `WorkSearch` query service turns request params into (a) a paginated, facet-filtered `Work` result set and (b) dynamic facet counts (each dimension counted under the search + the *other* facets, never its own). A thin `BrowseSearchController@index` returns either the full page (server-rendered first page of cards + embedded state) or JSON `{ total, page, hasMore, facets, html }`. An Alpine `browse` component swaps the server-rendered results grid (`<x-work-card>` stays the single source) and renders facet rows reactively from the JSON, syncing the URL.

**Tech Stack:** Laravel 13 Blade + Alpine.js + Tailwind v4; design tokens. No new dependencies, no schema changes (`circle`/`parody`/`event`/`title` already indexed).

**Spec:** `docs/superpowers/specs/2026-06-23-wydoujin-search-filters-design.md`. Parent: `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §10.

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+ (local dev 8.5). No `declare(strict_types=1)`.
- **Broken local toolchain:** prefix EVERY php command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5.4). Env doesn't persist between Bash calls — repeat it. Run tests via `php artisan test`. Node/npm are on the normal PATH.
- **Avoid `cd` in compound bash;** use absolute paths / `git -C`.
- **Commit trailer:** every commit ends with a blank line then exactly:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **PHP style:** single quotes unless interpolation is needed; inline typed properties (no `@var` when the type is expressible natively); short **bilingual (EN / JP)** doc comments on new classes/methods (match the existing `MangakaController` style).
- **Design tokens mandatory (§13).** Never inline a raw hex/size — reference a token (`--color-primary`, `--color-on-primary`, `--color-hairline`, `--text-heading`, `--text-body`, `--text-muted`, `--text-link`, `--surface-page`, `--surface-alt`, `--color-black`, `--color-on-dark`, `--color-body-muted`, `var(--type-*)`, `var(--radius-*)`, `var(--space-*)`, `--grid-gutter`, `--container-grid`). Tailwind plain utilities for structural layout only. **Interactive controls (checkboxes, range) use `accent-color:var(--color-primary)`** — one blue accent (the F2 slider lesson).
- **React-free:** register the Alpine component via `document.addEventListener('alpine:init', () => Alpine.data('browse', …))` so it's defined before `Alpine.start()` (`resources/js/app.js` just imports Alpine + starts it).
- **Views link via literal paths** (`/browse`, `/work/{id}`); routes are still named.
- **DB portability:** Eloquent only, **no raw SQL** — compute facet counts with `Collection::countBy()` in PHP, not SQL aggregates. Feature tests use `RefreshDatabase` on in-memory SQLite + `$this->withoutVite()`.
- **Auth gate is global** (`RequirePassword` on the `web` group) — `/browse` is auto-gated; no per-route auth.
- **Workflow:** TDD, DRY, YAGNI, bite-sized commits.

## Scope Decisions (locked, per spec)

1. **F3a = search + filters only.** F3b (scan/maintenance) and F3c (series management) are separate later sub-projects.
2. **Single `/browse` surface** hosts both search and facets.
3. **Live debounced search** (~250 ms) over `title` + `title_raw`, case-insensitive `LIKE %q%` (portable SQLite/MySQL).
4. **Left-sidebar facet rail**; results grid fills the rest; rail collapses to a "Filters" drawer on narrow screens.
5. **Facets = circle / parody / event.** Multi-select **OR within** a facet, **AND across** facets.
6. **Dynamic counts:** each dimension is counted under the search + the **other** facets' selections, **never its own** — so its remaining values stay selectable. Selected-but-zero values stay visible so they can be unchecked.
7. **HTML-over-the-wire for cards, JSON for counts:** results grid is server-rendered (`<x-work-card>` single source); the JSON endpoint returns `{ total, page, hasMore, facets, html }`; facet rows are Alpine-rendered from `facets`.
8. **"Load more"** pagination (append next page); ~60/page.
9. **Not-missing works only** (`is_missing = false`).

**Out of scope (later):** dynamic *vs* search over circle/author text; sort options; saved searches; numbered pagination; `FULLTEXT`; F3b / F3c surfaces.

## File Structure

- `app/Browse/WorkSearch.php` — **create**. Query service: `results()` (paginated, facet-filtered) + `facets()` (dynamic counts). The testable heart.
- `app/Http/Controllers/BrowseSearchController.php` — **create**. `index(Request)` → full page or JSON.
- `routes/web.php` — **modify**. Add `GET /browse` (name `browse.index`) + the import.
- `resources/views/components/nav.blade.php` — **modify**. Add the **Browse** nav link.
- `resources/views/browse/_cards.blade.php` — **create**. Cards-only partial (the JSON `html` + initial server render).
- `resources/views/browse/index.blade.php` — **create**. Page: facet rail + results grid + the inline `browse` Alpine component.
- `tests/Feature/Browse/WorkSearchTest.php` — **create**. Service tests (Task 1).
- `tests/Feature/Browse/BrowseSearchTest.php` — **create**. HTTP + JSON tests (Task 2).

**Reference — existing shapes (verbatim, do not re-derive):**

- `App\Models\Work` (`$guarded = []`): casts include `'is_missing' => 'boolean'`, `'series_locked' => 'boolean'`, `'flags' => 'array'`, `'page_count' => 'integer'`. Relationships: `mangaka()` BelongsTo, `series()` BelongsTo, `readingProgress()` HasOne. Columns for F3a: `title`, `title_raw`, `circle`, `parody`, `event` (all indexed except `title_raw`), `is_missing`, `sort_title`, `cover_path`, `page_count`.
- **`Work` factory leaves `circle`/`parody`/`event`/`sort_title` NULL by default** — tests MUST set them explicitly via `create([...])`. Factory sets `title`, `title_raw` (= title), `page_count`, etc. `Mangaka::factory()->create()`; `Work::factory()->for($mangaka)->create([...])`.
- `<x-work-card :work="$work" />` (`resources/views/components/work-card.blade.php`) self-computes progress from `$work->readingProgress`; reads `cover_path`, `title`, `circle`, `page_count`. **Eager-load `readingProgress`** to avoid N+1.
- F1 grid pattern (from `home.blade.php` / `mangaka/index.blade.php`):
  `<div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);"> … <x-work-card …/> … </div>`
- `<x-nav active="…" />` (`resources/views/components/nav.blade.php`): logo + a `flex:1` link group (Home, Mangaka) + theme toggle. Links use `{{ $active === 'X' ? '[color:var(--color-on-dark)]' : '[color:var(--color-body-muted)]' }}` + `style="font:var(--type-nav);"`.
- Page shell (from `mangaka/index.blade.php`): `@extends('layouts.app')` → `@section('content')` → `<x-nav active="…" />` then `<main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">…</main>`.
- Test pattern (`tests/Feature/Browse/SeriesAndWorkTest.php`): `namespace Tests\Feature\Browse;` · `class …Test extends TestCase` · `use RefreshDatabase;` · `setUp(): parent::setUp(); $this->withoutVite();`.
- `layouts.app` `<head>` already has `<meta name="csrf-token">` and the pre-paint `wyd-theme` script; `<body>` is token-styled; `@vite([...])` loads `app.css` + `app.js`.

---

## Task 1: `WorkSearch` query service (results + dynamic facets)

**Files:**
- Create: `app/Browse/WorkSearch.php`
- Test: `tests/Feature/Browse/WorkSearchTest.php`

**Interfaces:**
- Consumes: `App\Models\Work` (Eloquent), `Illuminate\Http\Request`.
- Produces:
  - `WorkSearch::__construct(?string $q = null, array $circle = [], array $parody = [], array $event = [])`
  - `WorkSearch::fromRequest(Request): self`
  - `results(int $page = 1, int $perPage = 60): LengthAwarePaginator` — facet-filtered, `is_missing=false`, ordered by `sort_title`, `readingProgress` eager-loaded.
  - `facets(): array<string, list<array{value:string,count:int}>>` — keys `circle`/`parody`/`event`; dynamic counts excluding own dimension; selected-but-zero values retained; sorted count desc then value asc.
  - `const DIMENSIONS = ['circle', 'parody', 'event']`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Browse/WorkSearchTest.php`:
```php
<?php

namespace Tests\Feature\Browse;

use App\Browse\WorkSearch;
use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkSearchTest extends TestCase
{
    use RefreshDatabase;

    private ?Mangaka $m = null;

    /** @param array<string,mixed> $a */
    private function work(array $a): Work
    {
        $this->m ??= Mangaka::factory()->create();

        return Work::factory()->for($this->m)->create($a);
    }

    public function test_excludes_missing_works(): void
    {
        $this->work(['title' => 'Seen', 'sort_title' => 'Seen', 'is_missing' => false]);
        $this->work(['title' => 'Gone', 'sort_title' => 'Gone', 'is_missing' => true]);

        $titles = (new WorkSearch())->results()->pluck('title')->all();

        $this->assertContains('Seen', $titles);
        $this->assertNotContains('Gone', $titles);
    }

    public function test_q_matches_title_and_title_raw_case_insensitively(): void
    {
        $this->work(['title' => 'Hello World', 'title_raw' => 'Hello World', 'sort_title' => 'a']);
        $this->work(['title' => 'zzz', 'title_raw' => '(C99) hello [raw]', 'sort_title' => 'b']); // matches via title_raw
        $this->work(['title' => 'nope', 'title_raw' => 'nope', 'sort_title' => 'c']);

        $titles = (new WorkSearch(q: 'HELLO'))->results()->pluck('title')->all();

        sort($titles);
        $this->assertSame(['Hello World', 'zzz'], $titles);
    }

    public function test_facets_or_within_and_and_across(): void
    {
        $this->work(['title' => 'A-P', 'sort_title' => '1', 'circle' => 'A', 'parody' => 'P']);
        $this->work(['title' => 'B-P', 'sort_title' => '2', 'circle' => 'B', 'parody' => 'P']);
        $this->work(['title' => 'C-Q', 'sort_title' => '3', 'circle' => 'C', 'parody' => 'Q']);

        // OR within circle:
        $or = (new WorkSearch(circle: ['A', 'B']))->results()->pluck('title')->all();
        sort($or);
        $this->assertSame(['A-P', 'B-P'], $or);

        // AND across circle + parody:
        $and = (new WorkSearch(circle: ['A', 'B'], parody: ['Q']))->results()->pluck('title')->all();
        $this->assertSame([], $and); // A,B are parody P, not Q
    }

    public function test_counts_are_dynamic_and_exclude_own_dimension(): void
    {
        $this->work(['title' => 'w1', 'sort_title' => '1', 'circle' => 'A', 'parody' => 'P']);
        $this->work(['title' => 'w2', 'sort_title' => '2', 'circle' => 'A', 'parody' => 'Q']);
        $this->work(['title' => 'w3', 'sort_title' => '3', 'circle' => 'B', 'parody' => 'P']);

        $facets = (new WorkSearch(parody: ['P']))->facets();

        // circle counted under parody=P → {A:1 (w1), B:1 (w3)}
        $circle = collect($facets['circle'])->pluck('count', 'value')->all();
        $this->assertSame(['A' => 1, 'B' => 1], $circle);

        // parody EXCLUDES its own selection → counted over all → {P:2, Q:1}
        $parody = collect($facets['parody'])->pluck('count', 'value')->all();
        $this->assertSame(['P' => 2, 'Q' => 1], $parody);
    }

    public function test_selected_value_kept_visible_when_zero(): void
    {
        $this->work(['title' => 'only', 'sort_title' => '1', 'circle' => 'A']);

        $facets = (new WorkSearch(circle: ['B']))->facets(); // B has no works

        $circle = collect($facets['circle'])->pluck('count', 'value')->all();
        $this->assertSame(1, $circle['A']);
        $this->assertArrayHasKey('B', $circle);
        $this->assertSame(0, $circle['B']); // selected → retained at 0
    }

    public function test_results_ordered_by_sort_title_and_paginated(): void
    {
        $this->work(['title' => 'C', 'sort_title' => 'C']);
        $this->work(['title' => 'A', 'sort_title' => 'A']);
        $this->work(['title' => 'B', 'sort_title' => 'B']);

        $page = (new WorkSearch())->results(page: 1, perPage: 2);

        $this->assertSame(['A', 'B'], $page->pluck('title')->all());
        $this->assertSame(3, $page->total());
        $this->assertTrue($page->hasMorePages());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=WorkSearchTest`
Expected: FAIL — `Class "App\Browse\WorkSearch" not found`.

- [ ] **Step 3: Implement `WorkSearch`**

`app/Browse/WorkSearch.php`:
```php
<?php

namespace App\Browse;

use App\Models\Work;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Title search + faceted filtering over works (F3a). / 作品の検索＋ファセット絞り込み。
 *
 * Facets: circle/parody/event. OR within a facet, AND across. Counts are dynamic —
 * each dimension is counted under the search + the OTHER facets (never its own),
 * so its remaining values stay selectable.
 */
final class WorkSearch
{
    public const DIMENSIONS = ['circle', 'parody', 'event'];

    /**
     * @param string[] $circle
     * @param string[] $parody
     * @param string[] $event
     */
    public function __construct(
        public readonly ?string $q = null,
        public readonly array $circle = [],
        public readonly array $parody = [],
        public readonly array $event = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        $clean = static fn ($v): array => array_values(array_filter(
            array_map('strval', (array) $v),
            static fn (string $s): bool => $s !== '',
        ));
        $q = trim((string) $request->query('q', ''));

        return new self(
            q: $q === '' ? null : $q,
            circle: $clean($request->query('circle', [])),
            parody: $clean($request->query('parody', [])),
            event: $clean($request->query('event', [])),
        );
    }

    /** Selected values for a dimension. / 次元の選択値。 */
    private function selected(string $dim): array
    {
        return $this->{$dim};
    }

    /** Base query: not-missing + optional title LIKE. / 基底: 欠落除外＋題名LIKE。 */
    private function base(): Builder
    {
        return Work::query()
            ->where('is_missing', false)
            ->when($this->q !== null, function (Builder $w): void {
                $term = '%'.$this->q.'%';
                $w->where(function (Builder $x) use ($term): void {
                    $x->where('title', 'like', $term)->orWhere('title_raw', 'like', $term);
                });
            });
    }

    /** Apply facet whereIns, optionally skipping one dimension. / ファセット適用（1次元除外可）。 */
    private function applyFacets(Builder $query, ?string $except = null): Builder
    {
        foreach (self::DIMENSIONS as $dim) {
            $values = $this->selected($dim);
            if ($dim !== $except && $values !== []) {
                $query->whereIn($dim, $values);
            }
        }

        return $query;
    }

    public function results(int $page = 1, int $perPage = 60): LengthAwarePaginator
    {
        return $this->applyFacets($this->base())
            ->with('readingProgress')
            ->orderBy('sort_title')
            ->paginate($perPage, ['*'], 'page', max(1, $page));
    }

    /**
     * Dynamic facet counts. / 動的ファセット件数。
     *
     * @return array<string, list<array{value:string,count:int}>>
     */
    public function facets(): array
    {
        $out = [];
        foreach (self::DIMENSIONS as $dim) {
            // Count under base + the OTHER facets (exclude this dim's own selection).
            $counts = $this->applyFacets($this->base(), except: $dim)
                ->whereNotNull($dim)
                ->pluck($dim)
                ->countBy()
                ->all(); // value => count

            // Keep selected-but-now-absent values visible so they can be unchecked.
            foreach ($this->selected($dim) as $sel) {
                $counts[$sel] ??= 0;
            }

            $rows = [];
            foreach ($counts as $value => $count) {
                $rows[] = ['value' => (string) $value, 'count' => (int) $count];
            }
            // count desc, then value asc.
            usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);

            $out[$dim] = $rows;
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=WorkSearchTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Browse/WorkSearch.php tests/Feature/Browse/WorkSearchTest.php
git commit -m "$(cat <<'EOF'
feat: add WorkSearch — title search + dynamic faceted filtering

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Route, controller, views, nav link (server + JSON)

**Files:**
- Create: `app/Http/Controllers/BrowseSearchController.php`, `resources/views/browse/_cards.blade.php`, `resources/views/browse/index.blade.php`, `tests/Feature/Browse/BrowseSearchTest.php`
- Modify: `routes/web.php`, `resources/views/components/nav.blade.php`

**Interfaces:**
- Consumes: `App\Browse\WorkSearch` (Task 1); `<x-work-card>`, `<x-nav>`, `layouts.app`.
- Produces: route `browse.index` → `BrowseSearchController::index(Request)`; the `browse.index` view exposing `x-data="browse(<initial>)"`; JSON `{ total, page, hasMore, facets, html }`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Browse/BrowseSearchTest.php`:
```php
<?php

namespace Tests\Feature\Browse;

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowseSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private ?Mangaka $m = null;

    /** @param array<string,mixed> $a */
    private function work(array $a): Work
    {
        $this->m ??= Mangaka::factory()->create();

        return Work::factory()->for($this->m)->create($a);
    }

    public function test_browse_renders_grid_and_nav_link(): void
    {
        $this->work(['title' => 'Findable Title', 'sort_title' => 'a']);

        $this->get('/browse')->assertOk()
            ->assertSee('Findable Title')
            ->assertSee('href="/browse"', false)  // nav link
            ->assertSee('No works match');         // empty-state element present in DOM (Alpine-hidden)
    }

    public function test_q_filters_server_rendered_results(): void
    {
        $this->work(['title' => 'Alpha Doujin', 'sort_title' => 'a']);
        $this->work(['title' => 'Beta Manga', 'sort_title' => 'b']);

        $this->get('/browse?q=alpha')->assertOk()
            ->assertSee('Alpha Doujin')
            ->assertDontSee('Beta Manga');
    }

    public function test_facet_filters_results(): void
    {
        $this->work(['title' => 'ZapWork', 'sort_title' => 'a', 'circle' => 'Z.A.P.']);
        $this->work(['title' => 'FooWork', 'sort_title' => 'b', 'circle' => 'Foo']);

        $url = '/browse?'.http_build_query(['circle' => ['Z.A.P.']]);
        $this->get($url)->assertOk()
            ->assertSee('ZapWork')
            ->assertDontSee('FooWork');
    }

    public function test_excludes_missing(): void
    {
        $this->work(['title' => 'GoneWork', 'sort_title' => 'a', 'is_missing' => true]);

        $this->get('/browse')->assertOk()->assertDontSee('GoneWork');
    }

    public function test_embeds_facet_data_for_alpine(): void
    {
        $this->work(['title' => 'X', 'sort_title' => 'a', 'circle' => 'Z.A.P.']);

        // The facet value ships in the embedded initial-state JSON.
        $this->get('/browse')->assertOk()->assertSee('Z.A.P.');
    }

    public function test_json_endpoint_shape(): void
    {
        $this->work(['title' => 'JsonWork', 'sort_title' => 'a', 'circle' => 'C1']);

        $res = $this->getJson('/browse')->assertOk()
            ->assertJsonStructure([
                'total', 'page', 'hasMore',
                'facets' => ['circle', 'parody', 'event'],
                'html',
            ]);
        $this->assertStringContainsString('JsonWork', $res->json('html'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=BrowseSearchTest`
Expected: FAIL — route `/browse` not defined (404).

- [ ] **Step 3: Create the cards partial**

`resources/views/browse/_cards.blade.php`:
```blade
@foreach ($works as $work)
    <x-work-card :work="$work" />
@endforeach
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/BrowseSearchController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Browse\WorkSearch;
use Illuminate\Http\Request;

/** Browse: live title search + faceted filtering (F3a). / 検索＋ファセット絞り込み。 */
final class BrowseSearchController extends Controller
{
    public function index(Request $request)
    {
        $search = WorkSearch::fromRequest($request);
        $page = max(1, (int) $request->query('page', 1));

        $works = $search->results($page, 60);
        $facets = $search->facets();

        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json([
                'total' => $works->total(),
                'page' => $works->currentPage(),
                'hasMore' => $works->hasMorePages(),
                'facets' => $facets,
                'html' => view('browse._cards', ['works' => $works->items()])->render(),
            ]);
        }

        return view('browse.index', [
            'works' => $works,
            'facets' => $facets,
            'total' => $works->total(),
            'hasMore' => $works->hasMorePages(),
            'search' => $search,
        ]);
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, add the import after `use App\Http\Controllers\BrowseController;` (line 4):
```php
use App\Http\Controllers\BrowseSearchController;
```
Add the route after the `mangaka.show` route (line 33):
```php
Route::get('/browse', [BrowseSearchController::class, 'index'])->name('browse.index');
```

- [ ] **Step 6: Add the Browse nav link**

In `resources/views/components/nav.blade.php`, add after the Mangaka link (line 8):
```blade
        <a href="/browse" class="no-underline {{ $active === 'browse' ? '[color:var(--color-on-dark)]' : '[color:var(--color-body-muted)]' }} hover:[color:var(--color-on-dark)]" style="font:var(--type-nav);">Browse</a>
```

- [ ] **Step 7: Create the browse page (rail + grid + Alpine)**

`resources/views/browse/index.blade.php`:
```blade
@extends('layouts.app')

@php
    $initial = [
        'q' => $search->q ?? '',
        'selected' => ['circle' => $search->circle, 'parody' => $search->parody, 'event' => $search->event],
        'facets' => $facets,
        'total' => $total,
        'page' => $works->currentPage(),
        'hasMore' => $hasMore,
    ];
@endphp

@section('content')
    <x-nav active="browse" />

    <div x-data="browse(@js($initial))"
         class="mx-auto w-full flex"
         style="max-width:var(--container-grid); padding:var(--space-lg) var(--space-lg); gap:var(--space-xl); align-items:flex-start;">

        {{-- Mobile "Filters" toggle --}}
        <button type="button" class="lg:hidden" @click="railOpen = !railOpen"
                style="position:fixed; bottom:var(--space-lg); right:var(--space-lg); z-index:30; padding:11px 22px; border:none; border-radius:var(--radius-pill); background:var(--color-primary); color:var(--color-on-primary); font:var(--type-caption-strong); cursor:pointer;"
                x-text="activeCount() ? ('Filters (' + activeCount() + ')') : 'Filters'"></button>

        {{-- Facet rail --}}
        <aside class="shrink-0"
               :class="railOpen ? 'block' : 'hidden lg:block'"
               style="width:240px;">
            <form action="/browse" method="get" @submit.prevent="refresh()" style="margin-bottom:var(--space-lg);">
                <input type="search" name="q" x-model="q" placeholder="Search title…" aria-label="Search title"
                       class="w-full"
                       style="padding:9px 13px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
            </form>

            <template x-for="group in groups" :key="group.key">
                <div style="margin-bottom:var(--space-lg);">
                    <div style="font:var(--type-fine); letter-spacing:0.4px; text-transform:uppercase; color:var(--text-muted); margin-bottom:var(--space-xs);" x-text="group.label"></div>

                    <input x-show="(facets[group.key] || []).length > cap" x-model="within[group.key]"
                           :placeholder="'filter ' + group.label.toLowerCase() + '…'" :aria-label="'filter ' + group.label"
                           class="w-full" style="margin-bottom:var(--space-xs); padding:5px 9px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-fine);">

                    <template x-for="row in visibleRows(group.key)" :key="group.key + '::' + row.value">
                        <label class="flex items-center" style="gap:var(--space-xs); padding:3px 0; cursor:pointer;">
                            <input type="checkbox" :checked="isChecked(group.key, row.value)" @change="toggle(group.key, row.value)"
                                   style="accent-color:var(--color-primary); cursor:pointer;">
                            <span class="truncate" style="flex:1; font:var(--type-caption); color:var(--text-body);" x-text="row.value"></span>
                            <span style="font:var(--type-fine); color:var(--text-muted);" x-text="row.count"></span>
                        </label>
                    </template>

                    <button type="button" x-show="hasMoreRows(group.key)" @click="expanded[group.key] = true"
                            style="margin-top:var(--space-xxs); background:none; border:none; padding:0; cursor:pointer; font:var(--type-fine); color:var(--text-link);">+ show more</button>
                </div>
            </template>
        </aside>

        {{-- Results --}}
        <main class="min-w-0" style="flex:1;">
            <div class="flex items-center" style="gap:var(--space-md); margin-bottom:var(--space-md);">
                <span style="font:var(--type-caption); color:var(--text-muted);" x-text="total + ' ' + (total === 1 ? 'work' : 'works')"></span>
                <button type="button" x-show="activeCount() > 0" @click="clear()"
                        style="background:none; border:none; padding:0; cursor:pointer; font:var(--type-caption); color:var(--text-link);">Clear filters</button>
            </div>

            <div x-ref="grid" x-show="total > 0" class="grid"
                 style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                @include('browse._cards', ['works' => $works])
            </div>

            <div x-show="total === 0" style="padding:var(--space-xxl) 0; text-align:center;">
                <p style="font:var(--type-body); color:var(--text-muted);">No works match.</p>
                <button type="button" @click="clear()"
                        style="margin-top:var(--space-sm); background:none; border:none; cursor:pointer; font:var(--type-caption); color:var(--text-link);">Clear filters</button>
            </div>

            <div style="text-align:center; margin-top:var(--space-xl);">
                <button type="button" x-show="hasMore" @click="loadMore()" :disabled="loading"
                        style="padding:9px 22px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption); cursor:pointer;"
                        x-text="loading ? 'Loading…' : 'Load more'"></button>
            </div>
        </main>
    </div>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('browse', (initial) => ({
            q: initial.q ?? '',
            selected: initial.selected ?? { circle: [], parody: [], event: [] },
            facets: initial.facets ?? { circle: [], parody: [], event: [] },
            total: initial.total ?? 0,
            page: initial.page ?? 1,
            hasMore: initial.hasMore ?? false,
            loading: false,
            railOpen: false,
            cap: 15,
            groups: [
                { key: 'circle', label: 'Circle' },
                { key: 'parody', label: 'Parody' },
                { key: 'event', label: 'Event' },
            ],
            expanded: { circle: false, parody: false, event: false },
            within: { circle: '', parody: '', event: '' },
            _debounce: null,
            _reqId: 0,

            init() {
                this.$watch('q', () => {
                    clearTimeout(this._debounce);
                    this._debounce = setTimeout(() => this.refresh(), 250);
                });
            },

            dims() { return ['circle', 'parody', 'event']; },
            isChecked(dim, value) { return this.selected[dim].includes(value); },

            visibleRows(dim) {
                let rows = this.facets[dim] || [];
                const term = (this.within[dim] || '').toLowerCase();
                if (term) rows = rows.filter((r) => r.value.toLowerCase().includes(term));
                if (!this.expanded[dim] && rows.length > this.cap) rows = rows.slice(0, this.cap);
                return rows;
            },
            hasMoreRows(dim) {
                return !this.expanded[dim] && !this.within[dim] && (this.facets[dim]?.length || 0) > this.cap;
            },

            toggle(dim, value) {
                const i = this.selected[dim].indexOf(value);
                if (i === -1) this.selected[dim].push(value); else this.selected[dim].splice(i, 1);
                this.refresh();
            },
            clear() {
                this.q = '';
                this.selected = { circle: [], parody: [], event: [] };
                this.refresh();
            },
            activeCount() {
                return this.selected.circle.length + this.selected.parody.length + this.selected.event.length + (this.q ? 1 : 0);
            },

            buildQuery(extra = {}) {
                const p = new URLSearchParams();
                if (this.q) p.set('q', this.q);
                for (const dim of this.dims()) for (const v of this.selected[dim]) p.append(dim + '[]', v);
                for (const [k, v] of Object.entries(extra)) p.set(k, v);
                return p;
            },
            syncUrl() {
                const qs = this.buildQuery().toString();
                history.replaceState(null, '', qs ? ('/browse?' + qs) : '/browse');
            },

            async fetchJson(page) {
                const p = this.buildQuery({ page, format: 'json' });
                const res = await fetch('/browse?' + p.toString(), { headers: { Accept: 'application/json' } });
                return res.json();
            },
            async refresh() {
                this.page = 1;
                this.syncUrl();
                const id = ++this._reqId;
                this.loading = true;
                try {
                    const data = await this.fetchJson(1);
                    if (id !== this._reqId) return; // drop stale
                    this.facets = data.facets;
                    this.total = data.total;
                    this.hasMore = data.hasMore;
                    this.$refs.grid.innerHTML = data.html;
                } catch (e) { /* best-effort */ }
                finally { if (id === this._reqId) this.loading = false; }
            },
            async loadMore() {
                const next = this.page + 1;
                const id = ++this._reqId;
                this.loading = true;
                try {
                    const data = await this.fetchJson(next);
                    if (id !== this._reqId) return;
                    this.page = next;
                    this.hasMore = data.hasMore;
                    this.$refs.grid.insertAdjacentHTML('beforeend', data.html);
                } catch (e) { /* best-effort */ }
                finally { if (id === this._reqId) this.loading = false; }
            },
        }));
    });
    </script>
@endsection
```

- [ ] **Step 8: Run tests**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=BrowseSearchTest` → PASS (6 tests).
Then the full suite: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test` → all green.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/BrowseSearchController.php resources/views/browse/_cards.blade.php resources/views/browse/index.blade.php routes/web.php resources/views/components/nav.blade.php tests/Feature/Browse/BrowseSearchTest.php
git commit -m "$(cat <<'EOF'
feat: /browse search + faceted filter surface (live, deep-linkable)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Asset build + full-suite gate + browser render-verify gate

**Files:** none (verification only; file any defect as a fix before merge).

- [ ] **Step 1: Build the frontend assets**

Run: `npm run build`
Expected: Vite compiles with no errors. Confirm the design tokens are still bundled:
```bash
f=$(/bin/ls public/build/assets/app-*.css); grep -oc -- '--color-primary' "$f"
```
Expected: > 0.

- [ ] **Step 2: Run the full test suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: ALL green (archive, parsing, scanning, series, reader, browse incl. WorkSearch + BrowseSearch, auth). Output pristine.

- [ ] **Step 3: Browser render-verify gate**

Seed a realistic set (≥ ~40 not-missing works across several `circle`/`parody`/`event` values, some sharing values so counts > 1; a couple of `is_missing=true`), then `php artisan serve` (or the F2 `php -S` + env router if a library path is needed — not required here since `/browse` needs no zips), open `/browse`, and drive it in the browser. Verify, with **no console errors**, in both light and dark themes:

- Initial paint shows the server-rendered grid + correct result count; facet rail renders rows with counts.
- **Live search:** typing filters the grid after ~250 ms debounce; clearing restores; `title_raw`-only matches show.
- **Facets:** checking a value filters (OR within a facet, AND across); **counts update dynamically** and a facet's own selection does not zero its siblings; unchecking restores.
- **Show more / filter-within:** facets with > 15 values show "+ show more"; the within-box trims rows.
- **Load more:** appends the next page; hides when no more.
- **URL sync:** the address bar reflects `q` + facets; reloading the deep link reproduces the state (server-rendered) then hydrates.
- **Clear filters** resets q + facets.
- **Responsive:** below the `lg` breakpoint the rail collapses; the "Filters (n)" button opens it.
- **Empty state:** a no-match query shows "No works match" + Clear filters.

- [ ] **Step 4: Commit (only if the build emitted tracked changes)**

`public/build` is git-ignored, so normally nothing to commit. If `git status --porcelain` shows tracked changes, commit them with the trailer.

---

## Self-Review

**Spec coverage (F3a design doc):**
- `/browse` surface + nav entry, not-missing works → Task 2 (route, controller, nav link, `WorkSearch` base).
- Live debounced title search over `title` + `title_raw` (portable LIKE) → `WorkSearch::base()` (Task 1) + Alpine `q` watcher (Task 2 view).
- Faceted circle/parody/event, OR within / AND across → `WorkSearch::applyFacets()` (Task 1).
- **Dynamic counts excluding own dimension; selected-but-zero retained** → `WorkSearch::facets()` (Task 1) + tests `test_counts_are_dynamic_and_exclude_own_dimension`, `test_selected_value_kept_visible_when_zero`.
- Left-sidebar layout + responsive drawer; top-15 + show-more + filter-within → Task 2 view.
- HTML-over-the-wire cards (single-sourced `<x-work-card>`) + JSON `{total,page,hasMore,facets,html}` → `_cards.blade.php` + controller (Task 2).
- Load-more paging; URL sync + deep-link hydration → Alpine `loadMore()` / `refresh()` / `syncUrl()` + the embedded `initial` state (Task 2); browser-verified (Task 3).
- Empty state; result count; clear filters → Task 2 view.
- Tokens-only + accent-color on checkboxes → Task 2 view + Global Constraints; build-regression check → Task 3.

**Placeholder scan:** none — every step has complete code + exact commands/expected output.

**Type consistency:** `WorkSearch(q, circle, parody, event)`, `results(page, perPage): LengthAwarePaginator`, `facets(): array<dim, list<{value,count}>>`, `const DIMENSIONS` — used identically in Task 1 tests, the controller (Task 2), and the view's `initial`/Alpine (`facets[dim]` rows of `{value,count}`). Route name `browse.index`; literal `/browse` used in nav, form, fetch, and tests. JSON keys `total/page/hasMore/facets/html` match between controller, `BrowseSearchTest`, and the Alpine `refresh()/loadMore()`. `<x-work-card :work>` consumes eager-loaded `readingProgress` (set in `results()`).

**Interactive verification:** the Alpine live behaviors (debounce, fragment swap, dynamic-count re-render, show-more/filter-within, load-more, URL sync, responsive drawer) are browser-only → Task 3 gate, not PHPUnit (consistent with F2).

**Out of scope (later):** dynamic-vs-static is resolved (dynamic); search over circle/author text; sort; saved searches; numbered pagination; FULLTEXT; F3b (scan/maintenance) and F3c (series management).
