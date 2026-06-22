# wydoujin — Browse Foundation (F1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the polished, read-only browse foundation (spec F1): a token-themed app shell with a light/dark toggle, the reusable Blade+Alpine components, and five pages — Home, Mangaka index, Mangaka detail, Series, Work detail — all behind the existing password gate, HTTP-smoke-tested.

**Architecture:** Re-theme `layouts/app.blade.php` onto the design-system tokens (light default; an Alpine toggle flips `data-dark` on `<html>`, persisted to `localStorage`, applied pre-paint by a head script). Translate the design-system reference components into **Blade anonymous components + Alpine** (React-free). Read-only controllers (`BrowseController`, `MangakaController`, `SeriesController`, `WorkController`) feed Blade views that compose those components over the existing models. Covers come from the existing `/covers/{hash}.webp` route.

**Tech Stack:** Laravel 13 Blade, Tailwind v4, Alpine.js (all already wired); design tokens live via `resources/design-system/styles.css`. No new runtime dependencies.

**Spec:** `docs/superpowers/specs/2026-06-22-wydoujin-browse-foundation-design.md`. Parent: `docs/superpowers/specs/2026-06-21-wydoujin-design.md` (§9, §10, §13).

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+ (local dev 8.5). No `declare(strict_types=1)` in this codebase.
- **Broken local toolchain:** prefix EVERY php command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5.4). Env doesn't persist between Bash calls — repeat it. Run tests via `php artisan test`. Node/npm are on the normal PATH (no prefix needed).
- **Avoid `cd` in compound bash;** use absolute paths / `git -C`.
- **Commit trailer:** every commit ends with a blank line then exactly:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **PHP style:** single quotes unless interpolation; native typed properties; EN+JA docblocks where a class/method needs one, short; `final` controllers.
- **Design tokens are mandatory (§13).** Never inline a raw hex or a design-language size — reference a token. Use **semantic aliases** in components/pages so dark mode is automatic: `--surface-page`, `--surface-card`, `--surface-alt`, `--text-heading`, `--text-body`, `--text-muted`, `--text-link`, `--color-primary`, `--border-card`/`--color-hairline`, `--focus-ring`, type via `font: var(--type-*)`, radii via `var(--radius-*)`, spacing via `var(--space-*)`.
- **Styling mechanism (locked):** design-language values (color, type, radius, border, spacing) → inline `style="…: var(--token)"`, or Tailwind **arbitrary** utilities that reference a token (e.g. `hover:[color:var(--color-on-dark)]`) when a hover/state is needed. Tailwind plain utilities are only for **structural** layout that carries no design value (`flex`, `grid`, `items-center`, `justify-between`, `hidden`, `w-full`, `aspect-[3/4]`, `object-cover`, `truncate`, `line-clamp-2`). Layout geometry with no token (grid min column width, aspect ratios, `--container-*` max-widths) may use raw values.
- **House rules (§13):** one blue accent (`--color-primary`); weight ladder 300/400/600/700 (no 500); elevation is a **1px hairline ring**, never a shadow; press → `scale(var(--press-scale))`; 17px body (`--type-body`). The **NavBar is true-black in both themes** (`--color-black` / `--color-on-dark` / `--color-body-muted`) — do not theme it with semantic aliases.
- **React-free:** components are Blade anonymous components in `resources/views/components/`; the vendored `.jsx` files are visual/interaction reference only. Interactivity is Alpine.
- **Views link via literal URL paths** (`/`, `/mangaka`, `/mangaka/{slug}`, `/series/{id}`, `/work/{id}`, `/work/{id}/page/1`), NOT `route()` names — this decouples views from route-registration order across tasks. Routes are still given names in `web.php`.
- **Missing works hidden (§7):** every browse query filters `where('is_missing', false)`.
- **DB portability:** Eloquent only (MySQL prod / SQLite dev+test); no MySQL-only raw SQL. Feature tests use `RefreshDatabase` on in-memory SQLite and `$this->withoutVite()` (no built assets needed).
- **Auth gate is global** (`RequirePassword` on the `web` group, exempts `login`/`health`); new routes are auto-gated — never add per-route auth. Default test env has `app.password` null (routes open).
- **Workflow:** TDD, DRY, YAGNI, bite-sized commits.

## Scope Decisions (locked)

1. **F1 is read-only browse.** The reader (F2) and search/filters/maintenance + manual series merge (F3) are separate plans, out of scope.
2. **Reader seam:** the Work-detail "Read" CTA links to `/work/{work}/page/1` (existing endpoint — no dead link). F2 will repoint it.
3. **Series cover is derived** (the first non-missing work by `sort_title` with a `cover_path`); `series.cover_work_id` stays null this plan.
4. **Pagination:** Mangaka index only (24/page). Mangaka-detail and Series lists are unpaginated (small sets) this plan.
5. **Visual polish verification** (rendering home/work in light & dark) is the controller's final gate before merge, per the spec; Task 6 guarantees the asset build + suite are green.

## File Structure

