# Apple Design System

A small, reusable design system in the Apple house style: **one blue accent, white / parchment surfaces, near-black ink, Inter / SF Pro type with tight tracking, pill-shaped controls, quiet motion, and a single reserved shadow.** Light and dark themes are baked into the tokens. Drop it into any project that wants a clean, restrained product UI.

> Note: this system began life tailored to an image board ("wyBooru"). It has been generalized — the booru-specific prototype, tag taxonomy, and copy are gone. The internal namespace string still reads `WyBooruDesignSystem_7f55e5` (auto-generated, harmless); the system is otherwise project-agnostic.

## What's in it

```
styles.css              ← the one file consumers link (imports everything below)
ds-tokens/
  fonts.css             font stack + Inter @import
  colors.css            accent / ink / surfaces / hairlines + [data-dark] overrides
  typography.css        size, weight, line-height, tracking + composed --type-* roles
  spacing.css           8px scale + layout constants
  shape.css             radii, the one shadow, press-scale
components/
  Button, Input, Textarea, OptionChip, Segmented, Card, Badge, NavBar
cards/                  Design-System-tab thumbnails for Colors / Type / Spacing / Shape
```

## Using the tokens

Link `styles.css` once. Everything is a CSS variable — **never inline a hex**; reference a token.

```html
<link rel="stylesheet" href="styles.css">
```

```css
.cta      { background: var(--color-primary); color: var(--color-on-primary); border-radius: var(--radius-pill); }
.heading  { font: var(--type-display-lg); letter-spacing: var(--tracking-display-md); color: var(--text-heading); }
.body     { font: var(--type-body); color: var(--text-body); }
```

Prefer the **semantic aliases** (`--text-heading`, `--text-muted`, `--surface-page`, `--surface-card`, `--border-card`, `--focus-ring`) in components — they re-map automatically in dark mode.

## Dark mode

Set `data-dark="true"` on any ancestor (usually `<html>` or the app root). The accent brightens to sky blue, surfaces drop to charcoal, ink inverts — components need no dark-specific code because they ride the semantic aliases. The black nav bar stays black in both themes.

```html
<html data-dark="true"> … </html>
```

## Using the components

The components compile into `_ds_bundle.js` and are exposed on `window.WyBooruDesignSystem_7f55e5`. Load React, then the bundle, then read what you need:

```html
<script src="https://unpkg.com/react@18.3.1/umd/react.development.js" …></script>
<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.development.js" …></script>
<script src="_ds_bundle.js"></script>
<script type="text/babel">
  const { Button, Input, Card, NavBar } = window.WyBooruDesignSystem_7f55e5;
</script>
```

| Component | Purpose | Key props |
|---|---|---|
| `Button` | Action element | `variant` primary/secondary/ghost · `size` · `fullWidth` · `disabled` |
| `Input` | Pill text field, 44px | `value` · `onChange` · `placeholder` · `disabled` |
| `Textarea` | Multi-line field | `value` · `onChange` · `rows` |
| `OptionChip` | One selectable pill | `label` · `selected` · `onClick` |
| `Segmented` | Managed single-select row | `options` (string \| {label,value}) · `value` · `onChange` |
| `Card` | Hairline surface | `interactive` · `padding` · `radius` |
| `Badge` | Status / taxonomy pill | `tone` neutral/blue/green/purple/amber/red · `solid` |
| `NavBar` | Thin black global header | `brand` · `links[]` · `right` · `onBrandClick` |

Full prop types live in each component's `.d.ts`.

## Principles

- **One interactive color.** Blue is the only accent; it carries every link and CTA.
- **No 500 weight.** The ladder is 300 / 400 / 600 / 700.
- **Elevation is a ring, not a shadow.** Cards use a 1px hairline. The single drop shadow (`--shadow-product`) is reserved for imagery.
- **Quiet motion.** Buttons press to `scale(0.95)`; hovers are background tints and underlines. Nothing else animates.
- **17px reading pace.** Body copy is 17px, not 16.
