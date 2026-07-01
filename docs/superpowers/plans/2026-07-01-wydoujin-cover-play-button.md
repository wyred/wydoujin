# Cover Play Button Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a circular "play" button over each work thumbnail — click the circle to jump straight into the reader, click anywhere else on the card to open the detail page.

**Architecture:** Pure CSS + layered anchors, **no JavaScript**. The card becomes a `group` wrapper holding (1) a `relative` cover box with a full-cover *detail* link, a decorative scrim, and a centered *play* link whose only clickable area is the circle, and (2) the title/meta block as the keyboard-focusable detail link. The reveal-on-hover / always-on-touch behaviour lives in a small CSS block; everything else is inline design-token styles.

**Tech Stack:** Laravel 13 Blade · Tailwind CSS v4 utilities + design-system CSS tokens · Pest 4 (feature + Playwright browser suite). No new dependencies.

## Global Constraints

- **Tokens only — never a raw hex or px color.** Translucency comes from `color-mix(in srgb, var(--color-ink) N%, transparent)` over existing tokens. (CLAUDE.md design-system rule.)
- **Elevation is a 1px hairline ring, not a shadow** (`border:1px solid var(--color-hairline)`).
- **One blue accent only** — the play disc is neutral dark (ink), not a second accent colour.
- **Quiet motion** — ~150ms transitions; buttons press to `scale(var(--press-scale))`.
- **Alpine only where needed** — this feature adds **no** JavaScript.
- **The play link always points at `/work/{id}/read`**; the reader decides start-vs-resume (so the button is "Continue" for free — no progress logic on the card).
- **Local PHP is broken** — prefix every artisan/pest command with `PATH="/opt/homebrew/opt/php/bin:$PATH"`.
- **Node/npm are on the normal PATH.** The browser suite needs built assets (`npm run build`) and a one-time `npx playwright install chromium`.
- Small, logical commits; descriptive messages.

## File Structure

