# Mangaka Live Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A live search bar on `/mangaka` that filters the grid by mangaka name ~250ms after the user stops typing, keeping the existing numbered pagination.

**Architecture:** `MangakaController::index` gains a `?q=` LIKE filter and a JSON format returning `{total, html, pagination}` rendered from shared Blade partials. A small inline Alpine component (`mangakaIndex`) debounces the input, fetches the JSON, and swaps the grid + pagination HTML. Spec: `docs/superpowers/specs/2026-07-02-wydoujin-mangaka-search-design.md`.

**Tech Stack:** Laravel 13 · Blade + Alpine.js (no other JS libs) · Pest 4 (feature + Playwright browser suite) · SQLite (tests) / MySQL (prod).

## Global Constraints

- **Local toolchain:** prefix every `php`/`artisan`/`composer` command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (the default `php` on this machine is broken).
- **Portable SQL only:** must behave identically on SQLite and MySQL. LIKE escaping uses the `ESCAPE '!'` convention (never backslash) — same as `app/Browse/WorkSearch.php:74-82`.
- **Design tokens only:** never inline a raw hex or px color — use `var(--…)` tokens (`--color-hairline`, `--radius-pill`, `--type-caption`, `--surface-page`, `--text-body`, …).
- **Coverage bar:** `app/` holds 100% line coverage. Every new controller line needs a feature test that executes it.
- **Commit style:** short imperative sentences, no `feat:`/`fix:` prefixes (see `git log`). End every commit message with the `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>` + `Claude-Session: https://claude.ai/code/session_018BZPUUfULj8Fr5eNNrxaDP` trailer lines.
- **Browser suite** is explicit-run only (`vendor/bin/pest tests/Browser`), not part of `php artisan test` or CI.

---

### Task 1: Backend — `?q=` filter + JSON format + shared partials

**Files:**
- Modify: `app/Http/Controllers/MangakaController.php` (the `index` method + imports)
- Create: `resources/views/mangaka/_cards.blade.php`
- Create: `resources/views/mangaka/_pagination.blade.php`
- Modify: `resources/views/mangaka/index.blade.php` (swap the inline loop/pagination for the partials)
- Test: `tests/Feature/Browse/MangakaTest.php` (append new tests)

**Interfaces:**
- Consumes: `Mangaka` model (`name`, `slug`), `Work::present()` scope, `x-collection-card` and `x-pagination` Blade components — all existing.
- Produces (Task 2 relies on these exactly):
  - `GET /mangaka?q=<term>` → filtered, paginated HTML page; view receives `$q` (string, `''` when absent) and `$mangaka` (paginator).
  - `GET /mangaka?format=json&q=<term>` (or `Accept: application/json`) → `{"total": int, "html": string, "pagination": string}`.
  - Partial `mangaka/_cards.blade.php` expects `$mangaka` (iterable of Mangaka rows with `rep_cover` + `works_count`).
  - Partial `mangaka/_pagination.blade.php` expects `$paginator`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Browse/MangakaTest.php`:

```php
test('index filters by q', function (): void {
    Mangaka::factory()->create(['name' => 'AlphaArtist']);
    Mangaka::factory()->create(['name' => 'BetaArtist']);

    $this->get('/mangaka?q=alpha')->assertOk()
        ->assertSee('AlphaArtist')
        ->assertDontSee('BetaArtist');
});

test('q treats LIKE metacharacters literally', function (): void {
    // % and _ must not act as wildcards; ! (the escape char) must match itself.
    Mangaka::factory()->create(['name' => 'Percent%Name']);
    Mangaka::factory()->create(['name' => 'PercentXName']);
    Mangaka::factory()->create(['name' => 'Bang!Name']);

    $this->get('/mangaka?q='.urlencode('Percent%'))->assertOk()
        ->assertSee('Percent%Name')
        ->assertDontSee('PercentXName');

    $this->get('/mangaka?q='.urlencode('Bang!N'))->assertOk()
        ->assertSee('Bang!Name');
});

test('pagination links carry q', function (): void {
    // 24 per page → 30 matches span 2 pages. / 1ページ24件。
    foreach (range(1, 30) as $i) {
        Mangaka::factory()->create(['name' => sprintf('Match %02d', $i)]);
    }
    Mangaka::factory()->create(['name' => 'ZOther']);

    // Page URLs are HTML-escaped in Blade, so & renders as &amp;.
    $this->get('/mangaka?q=Match')->assertOk()
        ->assertSee('q=Match&amp;page=2', false)
        ->assertDontSee('ZOther');
});

test('json endpoint returns total, cards html, and pagination html', function (): void {
    Mangaka::factory()->create(['name' => 'JsonArtist']);

    $res = $this->getJson('/mangaka')->assertOk()
        ->assertJsonStructure(['total', 'html', 'pagination']);
    expect($res->json('total'))->toBe(1);
    $this->assertStringContainsString('JsonArtist', $res->json('html'));
});