- `resources/views/layouts/app.blade.php` — **modify** (re-theme; theme head-script). (T1)
- `resources/css/app.css` — **modify** (add `[x-cloak]{display:none}`). (T1)
- `resources/views/components/nav.blade.php` — **create** `x-nav` (+ Alpine theme toggle). (T1)
- `resources/views/components/button.blade.php` — **create** `x-button`. (T1)
- `resources/views/auth/login.blade.php` — **modify** (re-theme onto tokens + `x-button`). (T1)
- `resources/views/components/cover.blade.php` — **create** `x-cover`. (T2)
- `resources/views/components/work-card.blade.php` — **create** `x-work-card`. (T2)
- `resources/views/components/badge.blade.php` — **create** `x-badge`. (T2)
- `resources/views/components/section-heading.blade.php` — **create** `x-section-heading`. (T2)
- `app/Http/Controllers/BrowseController.php` + `resources/views/home.blade.php` + route — **create** (T3)
- `app/Http/Controllers/MangakaController.php` + `resources/views/mangaka/{index,show}.blade.php` + routes — **create** (T4)
- `app/Http/Controllers/SeriesController.php` + `app/Http/Controllers/WorkController.php` + `resources/views/series/show.blade.php` + `resources/views/work/show.blade.php` + routes — **create** (T5)
- Tests under `tests/Feature/Browse/`. Removals/edits to scaffold tests in T3.

**Reference — existing shapes (verbatim):**
- Models (`$guarded = []`): `Mangaka` (`name`, `slug`; `works()`, `series()`), `Series` (`name`, `sort_name`, `is_auto`, `cover_work_id`, `mangaka_id`; `mangaka()`, `works()`), `Work` (`title`, `title_raw`, `sort_title`, `event`, `circle`, `author`, `parody`, `language`, `flags`(array), `page_count`(int), `cover_path`, `content_hash`, `is_missing`(bool), `series_id`, `mangaka_id`; `mangaka()`, `series()`, `readingProgress()`), `ReadingProgress` (`work_id` unique, `current_page`(int), `is_completed`(bool), `started_at`/`last_read_at`/`completed_at`(datetime); `work()`).
- Factories: `Mangaka::factory()` (name + unique slug), `Series::factory()`, `Work::factory()` (sets content_hash/relative_path/page_count/last_seen_at; NOT entries/cover_path/series_id). `ReadingProgress::create([...])`.
- Cover URL: a work's `cover_path` is `covers/<hash>.webp`; `url($cover_path)` → `/covers/<hash>.webp` (served by the cover route).
- Tokens: see `resources/design-system/ds-tokens/*` — colors (semantic aliases + `[data-dark="true"]` remap), typography (`--type-*`, weights), shape (`--radius-*`, `--press-scale`), spacing (`--space-*`, `--container-*`, `--grid-gutter`). Nav-only: `--color-black`, `--color-on-dark`, `--color-body-muted`, `--font-display`.

---

## Task 1: App shell — themed layout, theme toggle, `x-nav`, `x-button`, login

**Files:**
- Modify: `resources/views/layouts/app.blade.php`, `resources/css/app.css`, `resources/views/auth/login.blade.php`
- Create: `resources/views/components/nav.blade.php`, `resources/views/components/button.blade.php`
- Test: `tests/Feature/Browse/ShellTest.php`

**Interfaces:**
- Produces: `<x-nav :active="'home'|'mangaka'|null" />` (true-black bar: brand → `/`, links Home `/` + Mangaka `/mangaka`, Alpine theme toggle). `<x-button variant="primary|secondary" href="…"? type="…"? class="…"?>slot</x-button>` (pill; renders `<a>` when `href` given, else `<button>`). Layout exposes a working `data-dark` toggle persisted at `localStorage['wyd-theme']`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Browse/ShellTest.php`:
```php
<?php

namespace Tests\Feature\Browse;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ShellTest extends TestCase
{
    public function test_login_renders_on_themed_shell(): void
    {
        $this->withoutVite()
            ->get('/login')
            ->assertOk()
            ->assertSee('wydoujin', false);
    }

    public function test_button_renders_link_or_button_by_variant(): void
    {
        $link = Blade::render('<x-button variant="primary" href="/x">Go</x-button>');
        $this->assertStringContainsString('<a', $link);
        $this->assertStringContainsString('href="/x"', $link);
        $this->assertStringContainsString('var(--color-primary)', $link);
        $this->assertStringContainsString('var(--radius-pill)', $link);

        $btn = Blade::render('<x-button>Save</x-button>');
        $this->assertStringContainsString('<button', $btn);
    }

    public function test_nav_has_brand_links_and_theme_toggle(): void
    {
        $nav = Blade::render('<x-nav active="home" />');
        $this->assertStringContainsString('wydoujin', $nav);
        $this->assertStringContainsString('href="/"', $nav);
        $this->assertStringContainsString('href="/mangaka"', $nav);
        $this->assertStringContainsString('var(--color-black)', $nav);   // true-black bar
        $this->assertStringContainsString("localStorage.setItem('wyd-theme'", $nav); // toggle
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ShellTest`
Expected: FAIL — `x-button` / `x-nav` components not found (and the login button assertion).

- [ ] **Step 3: Re-theme the layout + add x-cloak**

Replace `resources/views/layouts/app.blade.php` with:
```blade
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'wydoujin' }}</title>
    {{-- Apply saved theme before first paint (no flash). / 描画前に保存テーマを適用。 --}}
    <script>
        try { if (localStorage.getItem('wyd-theme') === 'dark') document.documentElement.setAttribute('data-dark', 'true'); } catch (e) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full" style="background: var(--surface-page); color: var(--text-body);">
    @yield('content')
</body>
</html>
```

Append to `resources/css/app.css`:
```css

/* Hide Alpine-cloaked elements until Alpine initializes. */
[x-cloak] { display: none !important; }
```

- [ ] **Step 4: Create `x-button`**

`resources/views/components/button.blade.php`:
```blade
@props(['variant' => 'primary', 'href' => null, 'type' => 'button'])