- **Modify** `resources/views/components/work-card.blade.php` — restructure from a single `<a>` into the layered `group` markup. Sole view change (every listing — home, `mangaka/show`, `series/show`, `browse/_cards` — renders through this one component).
- **Modify** `resources/css/app.css` — add one small `.wyd-card-play` / `.wyd-card-scrim` reveal block (mirrors the existing `.wyd-select` / `--reader-scrim` precedent for card-scoped CSS the design tokens don't cover).
- **Modify** `tests/Feature/Browse/ComponentsTest.php` — add a test asserting the card renders the play link + aria-label alongside the detail link.
- **Modify** `tests/Feature/Browse/HomeTest.php` — the "random picks" test counts `href="/work/` per card; the new markup adds links, so switch it to count play links (`/read"`), one per present work.
- **Create** `tests/Browser/CoverPlayButtonTest.php` — Playwright: hidden-by-default, hover reveals, click circle → reader, click cover → detail; light + dark, no JS errors.

`x-cover` (`resources/views/components/cover.blade.php`) is **not** touched — the card wraps it.

---

### Task 1: Card play-button markup + reveal CSS

**Files:**
- Modify: `resources/views/components/work-card.blade.php`
- Modify: `resources/css/app.css`
- Modify (test): `tests/Feature/Browse/ComponentsTest.php`
- Modify (test): `tests/Feature/Browse/HomeTest.php`

**Interfaces:**
- Consumes: the `$work` model already passed to `<x-work-card>` (`id`, `title`, `page_count`, `cover_path`, `readingProgress`, `tags`). No signature change — the component's public contract is unchanged.
- Produces: rendered card markup that contains, per work, a detail link `href="/work/{id}"` and a play link `href="/work/{id}/read"` with `aria-label="Read {title}"`. Task 2's browser test relies on the selector `a[aria-label^="Read"]` (the play link) and the classes `.wyd-card-play` / `.wyd-card-scrim`.

- [ ] **Step 1: Write the failing feature test**

Add to `tests/Feature/Browse/ComponentsTest.php` (it already `uses(SeedsTags)` and imports `Mangaka`, `Work`, `Blade`):

```php
test('work card renders a play shortcut to the reader', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create(['title' => 'PlayMe']);
    $work->load('readingProgress', 'tags');

    $html = Blade::render('<x-work-card :work="$work" />', ['work' => $work]);

    // Detail link is still present (cover + title both point at the work page).
    $this->assertStringContainsString('href="/work/'.$work->id.'"', $html);
    // The play circle links straight to the reader and is labelled for assistive tech.
    $this->assertStringContainsString('href="/work/'.$work->id.'/read"', $html);
    $this->assertStringContainsString('aria-label="Read '.$work->title.'"', $html);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest --filter="play shortcut"`
Expected: FAIL — the current single-`<a>` card has no `/read` link or `aria-label`, so the last two assertions fail.

- [ ] **Step 3: Rewrite the card markup**

Replace the entire contents of `resources/views/components/work-card.blade.php` with:

```blade
@props(['work'])

@php
    $progress = $work->readingProgress;
    $pages = max(1, (int) $work->page_count);
    $pct = $progress ? min(100, (int) round($progress->current_page / $pages * 100)) : 0;
@endphp

<div class="group relative">
    {{-- Cover with the play-button shortcut layered on top. --}}
    <div class="relative">
        <x-cover :path="$work->cover_path" :title="$work->title" />

        {{-- Detail click layer: covers the whole cover so clicking anywhere here
             (except the play circle above) opens the detail page. Hidden from AT /
             keyboard — the title link below is the accessible detail link. --}}
        <a href="/work/{{ $work->id }}" aria-hidden="true" tabindex="-1"
           class="absolute inset-0 z-10" style="border-radius:var(--radius-md);"></a>

        {{-- Scrim: decorative dim on hover; never intercepts clicks. --}}
        <div aria-hidden="true"
             class="wyd-card-scrim absolute inset-0 z-20 pointer-events-none"
             style="border-radius:var(--radius-md); background:color-mix(in srgb, var(--color-ink) 35%, transparent);"></div>

        {{-- Play button. The centering layer passes clicks through
             (pointer-events:none) EXCEPT on the circle itself, so only the circle
             navigates to the reader; everywhere else falls through to the detail
             layer beneath. --}}
        <div class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none">
            <a href="/work/{{ $work->id }}/read" aria-label="Read {{ $work->title }}"
               class="wyd-card-play pointer-events-auto flex items-center justify-center"
               style="width:56px; height:56px; border-radius:var(--radius-pill);
                      background:color-mix(in srgb, var(--color-ink) 55%, transparent);
                      border:1px solid var(--color-hairline);">
                {{-- Play triangle (CSS shape), nudged right for optical centering. --}}
                <span aria-hidden="true" style="display:block; width:0; height:0; margin-left:4px;
                      border-top:9px solid transparent; border-bottom:9px solid transparent;
                      border-left:15px solid var(--color-on-primary);"></span>
            </a>
        </div>
    </div>

    {{-- Title / circle / progress — the keyboard-focusable link to the detail page. --}}
    <a href="/work/{{ $work->id }}" class="no-underline block" style="margin-top:var(--space-xs);">
        <div class="truncate" style="font:var(--type-caption-strong); color:var(--text-heading);">{{ $work->title }}</div>
        @php $circle = $work->tags->firstWhere('type', 'circle'); @endphp
        @if ($circle)
            <div class="truncate" style="font:var(--type-fine); color:var(--text-muted);">{{ $circle->value }}</div>
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
    </a>
</div>
```

- [ ] **Step 4: Add the reveal CSS**

Append to `resources/css/app.css` (after the `.wyd-select` block at the end):

```css
/* Cover play-button shortcut. The circular "play" overlay on each work card is
   hidden until the card is hovered (desktop); where hover is unavailable (touch)
   it stays visible so it can still be tapped. The scrim dims the cover behind it.
   `.group` is the Tailwind marker class on the card wrapper. カード再生ボタン。 */
.wyd-card-scrim { opacity: 0; transition: opacity 150ms ease; }
.group:hover .wyd-card-scrim { opacity: 1; }

.wyd-card-play { opacity: 0; transition: opacity 150ms ease, transform 120ms ease; }
.group:hover .wyd-card-play,
.wyd-card-play:focus-visible { opacity: 1; }
.wyd-card-play:active { transform: scale(var(--press-scale)); }

@media (hover: none) {
    .wyd-card-play { opacity: 1; }
}
```

- [ ] **Step 5: Run the feature test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest --filter="play shortcut"`
Expected: PASS.

- [ ] **Step 6: Run the Browse feature group to surface the regression**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Browse`
Expected: FAIL in `HomeTest` → "random picks shows up to 8 present works and hides missing". The new markup adds a play link (and a second detail link) per card, so `substr_count($picks, 'href="/work/')` is no longer 8.

- [ ] **Step 7: Fix the random-picks assertion**

In `tests/Feature/Browse/HomeTest.php`, in the "random picks" test, replace:

```php
    $this->assertSame(8, substr_count($picks, 'href="/work/'));
```

with:

```php
    // Each present work card renders exactly one play-button link (…/read); missing
    // works render no card, so this still asserts "8 present works shown".
    $this->assertSame(8, substr_count($picks, '/read"'));
```

- [ ] **Step 8: Run the Browse feature group to verify green**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Browse`
Expected: PASS (all files in the group, including `ComponentsTest` and `HomeTest`).

- [ ] **Step 9: Run the full feature suite (regression guard)**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: PASS. (No `app/` code changed, so 100% line coverage is unaffected; this run only guards against other views/tests that assert card structure — none are expected to break.)

- [ ] **Step 10: Commit**

```bash
git add resources/views/components/work-card.blade.php resources/css/app.css tests/Feature/Browse/ComponentsTest.php tests/Feature/Browse/HomeTest.php
git commit -m "Add a play-button shortcut to work thumbnails

Layer a detail link, a hover scrim, and a centered play link over the
cover: click the circle to open the reader, click anywhere else to open
the detail page. Pure CSS, no JavaScript.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012mD2yshVJJZaGoMTmHwdQY"
```

---

### Task 2: Browser test — hover, reveal, and the two click targets

**Files:**
- Create: `tests/Browser/CoverPlayButtonTest.php`

**Interfaces:**
- Consumes: the card markup from Task 1 — selectors `a[aria-label^="Read"]` (play link) and the detail link `a[href="/work/{id}"]`; the class `.wyd-card-play` (opacity-driven reveal).
- Produces: nothing consumed downstream (final task).

- [ ] **Step 1: Build assets so the new classes/utilities exist in the served CSS**

The browser suite loads the compiled stylesheet, and Task 1 introduced new classes (`.wyd-card-play`, `.wyd-card-scrim`) and Tailwind utilities (`z-10/20/30`, `pointer-events-*`, `inset-0`, flex centering). Compile them:

Run: `npm run build`
Expected: Vite writes `public/build/` with no errors.

(One-time, if not already done: `npm install && npx playwright install chromium`.)

- [ ] **Step 2: Write the browser test**

Create `tests/Browser/CoverPlayButtonTest.php`:

```php
<?php

use App\Models\Mangaka;
use App\Models\Work;

// The cover play-button shortcut: hidden until hover, then click the circle to
// open the reader; clicking the cover elsewhere opens the detail page.
// カバーの再生ボタン：ホバーで表示、円をクリックでリーダー、その他は詳細ページ。

test('play button is hidden by default, reveals on hover, and opens the reader', function (): void {
    $m = Mangaka::factory()->create();
    $work = Work::factory()->for($m)->create(['title' => 'PlayableWork', 'sort_title' => 'PlayableWork']);

    // /browse renders one card server-side, so the play link selector is unique.
    $page = visit('/browse');
    $page->assertPresent("a[aria-label^='Read']");

    // Hidden by default (no transition is running at load, so opacity is a stable "0").
    $page->assertScript("getComputedStyle(document.querySelector(\"a[aria-label^='Read']\")).opacity", '0');

    // Kill transitions so the post-hover opacity read is deterministic, then hover.
    $page->script("var s=document.createElement('style');s.textContent='*{transition:none !important}';document.head.appendChild(s)");
    $page->hover("a[aria-label^='Read']");
    $page->assertScript("getComputedStyle(document.querySelector(\"a[aria-label^='Read']\")).opacity", '1');

    // Clicking the circle navigates to the reader.
    $page->script("document.querySelector(\"a[aria-label^='Read']\").click()");
    $page->assertPathIs('/work/'.$work->id.'/read')
        ->assertNoJavaScriptErrors();
});

test('clicking the cover away from the circle opens the detail page', function (): void {
    $m = Mangaka::factory()->create();
    $work = Work::factory()->for($m)->create(['title' => 'DetailWork', 'sort_title' => 'DetailWork']);

    $page = visit('/browse');

    // The first /work/{id} anchor is the full-cover detail layer; clicking it (not the
    // centered play circle) opens the detail page.
    $page->script("document.querySelector(\"a[href='/work/".$work->id."']\").click()");
    $page->assertPathIs('/work/'.$work->id)
        ->assertNoJavaScriptErrors();
});

test('play button renders without errors in dark mode', function (): void {
    $m = Mangaka::factory()->create();
    Work::factory()->for($m)->create(['title' => 'DarkPlayWork', 'sort_title' => 'DarkPlayWork']);

    $page = visit('/browse');
    $page->click('☾'); // theme toggle → data-dark="true" on <html>

    $page->assertScript('document.documentElement.getAttribute("data-dark")', 'true')
        ->assertPresent("a[aria-label^='Read']")
        ->assertNoJavaScriptErrors();
});
```

- [ ] **Step 3: Run the browser test**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Browser/CoverPlayButtonTest.php`
Expected: PASS — all three tests, no console/JS errors.

- [ ] **Step 4: Commit**

```bash
git add tests/Browser/CoverPlayButtonTest.php
git commit -m "Add browser test for the cover play-button shortcut

Verifies the play circle is hidden by default, reveals on hover, opens
the reader when clicked, and that clicking the cover elsewhere opens the
detail page — in light and dark, with no console errors.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012mD2yshVJJZaGoMTmHwdQY"
```

---

## Self-Review

**Spec coverage:**
- Purpose (one-click play, click circle → reader, click elsewhere → detail) → Task 1 markup; verified Task 2.
- Scope (only `work-card.blade.php`; `x-cover` untouched) → honoured. *Deviation:* the spec said "one file"; the plan also adds a small block to `app.css` for the `@media (hover:none)` / `:hover` reveal (can't be inlined). Consistent with the existing `.wyd-select` precedent; no `app/` code changes, so `app/` coverage is unaffected.
- Structure (detail overlay `aria-hidden`/`tabindex=-1`, scrim `pointer-events:none`, play link only the circle, meta as detail link) → Task 1 Step 3 exactly.
- Look & motion (translucent ink disc via `color-mix`, white `--color-on-primary` triangle, hairline ring, `--radius-pill`, ~150ms, press `var(--press-scale)`, hover reveal, touch always-on) → Task 1 Steps 3–4.
- Accessibility (play `aria-label`, focus ring via existing global `:focus-visible`, real anchors for new-tab/keyboard) → Task 1 Step 3.
- Testing (cheap feature test for both hrefs; browser test hover→click both targets, light+dark, no JS errors) → Task 1 Steps 1/5 + Task 2.
- Edge cases (no-cover placeholder still works — overlays sit over the placeholder tile; no progress dependency; sibling not nested anchors; tokens only) → covered by the markup + constraints.

**Placeholder scan:** none — every step has concrete code/commands and expected output.

**Type/name consistency:** selectors and classes match across tasks — `a[aria-label^="Read"]`, `href="/work/{id}/read"`, `.wyd-card-play`, `.wyd-card-scrim`, `.group`. The HomeTest fix targets the exact existing assertion. Feature-test filter name ("play shortcut") matches the test title.

## Out of scope

Per spec: no `x-cover`/reader/progress changes; no hover-preview popover, keyboard shortcut, or context menu; the shortcut appears on every listing that uses `x-work-card`.
