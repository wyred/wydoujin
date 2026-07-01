# Cover Play Button — design

Date: 2026-07-01
Status: approved, ready for planning
Parent: `2026-06-21-wydoujin-design.md` · related: `2026-06-23-wydoujin-reader-design.md`

## Purpose

Add a **one-click "play" shortcut** to every work thumbnail so the owner can jump straight into
reading without the current two-step (open the detail page → click **Read/Continue**).

A circular **play button** (a disc with a triangle inside) appears centered over the cover:

- **Click the circle** → go straight to the reader (`/work/{id}/read`).
- **Click anywhere else on the thumbnail** (cover or the title/meta below) → the work detail page
  (`/work/{id}`), exactly as today.

`/work/{id}/read` already auto-resumes reading progress, so the play button is **"Continue" for
free** — no separate logic and no reference to the reading-progress state on the card.

## Scope

- **One file changes:** `resources/views/components/work-card.blade.php` (the single shared card,
  rendered on home, `mangaka/show`, `series/show`, and `browse/_cards`). Changing it once covers
  every listing.
- `resources/views/components/cover.blade.php` (`x-cover`) is **not** modified — the card wraps it.
- No PHP / `app/` changes, so the 100%-line-coverage target for `app/` is unaffected.

## Approach — pure CSS + layered anchors (no JavaScript)

The card is currently a single `<a href="/work/{id}">` wrapping the cover + title. An `<a>` cannot
be nested inside another `<a>` (invalid HTML), so the play link and the detail link must be
**separate, layered click targets** — not JS click-routing. Real anchors keep cmd/middle-click
"open in new tab" and keyboard focus working for free, and the markup is trivially testable.

Rejected alternatives: **Alpine click-routing** (JS on every card, breaks open-in-new-tab, worse
a11y) and **single link + JS hit-testing** the click coordinates (hacky, poor a11y).

## Structure (the rework of `work-card.blade.php`)

Two regions inside a `group` wrapper:

**1. Cover box** — a `relative` container, children in stacking order (lowest → highest):

1. `<x-cover :path :title>` — unchanged.
2. **Detail overlay** `<a href="/work/{id}">` filling the cover (`absolute inset-0`), with
   `aria-hidden="true"` + `tabindex="-1"` (the title link below is the accessible detail link, so
   this one is not a duplicate for keyboard/AT). Clicking anywhere on the cover → detail.
3. **Scrim** `<div>` filling the cover, `pointer-events:none` (decorative only — clicks on the
   dimmed area fall through to the detail overlay beneath). Fades in on hover.
4. **Play link** `<a href="/work/{id}/read">` sized to **just the circle**, centered, top layer,
   `aria-label="Read {{ title }}"`. The only element that navigates to the reader.

**2. Meta** — the title, circle name, and reading-progress bar stay a normal
`<a href="/work/{id}">` block below the cover (the keyboard-focusable detail link). Unchanged from
today except for being a sibling of the cover box rather than wrapping it.

Stacking is controlled with explicit `z-index` so the play link sits above the detail overlay;
because the play link is only as big as the circle, every other pixel of the cover resolves to the
detail overlay.

## Look & motion

- **Circle:** a translucent dark disc — `background: color-mix(in srgb, var(--color-black) 55%,
  transparent)` — with a **white play triangle** (`var(--color-on-primary)`), a **1px hairline
  ring** (`var(--color-hairline)` — house rule: elevation is a ring, not a shadow), and
  `border-radius: var(--radius-pill)`. The triangle is a CSS-only shape (border trick or an inline
  SVG using `currentColor`), nudged slightly right for optical centering.
- **Scrim:** `color-mix(in srgb, var(--color-black) ~35%, transparent)` over the whole cover.
- **Reveal (desktop):** circle + scrim default to `opacity:0`; on `.group:hover` → `opacity:1`
  with a quiet ~150ms transition. The circle presses to `scale(0.95)` on `:active` (house motion).
- **Touch (`@media (hover: none)`):** the **circle is always visible** (`opacity:1`) and the scrim
  stays hidden — the solid dark disc is legible on its own, so covers aren't permanently dimmed.
- **Tokens only** — no raw hex or px colors; `color-mix` over existing tokens provides the
  translucency. `--color-black` (`#000`, no dark-mode override) keeps the disc + scrim dark in
  both themes — unlike theme-relative tokens such as `--color-ink` (which inverts to near-white in
  dark mode), so the white triangle stays legible on both.

## Accessibility

- Two links per card: the **title** (detail) and the **play** (`aria-label="Read {title}"`). The
  cover's detail overlay is `aria-hidden`/`tabindex=-1` so it isn't a third, redundant tab stop.
- Both are real `<a>` elements: keyboard focusable, Enter-activatable, and support
  open-in-new-tab; the play link shows a visible focus ring (`var(--focus-ring)`).

## Testing

- **Feature (in CI, cheap):** a card renders **both** hrefs — `/work/{id}` (detail) and
  `/work/{id}/read` (play) — with the play link carrying its `aria-label`. A quick guard that the
  markup is present on a listing page (e.g. home).
- **Browser (`tests/Browser`, explicit suite — Pest 4 + Playwright):** hover a card → the play
  circle becomes visible → clicking it lands on `…/read`; clicking the cover elsewhere lands on
  `/work/{id}`. Checked in **light and dark** with **no console/JS errors**, matching the suite's
  existing conventions.

## Edge cases & invariants

- **No-cover works:** `x-cover` already renders a placeholder tile; the overlays sit over it
  identically (the play button still works).
- **No progress dependency:** the play button always points at `/work/{id}/read`; the reader
  decides start-vs-resume. The card keeps showing its progress bar as-is.
- **Nesting:** the play `<a>` is a **sibling** of the detail `<a>`, never nested — valid HTML.
- **Design system:** tokens only, ring-not-shadow, one-blue-accent respected (the disc is neutral
  dark, not a second accent color), quiet motion.

## Out of scope

- No change to `x-cover`, the reader, or reading-progress logic.
- No hover-preview / quick-look popover, no keyboard shortcut, no context menu.
- No per-page opt-out — the shortcut appears on every listing that uses `x-work-card`.