@php
    $skin = $variant === 'secondary'
        ? 'background: var(--color-pearl); color: var(--color-ink-muted-80); border: 1px solid var(--color-hairline);'
        : 'background: var(--color-primary); color: var(--color-on-primary); border: 1px solid transparent;';
    $base = 'display:inline-flex;align-items:center;justify-content:center;gap:var(--space-xs);'
        .'padding:11px 22px;border-radius:var(--radius-pill);font:var(--weight-regular) 16px/1 var(--font-text);'
        .'letter-spacing:-0.01em;white-space:nowrap;cursor:pointer;text-decoration:none;'
        .'transition:transform .18s cubic-bezier(.4,0,.2,1), filter .18s ease;';
@endphp

@if ($href)
    <a href="{{ $href }}"
       {{ $attributes->merge(['style' => $base.$skin, 'class' => 'active:[transform:scale(var(--press-scale))] hover:[filter:brightness(1.06)]']) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}"
        {{ $attributes->merge(['style' => $base.$skin, 'class' => 'active:[transform:scale(var(--press-scale))] hover:[filter:brightness(1.06)]']) }}>
        {{ $slot }}
    </button>
@endif
```

- [ ] **Step 5: Create `x-nav`**

`resources/views/components/nav.blade.php`:
```blade
@props(['active' => null])

<nav class="flex items-center" style="height:44px; background:var(--color-black); gap:var(--space-xl); padding:0 var(--space-xl);">
    <a href="/" class="no-underline" style="font:var(--weight-semibold) 18px/1 var(--font-display); letter-spacing:-0.2px; color:var(--color-on-dark);">wydoujin</a>

    <div class="flex items-center" style="gap:var(--space-lg); flex:1;">
        <a href="/" class="no-underline {{ $active === 'home' ? '[color:var(--color-on-dark)]' : '[color:var(--color-body-muted)]' }} hover:[color:var(--color-on-dark)]" style="font:var(--type-nav);">Home</a>
        <a href="/mangaka" class="no-underline {{ $active === 'mangaka' ? '[color:var(--color-on-dark)]' : '[color:var(--color-body-muted)]' }} hover:[color:var(--color-on-dark)]" style="font:var(--type-nav);">Mangaka</a>
    </div>

    <button type="button"
        x-data="{ dark: document.documentElement.getAttribute('data-dark') === 'true' }"
        @click="dark = !dark; dark ? document.documentElement.setAttribute('data-dark','true') : document.documentElement.removeAttribute('data-dark'); localStorage.setItem('wyd-theme', dark ? 'dark' : 'light')"
        :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'"
        style="background:none; border:none; cursor:pointer; color:var(--color-on-dark); font-size:16px; line-height:1;">
        <span x-text="dark ? '☀' : '☾'">☾</span>
    </button>
</nav>
```

- [ ] **Step 6: Re-theme the login form**

Replace `resources/views/auth/login.blade.php` with:
```blade
@extends('layouts.app')

@section('content')
    <div class="flex min-h-screen items-center justify-center" style="padding:var(--space-xl);">
        <form method="POST" action="/login" class="w-full" style="max-width:320px; display:flex; flex-direction:column; gap:var(--space-md);">
            @csrf
            <h1 style="font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">wydoujin</h1>
            <input type="password" name="password" autofocus placeholder="Password"
                   class="w-full"
                   style="background:var(--surface-card); color:var(--text-body); border:1px solid var(--color-hairline); border-radius:var(--radius-md); padding:11px var(--space-md); font:var(--type-body);">
            @error('password')
                <p style="color:#b8453e; font:var(--type-caption);">{{ $message }}</p>
            @enderror
            <x-button type="submit" class="w-full">Enter</x-button>
        </form>
    </div>
@endsection
```

- [ ] **Step 7: Run tests + build sanity**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ShellTest`
Expected: PASS (3 tests). Then the full suite: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test` — all green (no other tests touch these files yet).

- [ ] **Step 8: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/css/app.css resources/views/components/nav.blade.php resources/views/components/button.blade.php resources/views/auth/login.blade.php tests/Feature/Browse/ShellTest.php
git commit -m "$(cat <<'EOF'
feat: themed app shell — token layout, theme toggle, x-nav, x-button, login

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Content components — `x-cover`, `x-work-card`, `x-badge`, `x-section-heading`

**Files:**
- Create: `resources/views/components/cover.blade.php`, `resources/views/components/work-card.blade.php`, `resources/views/components/badge.blade.php`, `resources/views/components/section-heading.blade.php`
- Test: `tests/Feature/Browse/ComponentsTest.php`

**Interfaces:**
- Consumes: a `Work` model (for `x-work-card`).
- Produces: `<x-cover :path="$cover_path" :title="$title" />` (img → `url($path)` when present, else a CSS placeholder showing the title; `aspect-[3/4]`). `<x-work-card :work="$work" />` (cover + title + circle subtitle + progress bar when the work's `readingProgress` has `current_page > 0`; whole card links to `/work/{id}`). `<x-badge>slot</x-badge>` (soft blue-tinted pill). `<x-section-heading>slot</x-section-heading>`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Browse/ComponentsTest.php`:
```php
<?php

namespace Tests\Feature\Browse;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cover_renders_image_when_path_present(): void
    {
        $html = Blade::render('<x-cover path="covers/abc.webp" title="My Title" />');
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('/covers/abc.webp', $html);
    }

    public function test_cover_renders_placeholder_when_path_null(): void
    {
        $html = Blade::render('<x-cover :path="null" title="Placeholder Me" />');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Placeholder Me', $html);
    }

    public function test_work_card_links_to_work_and_shows_progress(): void
    {
        $work = Work::factory()->for(Mangaka::factory())->create([
            'title' => 'カードの題', 'circle' => 'サークルX', 'page_count' => 20, 'cover_path' => 'covers/h.webp',
        ]);
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 5]);
        $work->load('readingProgress');

        $html = Blade::render('<x-work-card :work="$work" />', ['work' => $work]);
        $this->assertStringContainsString('href="/work/'.$work->id.'"', $html);
        $this->assertStringContainsString('カードの題', $html);
        $this->assertStringContainsString('サークルX', $html);
        $this->assertStringContainsString('5', $html); // progress count
    }

    public function test_badge_and_heading_render_slot(): void
    {
        $this->assertStringContainsString('オリジナル', Blade::render('<x-badge>オリジナル</x-badge>'));
        $this->assertStringContainsString('var(--color-primary)', Blade::render('<x-badge>x</x-badge>'));
        $this->assertStringContainsString('Recently Added', Blade::render('<x-section-heading>Recently Added</x-section-heading>'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ComponentsTest`
Expected: FAIL — the four components are not found.

- [ ] **Step 3: Create `x-cover`**

`resources/views/components/cover.blade.php`:
```blade
@props(['path' => null, 'title' => ''])

<div class="w-full aspect-[3/4] overflow-hidden" style="border-radius:var(--radius-md); border:1px solid var(--color-hairline); background:var(--surface-alt);">
    @if ($path)
        <img src="{{ url($path) }}" alt="{{ $title }}" loading="lazy" class="w-full h-full object-cover">
    @else
        <div class="w-full h-full flex items-center justify-center text-center" style="padding:var(--space-md);">
            <span style="font:var(--type-caption); color:var(--text-muted);">{{ $title }}</span>
        </div>
    @endif
</div>
```

- [ ] **Step 4: Create `x-work-card`**

`resources/views/components/work-card.blade.php`:
```blade
@props(['work'])

@php
    $progress = $work->readingProgress;
    $pages = max(1, (int) $work->page_count);
    $pct = $progress ? min(100, (int) round($progress->current_page / $pages * 100)) : 0;
@endphp

<a href="/work/{{ $work->id }}" class="no-underline block group">
    <x-cover :path="$work->cover_path" :title="$work->title" />

    <div style="margin-top:var(--space-xs);">
        <div class="truncate" style="font:var(--type-caption-strong); color:var(--text-heading);">{{ $work->title }}</div>
        @if ($work->circle)
            <div class="truncate" style="font:var(--type-fine); color:var(--text-muted);">{{ $work->circle }}</div>
        @endif

        @if ($progress && $progress->current_page > 0)
            @if ($progress->is_completed)
                <div style="margin-top:4px; font:var(--type-fine); color:var(--text-link);">Completed</div>
            @else
                <div style="margin-top:6px; height:3px; border-radius:var(--radius-pill); background:var(--color-hairline);">
                    <div style="height:100%; width:{{ $pct }}%; border-radius:var(--radius-pill); background:var(--color-primary);"></div>
                </div>
                <div style="margin-top:4px; font:var(--type-fine); color:var(--text-muted);">{{ $progress->current_page }}/{{ $work->page_count }}</div>
            @endif
        @endif
    </div>
</a>
```

- [ ] **Step 5: Create `x-badge` and `x-section-heading`**

`resources/views/components/badge.blade.php`:
```blade
{{-- Soft blue-tinted taxonomy pill (parody/event/flags). One accent only. --}}
<span class="inline-flex items-center" {{ $attributes->merge(['style' => 'gap:5px; height:22px; padding:0 10px; border-radius:var(--radius-pill); font:var(--weight-semibold) 12px/1 var(--font-text); letter-spacing:0.1px; white-space:nowrap; color:var(--color-primary); background:color-mix(in srgb, var(--color-primary) 12%, transparent);']) }}>
    {{ $slot }}
</span>
```

`resources/views/components/section-heading.blade.php`:
```blade
<h2 {{ $attributes->merge(['style' => 'font:var(--type-tagline); color:var(--text-heading); letter-spacing:var(--tracking-tagline); margin:0 0 var(--space-md);']) }}>
    {{ $slot }}
</h2>
```

- [ ] **Step 6: Run tests**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ComponentsTest`
Expected: PASS (4 tests). Then full suite — green.

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/cover.blade.php resources/views/components/work-card.blade.php resources/views/components/badge.blade.php resources/views/components/section-heading.blade.php tests/Feature/Browse/ComponentsTest.php
git commit -m "$(cat <<'EOF'
feat: browse content components — x-cover, x-work-card, x-badge, x-section-heading

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Home page (`/`)

**Files:**
- Create: `app/Http/Controllers/BrowseController.php`, `resources/views/home.blade.php`, `tests/Feature/Browse/HomeTest.php`
- Modify: `routes/web.php` (point `/` at the controller)
- Modify: `tests/Feature/AuthGateTest.php` (add `RefreshDatabase` — `/` now queries the DB)
- Delete: `tests/Feature/ExampleTest.php`, `tests/Feature/HomePageTest.php`, `resources/views/welcome.blade.php` (superseded; both example/home tests only GET `/`)

**Interfaces:**
- Consumes: `x-nav`, `x-section-heading`, `x-work-card`; models `Work`, `ReadingProgress`.
- Produces: `BrowseController@home` → `home` view. Route `GET /` name `home`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Browse/HomeTest.php`:
```php
<?php

namespace Tests\Feature\Browse;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_empty_library_shows_scan_prompt(): void
    {
        $this->get('/')->assertOk()->assertSee('wydoujin:scan');
    }

    public function test_continue_reading_shows_only_in_progress_newest_first(): void
    {
        $m = Mangaka::factory()->create();
        $inProgressOld = Work::factory()->for($m)->create(['title' => 'OldProgress', 'page_count' => 10]);
        $inProgressNew = Work::factory()->for($m)->create(['title' => 'NewProgress', 'page_count' => 10]);
        $completed = Work::factory()->for($m)->create(['title' => 'DoneWork', 'page_count' => 10]);
        $notStarted = Work::factory()->for($m)->create(['title' => 'FreshWork', 'page_count' => 10]);

        ReadingProgress::create(['work_id' => $inProgressOld->id, 'current_page' => 3, 'last_read_at' => now()->subDay()]);
        ReadingProgress::create(['work_id' => $inProgressNew->id, 'current_page' => 4, 'last_read_at' => now()]);
        ReadingProgress::create(['work_id' => $completed->id, 'current_page' => 10, 'is_completed' => true, 'last_read_at' => now()]);

        $content = $this->get('/')->assertOk()->assertSee('Continue Reading')->getContent();

        // Scope to the Continue Reading section (it precedes Recently Added in the HTML).
        // completed/never-started works legitimately appear in Recently Added, so a
        // whole-page assertDontSee would be wrong — assert against the section only.
        $start = strpos($content, 'Continue Reading');
        $cr = substr($content, $start, strpos($content, 'Recently Added') - $start);

        $this->assertStringContainsString('NewProgress', $cr);
        $this->assertStringContainsString('OldProgress', $cr);
        $this->assertStringNotContainsString('DoneWork', $cr);   // completed excluded
        $this->assertStringNotContainsString('FreshWork', $cr);  // never-started excluded
        $this->assertTrue(strpos($cr, 'NewProgress') < strpos($cr, 'OldProgress')); // newest first
    }

    public function test_recently_added_lists_works_and_hides_missing(): void
    {
        $m = Mangaka::factory()->create();
        Work::factory()->for($m)->create(['title' => 'ShownWork']);
        Work::factory()->for($m)->create(['title' => 'GhostWork', 'is_missing' => true]);

        $this->get('/')->assertOk()
            ->assertSee('Recently Added')
            ->assertSee('ShownWork')
            ->assertDontSee('GhostWork');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=HomeTest`
Expected: FAIL — `/` still returns the `welcome` closure; `Continue Reading` / `wydoujin:scan` not present.

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/BrowseController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\ReadingProgress;
use App\Models\Work;

/** The home dashboard: Continue Reading + Recently Added. / ホーム。 */
final class BrowseController extends Controller
{
    public function home()
    {
        $continueReading = ReadingProgress::query()
            ->where('current_page', '>', 0)
            ->where('is_completed', false)
            ->whereHas('work', fn ($q) => $q->where('is_missing', false))
            ->with('work.mangaka', 'work.readingProgress')
            ->orderByDesc('last_read_at')
            ->limit(12)
            ->get()
            ->map(fn (ReadingProgress $p) => $p->work);

        $recentlyAdded = Work::query()
            ->where('is_missing', false)
            ->with('mangaka', 'readingProgress')
            ->latest()
            ->limit(12)
            ->get();

        $hasAnyWork = Work::where('is_missing', false)->exists();

        return view('home', compact('continueReading', 'recentlyAdded', 'hasAnyWork'));
    }
}
```

- [ ] **Step 4: Create the view**

`resources/views/home.blade.php`:
```blade
@extends('layouts.app')

@section('content')
    <x-nav active="home" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        @if (! $hasAnyWork)
            <div class="text-center" style="padding:var(--space-section) 0;">
                <h1 style="font:var(--type-lead); color:var(--text-heading);">No works yet</h1>
                <p style="margin-top:var(--space-sm); font:var(--type-body); color:var(--text-muted);">
                    Run <code>wydoujin:scan</code> to index your library.
                </p>
            </div>
        @else
            @if ($continueReading->isNotEmpty())
                <section style="margin-bottom:var(--space-xxl);">
                    <x-section-heading>Continue Reading</x-section-heading>
                    <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                        @foreach ($continueReading as $work)
                            <x-work-card :work="$work" />
                        @endforeach
                    </div>
                </section>
            @endif

            <section>
                <x-section-heading>Recently Added</x-section-heading>
                <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                    @foreach ($recentlyAdded as $work)
                        <x-work-card :work="$work" />
                    @endforeach
                </div>
            </section>
        @endif
    </main>
@endsection
```

- [ ] **Step 5: Point `/` at the controller**

In `routes/web.php`: add `use App\Http\Controllers\BrowseController;` near the other controller imports, and replace
```php
Route::get('/', function () {
    return view('welcome');
});
```
with
```php
Route::get('/', [BrowseController::class, 'home'])->name('home');
```

- [ ] **Step 6: Fix the scaffold tests that GET `/`**

`/` now queries the DB, so the old non-migrating tests break.
- In `tests/Feature/AuthGateTest.php`, add `use Illuminate\Foundation\Testing\RefreshDatabase;` and `use RefreshDatabase;` inside the class (after the opening brace) so its `get('/')` calls hit a migrated (empty) DB.
- Delete the superseded scaffold tests and view:
```bash
git rm tests/Feature/ExampleTest.php tests/Feature/HomePageTest.php resources/views/welcome.blade.php
```

- [ ] **Step 7: Run tests**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=HomeTest` → PASS (3 tests).
Then `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test` → full suite green (AuthGateTest now migrates; Example/HomePage removed).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/BrowseController.php resources/views/home.blade.php routes/web.php tests/Feature/Browse/HomeTest.php tests/Feature/AuthGateTest.php
git commit -m "$(cat <<'EOF'
feat: home page — Continue Reading + Recently Added

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Mangaka index + detail (`/mangaka`, `/mangaka/{slug}`)

**Files:**
- Create: `app/Http/Controllers/MangakaController.php`, `resources/views/mangaka/index.blade.php`, `resources/views/mangaka/show.blade.php`, `tests/Feature/Browse/MangakaTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `x-nav`, `x-section-heading`, `x-work-card`, `x-cover`; models `Mangaka`, `Series`, `Work`.
- Produces: `MangakaController@index` (paginated mangaka, each with `works_count` + a derived cover) and `@show` (series + standalone works). Routes `GET /mangaka` name `mangaka.index`, `GET /mangaka/{mangaka:slug}` name `mangaka.show`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Browse/MangakaTest.php`:
```php
<?php

namespace Tests\Feature\Browse;

use App\Models\Mangaka;
use App\Models\Series;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MangakaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_index_lists_mangaka_with_work_counts(): void
    {
        $m = Mangaka::factory()->create(['name' => 'Z.A.P.']);
        Work::factory()->for($m)->create();
        Work::factory()->for($m)->create();

        $this->get('/mangaka')->assertOk()
            ->assertSee('Z.A.P.')
            ->assertSee('href="/mangaka/'.$m->slug.'"', false);
    }

    public function test_index_empty_state(): void
    {
        $this->get('/mangaka')->assertOk()->assertSee('No mangaka');
    }

    public function test_show_separates_series_and_standalone_works(): void
    {
        $m = Mangaka::factory()->create(['name' => 'CircleA']);
        $series = Series::factory()->for($m)->create(['name' => 'MyShelf']);
        Work::factory()->for($m)->create(['title' => 'SeriesVol1', 'series_id' => $series->id, 'sort_title' => 'SeriesVol1']);
        Work::factory()->for($m)->create(['title' => 'LoneWork', 'series_id' => null, 'sort_title' => 'LoneWork']);

        $this->get('/mangaka/'.$m->slug)->assertOk()
            ->assertSee('MyShelf')
            ->assertSee('href="/series/'.$series->id.'"', false)
            ->assertSee('LoneWork')
            ->assertSee('href="/work/'.Work::where('title', 'LoneWork')->first()->id.'"', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=MangakaTest`
Expected: FAIL — `/mangaka` route is not defined (404).

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/MangakaController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Mangaka;
use App\Models\Work;

/** Mangaka index + detail. / マンガ家一覧と詳細。 */
final class MangakaController extends Controller
{
    public function index()
    {
        // Representative cover via a correlated scalar subquery (portable; NOT a
        // limited eager-load, which would cap works across ALL rows).
        // 代表表紙は相関サブクエリで取得（移植性: limited eager-loadの罠を回避）。
        $mangaka = Mangaka::query()
            ->withCount(['works' => fn ($q) => $q->where('is_missing', false)])
            ->addSelect(['rep_cover' => Work::select('cover_path')
                ->whereColumn('mangaka_id', 'mangaka.id')
                ->where('is_missing', false)
                ->whereNotNull('cover_path')
                ->orderBy('sort_title')
                ->limit(1)])
            ->orderBy('name')
            ->paginate(24);

        return view('mangaka.index', compact('mangaka'));
    }

    public function show(Mangaka $mangaka)
    {
        $series = $mangaka->series()
            ->with(['works' => fn ($q) => $q->where('is_missing', false)->orderBy('sort_title')])
            ->orderBy('name')
            ->get();

        $standalone = $mangaka->works()
            ->where('is_missing', false)
            ->whereNull('series_id')
            ->with('readingProgress')
            ->orderBy('sort_title')
            ->get();

        return view('mangaka.show', compact('mangaka', 'series', 'standalone'));
    }
}
```

- [ ] **Step 4: Create the views**

`resources/views/mangaka/index.blade.php`:
```blade
@extends('layouts.app')

@section('content')
    <x-nav active="mangaka" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <x-section-heading>Mangaka</x-section-heading>

        @if ($mangaka->isEmpty())
            <p style="font:var(--type-body); color:var(--text-muted);">No mangaka yet — run <code>wydoujin:scan</code>.</p>
        @else
            <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                @foreach ($mangaka as $artist)
                    <a href="/mangaka/{{ $artist->slug }}" class="no-underline block">
                        <x-cover :path="$artist->rep_cover" :title="$artist->name" />
                        <div class="truncate" style="margin-top:var(--space-xs); font:var(--type-caption-strong); color:var(--text-heading);">{{ $artist->name }}</div>
                        <div style="font:var(--type-fine); color:var(--text-muted);">{{ $artist->works_count }} {{ \Illuminate\Support\Str::plural('work', $artist->works_count) }}</div>
                    </a>
                @endforeach
            </div>

            @if ($mangaka->hasPages())
                <nav class="flex items-center justify-center" style="gap:var(--space-md); margin-top:var(--space-xl);">
                    @if ($mangaka->onFirstPage())
                        <span style="font:var(--type-caption); color:var(--text-muted);">Prev</span>
                    @else
                        <a href="{{ $mangaka->previousPageUrl() }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">Prev</a>
                    @endif
                    <span style="font:var(--type-caption); color:var(--text-muted);">Page {{ $mangaka->currentPage() }} of {{ $mangaka->lastPage() }}</span>
                    @if ($mangaka->hasMorePages())
                        <a href="{{ $mangaka->nextPageUrl() }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">Next</a>
                    @else
                        <span style="font:var(--type-caption); color:var(--text-muted);">Next</span>
                    @endif
                </nav>
            @endif
        @endif
    </main>
@endsection
```

`resources/views/mangaka/show.blade.php`:
```blade
@extends('layouts.app')

@section('content')
    <x-nav active="mangaka" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <h1 style="font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md); margin-bottom:var(--space-xl);">{{ $mangaka->name }}</h1>

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
    </main>
@endsection
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`: add `use App\Http\Controllers\MangakaController;`, then append:
```php
Route::get('/mangaka', [MangakaController::class, 'index'])->name('mangaka.index');
Route::get('/mangaka/{mangaka:slug}', [MangakaController::class, 'show'])->name('mangaka.show');
```

- [ ] **Step 6: Run tests**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=MangakaTest` → PASS (3 tests). Then full suite — green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/MangakaController.php resources/views/mangaka/ routes/web.php tests/Feature/Browse/MangakaTest.php
git commit -m "$(cat <<'EOF'
feat: mangaka index + detail pages

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Series page + Work detail (`/series/{id}`, `/work/{id}`)

**Files:**
- Create: `app/Http/Controllers/SeriesController.php`, `app/Http/Controllers/WorkController.php`, `resources/views/series/show.blade.php`, `resources/views/work/show.blade.php`, `tests/Feature/Browse/SeriesAndWorkTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `x-nav`, `x-section-heading`, `x-work-card`, `x-cover`, `x-badge`, `x-button`; models `Series`, `Work`.
- Produces: `SeriesController@show`, `WorkController@show`. Routes `GET /series/{series}` name `series.show`, `GET /work/{work}` name `work.show`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Browse/SeriesAndWorkTest.php`:
```php
<?php

namespace Tests\Feature\Browse;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesAndWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_series_lists_works_in_sort_order(): void
    {
        $m = Mangaka::factory()->create();
        $series = Series::factory()->for($m)->create(['name' => 'TheSeries']);
        Work::factory()->for($m)->create(['title' => 'Bravo', 'series_id' => $series->id, 'sort_title' => 'Bravo']);
        Work::factory()->for($m)->create(['title' => 'Alpha', 'series_id' => $series->id, 'sort_title' => 'Alpha']);

        $res = $this->get('/series/'.$series->id)->assertOk()
            ->assertSee('TheSeries')->assertSee('Alpha')->assertSee('Bravo');
        $this->assertTrue(strpos($res->getContent(), 'Alpha') < strpos($res->getContent(), 'Bravo'));
    }

    public function test_work_detail_shows_metadata_badges_progress_and_read_cta(): void
    {
        $m = Mangaka::factory()->create(['name' => 'Z.A.P.']);
        $work = Work::factory()->for($m)->create([
            'title' => '四畳半物語', 'circle' => 'Z.A.P.', 'author' => 'ズッキーニ',
            'parody' => 'オリジナル', 'event' => 'C89', 'flags' => ['DL版'],
            'page_count' => 24, 'cover_path' => 'covers/h.webp',
        ]);
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 3]);

        $this->get('/work/'.$work->id)->assertOk()
            ->assertSee('四畳半物語')
            ->assertSee('ズッキーニ')
            ->assertSee('オリジナル')       // parody badge
            ->assertSee('C89')              // event badge
            ->assertSee('DL版')             // flag badge
            ->assertSee('24 pages')         // page count
            ->assertSee('3/24')             // progress
            ->assertSee('href="/work/'.$work->id.'/page/1"', false); // Read CTA → reader seam
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesAndWorkTest`
Expected: FAIL — `/series/{id}` and `/work/{id}` routes are not defined (404).

- [ ] **Step 3: Create the controllers**

`app/Http/Controllers/SeriesController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Series;

/** Series detail — works in reading order. / シリーズ詳細。 */
final class SeriesController extends Controller
{
    public function show(Series $series)
    {
        $series->load('mangaka');
        $works = $series->works()
            ->where('is_missing', false)
            ->with('readingProgress')
            ->orderBy('sort_title')
            ->get();

        return view('series.show', compact('series', 'works'));
    }
}
```

`app/Http/Controllers/WorkController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Work;

/** Work detail — cover, metadata, progress, Read CTA. / 作品詳細。 */
final class WorkController extends Controller
{
    public function show(Work $work)
    {
        $work->load('mangaka', 'series', 'readingProgress');

        return view('work.show', compact('work'));
    }
}
```

- [ ] **Step 4: Create the views**

`resources/views/series/show.blade.php`:
```blade
@extends('layouts.app')

@section('content')
    <x-nav />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <div style="margin-bottom:var(--space-xl);">
            <a href="/mangaka/{{ $series->mangaka->slug }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">{{ $series->mangaka->name }}</a>
            <h1 style="font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">{{ $series->name }}</h1>
        </div>

        <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
            @foreach ($works as $work)
                <x-work-card :work="$work" />
            @endforeach
        </div>
    </main>
@endsection
```

`resources/views/work/show.blade.php`:
```blade
@extends('layouts.app')

@section('content')
    <x-nav />

    <main class="mx-auto w-full" style="max-width:var(--container-text); padding:var(--space-xl) var(--space-lg);">
        <div class="flex" style="gap:var(--space-xl); flex-wrap:wrap;">
            <div style="width:260px; max-width:100%;">
                <x-cover :path="$work->cover_path" :title="$work->title" />
            </div>

            <div style="flex:1; min-width:260px;">
                <h1 style="font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">{{ $work->title }}</h1>

                <div style="margin-top:var(--space-xs); font:var(--type-body); color:var(--text-muted);">
                    <a href="/mangaka/{{ $work->mangaka->slug }}" class="no-underline" style="color:var(--text-link);">{{ $work->mangaka->name }}</a>
                    @if ($work->circle)<span> · {{ $work->circle }}</span>@endif
                    @if ($work->author)<span> · {{ $work->author }}</span>@endif
                </div>

                <div class="flex" style="gap:var(--space-xs); flex-wrap:wrap; margin-top:var(--space-md);">
                    @if ($work->parody)<x-badge>{{ $work->parody }}</x-badge>@endif
                    @if ($work->event)<x-badge>{{ $work->event }}</x-badge>@endif
                    @foreach (($work->flags ?? []) as $flag)<x-badge>{{ $flag }}</x-badge>@endforeach
                </div>

                <p style="margin-top:var(--space-md); font:var(--type-body); color:var(--text-body);">
                    {{ $work->page_count }} pages
                    @if ($work->readingProgress && $work->readingProgress->current_page > 0)
                        · {{ $work->readingProgress->is_completed ? 'Completed' : $work->readingProgress->current_page.'/'.$work->page_count.' read' }}
                    @else
                        · Not started
                    @endif
                </p>

                @if ($work->series)
                    <p style="margin-top:var(--space-xs); font:var(--type-caption);">
                        <a href="/series/{{ $work->series->id }}" class="no-underline" style="color:var(--text-link);">Part of {{ $work->series->name }}</a>
                    </p>
                @endif

                <div style="margin-top:var(--space-lg);">
                    <x-button href="/work/{{ $work->id }}/page/1">▶ Read</x-button>
                </div>
            </div>
        </div>
    </main>
@endsection
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`: add `use App\Http\Controllers\SeriesController;` and `use App\Http\Controllers\WorkController;`, then append:
```php
Route::get('/series/{series}', [SeriesController::class, 'show'])->name('series.show');
Route::get('/work/{work}', [WorkController::class, 'show'])->name('work.show');
```

- [ ] **Step 6: Run tests**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=SeriesAndWorkTest` → PASS (2 tests). Then full suite — green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/SeriesController.php app/Http/Controllers/WorkController.php resources/views/series/ resources/views/work/ routes/web.php tests/Feature/Browse/SeriesAndWorkTest.php
git commit -m "$(cat <<'EOF'
feat: series page + work detail page

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Asset build + full-suite gate

**Files:** none (verification only).

- [ ] **Step 1: Build the frontend assets**

Run: `npm run build`
Expected: Vite compiles `resources/css/app.css` + `resources/js/app.js` to `public/build` with no errors (this also surfaces any Tailwind v4 arbitrary-utility typos used in the components).

- [ ] **Step 2: Run the full test suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: ALL suites green (archive, parsing, scanning, series, reader, browse, auth). Output pristine.

- [ ] **Step 3: Commit (only if the build emitted tracked changes)**

`public/build` is git-ignored (Vite output), so normally there is nothing to commit here. If `git status` shows tracked changes, commit them:
```bash
git status --porcelain
# if non-empty:
git add -A && git commit -m "$(cat <<'EOF'
chore: build assets for browse foundation

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

> **Visual polish gate (controller, post-implementation):** after all tasks pass, render `/`, `/mangaka`, a `/mangaka/{slug}`, a `/series/{id}`, and a `/work/{id}` in **both** light and dark (seed a few works + cover files, `php artisan serve`, screenshot via the browser tool), and confirm: tokens apply (no raw-neutral leftovers), the nav is true-black, the theme toggle flips + persists, covers/placeholders render, grids reflow, and badges/progress look right. File any visual defects as fix-subagent tasks before merge.

---

## Self-Review

**Spec coverage (F1 design doc):**
- App shell re-themed to tokens + light/dark toggle (anti-flash) → Task 1 (`ShellTest`).
- Blade/Alpine components per house rules (`x-nav`, `x-button`, `x-cover`, `x-work-card`, `x-badge`, `x-section-heading`) → Tasks 1–2 (`ShellTest`, `ComponentsTest`).
- Home (Continue Reading + Recently Added, empty state, missing hidden, ordering) → Task 3 (`HomeTest`).
- Mangaka index (counts, pagination, derived cover, empty) + detail (series/standalone) → Task 4 (`MangakaTest`).
- Series (works in `sort_title` order) → Task 5 (`SeriesAndWorkTest`).
- Work detail (cover, metadata, parody/event/flag badges, progress, Read CTA → `/work/{id}/page/1`) → Task 5 (`SeriesAndWorkTest`).
- Missing-works hidden everywhere; covers via `url(cover_path)`; series cover derived → encoded in each controller/view.
- Login re-themed for consistency with the new shell → Task 1.
- Polished bar → token-faithful markup + the Task 6 controller visual gate.

**Placeholder scan:** none — every step has complete code and an exact command with expected output.

**Type consistency:** component tag names + props (`x-nav` `active`; `x-button` `variant`/`href`/`type`; `x-cover` `path`/`title`; `x-work-card` `work`) are identical across definitions (Tasks 1–2) and every call site (Tasks 3–5). Controller method names (`home`, `index`, `show`) match their routes. Literal link paths (`/work/{id}`, `/mangaka/{slug}`, `/series/{id}`, `/work/{id}/page/1`) match the registered routes. All browse queries filter `is_missing = false`. `withoutVite()` + `RefreshDatabase` on every page test.

**Cross-task sequencing checked:** `/`-hitting scaffold tests are handled in Task 3 (AuthGateTest gains `RefreshDatabase`; ExampleTest/HomePageTest/welcome removed). Views use literal paths, so components referencing not-yet-registered routes never call `route()` — no registration-order coupling; by F1 completion every linked route exists.

**Out of scope (later):** the reader (F2); search, filters, scan trigger/status, missing-works view, manual series merge/split/rename (F3); persisting `series.cover_work_id`.