test('json respects q and format=json', function (): void {
    Mangaka::factory()->create(['name' => 'KeepMe']);
    Mangaka::factory()->create(['name' => 'DropMe']);

    $res = $this->get('/mangaka?format=json&q=Keep')->assertOk();
    expect($res->json('total'))->toBe(1);
    $this->assertStringContainsString('KeepMe', $res->json('html'));
    $this->assertStringNotContainsString('DropMe', $res->json('html'));
});
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Browse/MangakaTest.php`
Expected: the 5 new tests FAIL (`q` is ignored → BetaArtist still visible; JSON structure missing); the 4 pre-existing tests still PASS.

- [ ] **Step 3: Implement the controller + partials**

Replace the `index` method in `app/Http/Controllers/MangakaController.php` (add the `Request` import):

```php
<?php

namespace App\Http\Controllers;

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Http\Request;

/** Mangaka index + detail. / マンガ家一覧と詳細。 */
final class MangakaController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        // Representative cover via a correlated scalar subquery (portable; NOT a
        // limited eager-load, which would cap works across ALL rows).
        // 代表表紙は相関サブクエリで取得（移植性: limited eager-loadの罠を回避）。
        $mangaka = Mangaka::query()
            ->withCount(['works' => fn ($w) => $w->present()])
            ->addSelect(['rep_cover' => Work::select('cover_path')
                ->whereColumn('mangaka_id', 'mangaka.id')
                ->present()
                ->whereNotNull('cover_path')
                ->orderBy('sort_title')
                ->limit(1)])
            ->when($q !== '', function ($query) use ($q): void {
                // ESCAPE '!' (not backslash) keeps literal % / _ matching identical on
                // SQLite and MySQL — same convention as WorkSearch. / WorkSearchと同じ'!'エスケープ。
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->whereRaw("name LIKE ? ESCAPE '!'", [$term]);
            })
            ->orderBy('name')
            ->paginate(24)
            ->appends($q !== '' ? ['q' => $q] : []);

        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json([
                'total' => $mangaka->total(),
                'html' => view('mangaka._cards', ['mangaka' => $mangaka->items()])->render(),
                'pagination' => view('mangaka._pagination', ['paginator' => $mangaka])->render(),
            ]);
        }

        return view('mangaka.index', compact('mangaka', 'q'));
    }
```

(`show()` is untouched.)

Create `resources/views/mangaka/_cards.blade.php`:

```blade
@foreach ($mangaka as $artist)
    <x-collection-card href="/mangaka/{{ $artist->slug }}" :path="$artist->rep_cover" :title="$artist->name" :count="$artist->works_count" />
