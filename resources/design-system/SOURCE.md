# Vendored design system — provenance & usage

**Source:** "Apple Design System" on claude.ai/design
**Project ID:** `7f55e543-1f4e-4574-afa2-2dfda16b2992` (owner: Erik)
**Pulled:** 2026-06-21 (via the DesignSync tool)

## How this is used in wydoujin

- **Tokens are live.** `styles.css` and `ds-tokens/*.css` are imported by
  `resources/css/app.css` (see Plan 1, Task 5), so the whole app inherits the
  Apple token layer and its `[data-dark="true"]` dark mode.
- **Components are reference only.** The files under `components/` are **React**
  (`.jsx`) plus their prop types (`.d.ts`). They are NOT compiled into the app —
  Vite never imports them. They exist as the visual + interaction spec for
  re-building each control as a **Blade + Alpine** partial during Plan 5
  (Browse Surfaces & UI). This keeps the app React-free per the architecture
  decision (minimal JS, no SPA framework).

## What was intentionally NOT pulled

- `_ds_bundle.js`, `_ds_manifest.json`, `_adherence.oxlintrc.json`, `.thumbnail`,
  `cards/*.html` — these serve the claude.ai/design viewer pane only and have no
  use inside the Laravel app.
- The `.html` component preview harnesses — React/CDN demo scaffolding that can't
  run in our stack; the `.jsx` + `.d.ts` already capture the full spec.

## Notes

- `ds-tokens/fonts.css` loads **Inter from Google Fonts at runtime**. For a fully
  self-hosted deployment, consider self-hosting Inter and dropping the `@import`.
- The internal namespace string `WyBooruDesignSystem_7f55e5` in the upstream README
  is auto-generated and harmless.

## Re-syncing

The design system can be re-pulled from the same project ID with the DesignSync
tool. Re-running a pull overwrites the token files; keep app-specific overrides in
`resources/css/app.css`, not in these vendored files.