@endforeach
```

Create `resources/views/mangaka/_pagination.blade.php`:

```blade
{{-- One-line wrapper so the JSON path can render the pagination component
     (a @props component can't be rendered as a plain view). / JSON応答用ラッパー。 --}}
<x-pagination :paginator="$paginator" />
```

Update `resources/views/mangaka/index.blade.php` — replace the `@foreach` inside `<x-card-grid>` and the `<x-pagination …/>` line so both render through the new partials (full markup single-sourced for the JSON path):

```blade
            <x-card-grid>
                @include('mangaka._cards')
            </x-card-grid>

            @include('mangaka._pagination', ['paginator' => $mangaka])
```

Everything else in the view stays as-is for this task.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Browse/MangakaTest.php`
Expected: all 9 tests PASS.

- [ ] **Step 5: Run the full suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: PASS (no regressions — the empty-`q` path leaves existing pages byte-identical).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MangakaController.php resources/views/mangaka/ tests/Feature/Browse/MangakaTest.php
git commit -m "Add q filter and JSON format to the mangaka index

GET /mangaka now accepts ?q= (name LIKE, '!'-escaped like WorkSearch) and a
JSON format returning {total, html, pagination} from new shared partials,
groundwork for the live search bar. Pagination links carry q via appends().

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_018BZPUUfULj8Fr5eNNrxaDP"
```

---

### Task 2: Frontend — debounced Alpine live search on the index page

**Files:**
- Modify: `resources/views/mangaka/index.blade.php` (full rework below)
- Test: `tests/Feature/Browse/MangakaTest.php` (append DOM-wiring tests)

**Interfaces:**
- Consumes (from Task 1): view vars `$q` (string) + `$mangaka` (paginator); `GET /mangaka?format=json[&q=…]` → `{total, html, pagination}`; partials `mangaka/_cards` + `mangaka/_pagination`.
- Produces (Task 3 relies on these exactly): an `input[aria-label="Search mangaka"]`; a `mangakaIndex` Alpine component that debounces 250 ms, then swaps `x-ref="grid"` / `x-ref="pagination"` innerHTML and `history.replaceState`s the URL to `/mangaka?q=…`; a "No mangaka match." empty state with a "Clear search" button.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Browse/MangakaTest.php`:

```php
test('index renders live search wiring', function (): void {
    Mangaka::factory()->create(['name' => 'WiredArtist']);

    $this->get('/mangaka')->assertOk()
        ->assertSee('aria-label="Search mangaka"', false)
        ->assertSee('x-data="mangakaIndex', false)
        ->assertSee('x-ref="grid"', false)
        ->assertSee('x-ref="pagination"', false)
        ->assertSee('No mangaka match');   // empty-state element present in DOM (Alpine-hidden)
});

test('search input pre-fills from q for the no-js path', function (): void {
    Mangaka::factory()->create(['name' => 'PrefillArtist']);

    $this->get('/mangaka?q=Prefill')->assertOk()
        ->assertSee('value="Prefill"', false);
});
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Browse/MangakaTest.php`
Expected: the 2 new tests FAIL (no search input in the view yet); the 9 others PASS.

- [ ] **Step 3: Rework the view**

Replace `resources/views/mangaka/index.blade.php` entirely with:

```blade
@extends('layouts.app')

@section('content')
    <x-nav active="mangaka" />

    <main class="mx-auto w-full" x-data="mangakaIndex(@js(['q' => $q, 'total' => $mangaka->total()]))"
          style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <x-page-heading>Mangaka</x-page-heading>

        {{-- GET form = no-JS fallback; with JS the submit is intercepted and the grid
             live-refreshes instead. / GETフォームはJS無効時のフォールバック。 --}}
        <form action="/mangaka" method="get" @submit.prevent="refresh()" style="margin-bottom:var(--space-lg); max-width:320px;">
            <input type="search" name="q" value="{{ $q }}" x-model="q" placeholder="Search mangaka…" aria-label="Search mangaka"
                   class="w-full"
                   style="padding:9px 13px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
        </form>

        <div x-show="error" style="display:none; margin-bottom:var(--space-md); padding:var(--space-sm) var(--space-md); border-radius:var(--radius-sm); background:color-mix(in srgb, var(--color-error) 12%, transparent); color:var(--color-error); font:var(--type-caption);">
            Couldn't load results.
            <button type="button" @click="refresh()" style="background:none; border:none; padding:0; cursor:pointer; color:var(--color-error); text-decoration:underline; font:inherit;">Retry</button>
        </div>

        @if ($mangaka->isEmpty() && $q === '')
            {{-- Static empty-library message; hidden while a live search is active. --}}
            <p x-show="!q" style="font:var(--type-body); color:var(--text-muted);">No mangaka yet — run <code>wydoujin:scan</code>.</p>
        @endif

        {{-- Server-correct initial visibility (also right for no-JS); Alpine toggles after. --}}
        <div x-show="total === 0 && q"
             style="{{ ($mangaka->isEmpty() && $q !== '') ? '' : 'display:none;' }} padding:var(--space-xxl) 0; text-align:center;">
            <p style="font:var(--type-body); color:var(--text-muted);">No mangaka match.</p>
            <button type="button" @click="clear()"
                    style="margin-top:var(--space-sm); background:none; border:none; cursor:pointer; font:var(--type-caption); color:var(--text-link);">Clear search</button>
        </div>

        <x-card-grid x-ref="grid">
            @include('mangaka._cards')
        </x-card-grid>

        <div x-ref="pagination">
            @include('mangaka._pagination', ['paginator' => $mangaka])
        </div>
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('mangakaIndex', (initial) => ({
            q: initial.q ?? '',
            total: initial.total ?? 0,
            error: false,
            _debounce: null,
            _reqId: 0,

            init() {
                this.$watch('q', () => {
                    clearTimeout(this._debounce);
                    this._debounce = setTimeout(() => this.refresh(), 250);
                });
            },

            clear() { this.q = ''; },   // the watcher triggers refresh()

            syncUrl() {
                history.replaceState(null, '', this.q ? ('/mangaka?q=' + encodeURIComponent(this.q)) : '/mangaka');
            },

            async refresh() {
                this.error = false;
                this.syncUrl();
                const id = ++this._reqId;
                try {
                    const p = new URLSearchParams({ format: 'json' });
                    if (this.q) p.set('q', this.q);
                    const res = await fetch('/mangaka?' + p.toString(), { headers: { Accept: 'application/json' } });
                    const data = await res.json();
                    if (id !== this._reqId) return; // drop stale
                    this.total = data.total;
                    this.$refs.grid.innerHTML = data.html;
                    this.$refs.pagination.innerHTML = data.pagination;
                } catch (e) {
                    if (id === this._reqId) this.error = true;
                }
            },
        }));
    });
    </script>
@endsection
```

Notes for the implementer:
- `x-card-grid` merges extra attributes onto its `<div>` (see `resources/views/components/card-grid.blade.php`), so `x-ref="grid"` lands on the grid element.
- The grid keeps **no** `x-show` — with zero results it simply has no children.
- A query change always fetches page 1 (no `page` param is ever sent), per the spec.
- `value="{{ $q }}"` covers the no-JS render; Alpine's `x-model` re-applies the same value on init.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Browse/MangakaTest.php`
Expected: all 11 tests PASS — including the pre-existing `index empty state` and pagination tests, which must not regress.

- [ ] **Step 5: Run the full suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/views/mangaka/index.blade.php tests/Feature/Browse/MangakaTest.php
git commit -m "Add a debounced live search bar to the mangaka index

A small inline Alpine component (mangakaIndex) watches the input, waits
250ms after typing stops, then fetches the JSON format and swaps the grid
and pagination HTML in place, mirroring the /browse title search. The URL
is synced via history.replaceState and a plain GET form keeps no-JS working.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_018BZPUUfULj8Fr5eNNrxaDP"
```

---

### Task 3: Browser test — live behavior in real Chromium

**Files:**
- Create: `tests/Browser/MangakaSearchTest.php`

**Interfaces:**
- Consumes (from Task 2): `input[aria-label="Search mangaka"]`, the 250 ms debounce → grid swap, URL sync to `/mangaka?q=…`, the "No mangaka match." / "Clear search" empty state, the nav's `☾` theme toggle (existing).
- Produces: nothing downstream — this is the verification suite.

- [ ] **Step 1: Build assets (browser tests need the compiled bundle)**

Run: `npm run build`
Expected: Vite build succeeds (`public/build` refreshed).

- [ ] **Step 2: Write the browser tests**

Create `tests/Browser/MangakaSearchTest.php`:

```php
<?php

use App\Models\Mangaka;

// /mangaka live search: typing filters the grid after the debounce without a page
// load, clearing restores it, and the URL stays in sync.
// マンガ家検索：入力後デバウンスを経てグリッドがライブ更新される。

test('typing filters the mangaka grid live and clearing restores it', function (): void {
    Mangaka::factory()->create(['name' => 'AlphaArtist']);
    Mangaka::factory()->create(['name' => 'BetaArtist']);

    $page = visit('/mangaka');

    $page->assertSee('AlphaArtist')
        ->assertSee('BetaArtist');

    // fill() fires input events, so x-model + the debounce watcher run as if typed.
    $page->fill("input[aria-label='Search mangaka']", 'Alpha');

    // Debounce (250ms) + fetch, then the grid swaps — see/dont-see assertions retry.
    $page->assertDontSee('BetaArtist')
        ->assertSee('AlphaArtist');

    // URL synced via replaceState, no navigation. / replaceStateでURL同期。
    $page->assertScript('location.search', '?q=Alpha');

    $page->fill("input[aria-label='Search mangaka']", '');
    $page->assertSee('BetaArtist')
        ->assertNoJavaScriptErrors();
});

test('no-match state appears and the clear button restores the grid', function (): void {
    Mangaka::factory()->create(['name' => 'OnlyArtist']);

    $page = visit('/mangaka');

    $page->fill("input[aria-label='Search mangaka']", 'zzz');
    $page->assertSee('No mangaka match');

    $page->click('Clear search');
    $page->assertSee('OnlyArtist')
        ->assertNoJavaScriptErrors();
});

test('live search works in dark mode without errors', function (): void {
    Mangaka::factory()->create(['name' => 'DarkArtist']);
    Mangaka::factory()->create(['name' => 'DarkOther']);

    $page = visit('/mangaka');
    $page->click('☾'); // theme toggle → data-dark="true" on <html>

    $page->assertScript('document.documentElement.getAttribute("data-dark")', 'true');

    $page->fill("input[aria-label='Search mangaka']", 'DarkArtist');
    $page->assertDontSee('DarkOther')
        ->assertSee('DarkArtist')
        ->assertNoJavaScriptErrors();
});
```

- [ ] **Step 3: Run the browser suite for this file**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Browser/MangakaSearchTest.php`
Expected: 3 tests PASS. (If Chromium is missing: `npx playwright install chromium` once, then re-run.)

- [ ] **Step 4: Run the whole browser suite (guard the other pages)**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Browser`
Expected: PASS across all files.

- [ ] **Step 5: Commit**

```bash
git add tests/Browser/MangakaSearchTest.php
git commit -m "Add browser tests for the mangaka live search

Covers the debounced grid swap, URL sync via replaceState, the no-match
empty state with its clear button, and dark mode, asserting no JS errors.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_018BZPUUfULj8Fr5eNNrxaDP"
```
