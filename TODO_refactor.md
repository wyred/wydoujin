# TODO — Refactor / code-quality sweep

Generated 2026-06-24 from a 9-agent parallel audit of the whole codebase (parsing, archive,
scanning/series/jobs, tagging, search/browse, controllers/middleware/routes, models/migrations,
Blade/frontend, build/CI/Docker). Each finding was grounded in `file:line` and the top/dead-code
claims re-verified by hand. Items are **deduped across agents** and tiered by value. Respect the
locked invariants in `CLAUDE.md` (content_hash identity, portable migrations, single-user auth,
parser registry, per-mangaka series, normalized tags).

Legend — Sev: 🔴 high · 🟠 medium · ⚪ low · Effort: S/M/L.

---

## Tier 0 — High value, clear wins

- [ ] **R1 · Add missing DB indexes** 🔴 S — `database/migrations/`
  `works.relative_path` (1024, **no index**) is looked up per-file on every scan
  (`LibraryScanner.php:96`) → full scan per zip. `tags.merged_into_id` (FK via `constrained()`, no
  explicit index; SQLite doesn't auto-index FKs) is filtered on every facet/scan/prune.
  *Action:* new migration — shorten `relative_path` to 768 (portable under utf8mb4 key limit) + add
  index; add index on `tags.merged_into_id`. Portable Blueprint only, no raw SQL.
  *Risk:* pick a length MySQL accepts; index-only, behaviour-neutral.

- [ ] **R2 · `Work::scopePresent()` / `scopeMissing()`** 🟠 M — `app/Models/Work.php` + 6 files / 18 sites
  `where('is_missing', false)` is hand-written ~18× (the bug class CLAUDE.md warns about).
  *Action:* add bilingual scopes; replace call sites incl. relationship closures with `->present()`.
  *Risk:* pure refactor; touches many files — coordinate ordering to avoid churn.

- [ ] **R3 · Optimise `WorkSearch::facets()` query shape** 🔴 M — `app/Browse/WorkSearch.php:103-139,66-100`
  `facets()` calls `matchingWorkIds()` ×6, each `->pluck('id')->all()` pulling the whole matching
  corpus into PHP then re-sending it as a giant `whereIn(...)` — 12 queries/request, several with
  multi-thousand binds. `base()` (incl. LIKE) is rebuilt ~13×/request.
  *Action:* pass the filtered set as a **subquery** to `whereIn` (never `pluck()->all()`); build the
  base predicate once and reuse for results + the 6 facet counts.
  *Risk:* keep ordering/pagination identical; re-run `WorkSearchTest` (counts/dynamic/tombstone).

- [ ] **R4 · Centralise tag canonicalisation (+ make multi-hop safe)** 🔴 S — `app/Models/Tag.php`, `WorkTagSync.php:61-66`, `WorkTagController.php:25-26`
  The "firstOrCreate `(type,value)` → resolve merge-alias" sequence is duplicated in the service and
  the controller, and resolves only **one** hop (`merged_into_id ?? id`) — a latent corruption risk
  if a 2-hop chain ever forms.
  *Action:* one `Tag::canonicalIdFor(type,value): int` (or `canonicalFor`) used by both; loop until
  `merged_into_id === null` with a visited-set/depth guard.
  *Risk:* behaviour identical for current depth-1 data; `attach()` still needs the resolved id for its 201 payload.

- [ ] **R5 · Fix Vite font weights (drop forbidden 500)** 🔴 S — `vite.config.js:11-15`
  Bundles `Instrument Sans` weights `[400, 500, 600]`; house ladder is **300/400/600/700, no 500**.
  *Action:* set `[300,400,600,700]` — **or** remove the `bunny(...)` block entirely if the vendored
  design system already supplies the font stack (verify `resources/design-system` first).
  *Risk:* re-fetches fonts on build; confirm no view relies on 500.

- [ ] **R6 · Global `:focus-visible` ring** 🔴 S — `resources/css/app.css`
  No focus styling anywhere; dozens of controls strip the native outline (`border:none`), and the
  `--focus-ring` token is never used → keyboard users get no visible focus.
  *Action:* one rule: `:focus-visible { outline:2px solid var(--focus-ring); outline-offset:2px }`
  (+ suppress outline on `:focus:not(:focus-visible)`).
  *Risk:* verify contrast on the reader's dark chrome.

- [ ] **R7 · Extract shared CSRF `postJson` helper** 🔴 M — `resources/js/app.js` + 6 Blade components
  Identical JSON+CSRF `fetch` + reload boilerplate is copy-pasted across mangaka/series/tags/work
  (and partially reader/maintenance) — ~90 duplicated lines.
  *Action:* `window.wyd.postJson(url, body)` in `app.js`; call from each Alpine component. Alpine-only.
  *Risk:* browser suite exercises each surface — regressions surface immediately.

---

## Tier 1 — Medium value

- [ ] **R8 · Transaction boundaries for multi-write ops** 🟠 M — `SeriesDetector::detect`, `LibraryScanner` missing-sweep/prune, `SeriesManagementController` (4 ops)
  Only `TagController::merge` is wrapped. A mid-op crash leaves half-grouped series / orphan series.
  *Action:* wrap each op in `DB::transaction` — **per-mangaka** for `detect()`/scan (avoid one giant
  lock); the missing-sweep+prune atomically.
  *Risk:* keep transactions narrow to not block web reads on MySQL.

- [ ] **R9 · Memoise tag canonical-id within a scan** 🟠 M — `app/Tagging/WorkTagSync.php` (driven by scan loop)
  `firstOrCreate` runs per parsed field per work across the whole library on a content change.
  *Action:* per-scan `[type."\0".value => id]` cache (composes with R4). Invalidate/skip on merge.
  *Risk:* keep `pruneOrphans()` semantics; query-count drop only.

- [ ] **R10 · Dedupe zip open/read; `CoverGenerator` reuses `ZipPageReader`** 🟠 S — `app/Archive/`
  `ZipPageReader` and `CoverGenerator` repeat the open→getFromName→close→throw block verbatim.
  *Action:* `CoverGenerator` depends on `ZipPageReader::read()`; wire via `AppServiceProvider`.
  *Risk:* keep exception messages byte-identical (edge tests assert `/Cannot open zip/`).

- [ ] **R11 · `Work` card-relations constant/scope** ⚪ S — controllers
  `->with('readingProgress','tags')` copy-pasted across 4 controllers.
  *Action:* `Work::CARD_RELATIONS` (or `scopeWithCardRelations`) used everywhere a work-card renders.

- [ ] **R12 · Single source for the 6 facet dimensions** 🟠 M — `WorkSearch.php:20`, `browse/index.blade.php`
  The dim list `[circle,parody,event,author,flag,theme]` is restated ~8× (PHP constants + `fromRequest`
  + 5 Alpine object literals) and equals `Tag::TYPES`.
  *Action:* drive `WorkSearch`/`fromRequest`/`$initial` from one constant; seed Alpine
  `selected/facets/expanded/within` by reducing over server-provided `groups` instead of literals.
  *Risk:* keep the `$search->circle` view accessors; verify browse tests.

- [ ] **R13 · Surface corrupt-archive errors in `PageController`** 🟠 S — `app/Http/Controllers/PageController.php:43-45`
  `catch (ArchiveException) → abort(404)` hides genuine corruption (no log/report) and conflates it
  with not-found; diverges from `CoverController`.
  *Action:* `report($e)` before `abort(404)`; add a bilingual comment that pages are non-Range by
  design (vs `CoverController`'s `BinaryFileResponse`). *(Full Range/streaming rewrite deferred.)*

- [ ] **R14 · Run container processes as non-root** 🔴 S — `docker/s6/s6-rc.d/{web,worker,scheduler}/run`
  All three long-run services run as root (process untrusted zip/image decode with full privileges).
  *Action:* `s6-setuidgid www-data` before each exec (storage/data already chowned; FrankenPHP binds
  :8080 fine unprivileged). **Cannot run a container locally — change is the canonical s6 pattern but
  ships unverified end-to-end; flag in the commit.**

- [ ] **R15 · Drop weak default DB passwords in compose** 🟠 S — `docker-compose.yml:15,29,30`
  `:-secret` / `:-rootsecret` make the insecure path the zero-config default.
  *Action:* remove the fallbacks (fail fast on unset) and/or gate the bundled `mysql` behind a
  `profiles: ["bundled-db"]`. Keep README guidance.

- [ ] **R16 · Remove redundant `/up` health route** 🟠 S — `bootstrap/app.php:12`
  Custom `/health` is the real one (Docker HEALTHCHECK + middleware-exempt + tested); `health:'/up'`
  is gated by `RequirePassword` (302→login) and unused.
  *Action:* drop `health: '/up'` from `withRouting`. Keep `/health`.

- [ ] **R33 · CI dependency caching** 🟠 S — `.github/workflows/ci.yml`
  No Composer/npm cache; cold install + asset build every run.
  *Action:* `actions/cache` (or `ramsey/composer-install` + `setup-node cache: npm`) keyed on lockfiles.

---

## Tier 2 — Low-value polish & dead code

- [ ] **R17 · Remove dead `language` field from `ParsedName`** 🟠 S — `app/Parsing/ParsedName.php:20,37,48`
  Never written by any pattern, never read by any consumer; CLAUDE.md says language was dropped.
  *Action:* remove ctor + `make()` param/field; update the `assertNull($r->language)` test lines.

- [ ] **R18 · Drop dead `series.cover_work_id` column** 🟠 S — `database/migrations/2026_06_21_000002`
  No relation, FK, index, cast, or read anywhere. *Action:* remove the column (pre-deployment → edit
  the create migration; tests are fresh `:memory:`).

- [ ] **R19 · Make `Tag::AUTO_TYPES` load-bearing** ⚪ S — `app/Models/Tag.php:24`, `WorkTagSync.php:45,53`, `LegacyScalarBackfill.php:18`
  Constant is declared but unused; the scalar type list is instead duplicated inline in derive()+backfill.
  *Action:* add `Tag::SCALAR_TYPES` (the 4 scalars), define `AUTO_TYPES = [...SCALAR_TYPES,'flag']`,
  and drive `derive()`/backfill from it.

- [ ] **R20 · Move `deriveSortTitle` out of `ParsedName`** ⚪ M — 6 callers (Series/Tag/Tagging/2× Http/Parsing)
  A pure sort-key normaliser living on a Parsing value object, reached cross-domain.
  *Action:* move to a neutral home (`Series\TitleNormalizer` or `Support\SortKey`); `ParsedName::make`
  delegates. **Output must stay byte-identical** (sort_value columns + Tag `creating` hook) — run full suite.

- [ ] **R21 · Extract `Series::pruneEmptyAuto($mangakaId)`** ⚪ S — `SeriesDetector.php:60-63`, `SeriesManagementController.php:110-116`
  "Delete empty auto series" query written verbatim twice. *Action:* one scope/static, call from both.

- [ ] **R22 · `Tag` `$casts` for `merged_into_id`** ⚪ S — `app/Models/Tag.php`
  Bigint FK uncast → repeated `(int)(... ?? id)` at call sites (MySQL returns strings, SQLite ints).
  *Action:* `protected $casts = ['merged_into_id' => 'integer']`; drop the manual `(int)` casts.

- [ ] **R23 · `Scan` model constants + `scopeActive()`** ⚪ S — `app/Models/Scan.php`, `MaintenanceController.php:37`
  `status`/`triggered_by` are stringly-typed; `whereIn('status',['queued','running'])` hardcoded.
  *Action:* `Scan::STATUSES`/`TRIGGERS` + `scopeActive()` (queued|running), like `Tag::TYPES`.

- [ ] **R24 · Fix `composer test` script** ⚪ S — `composer.json:51`
  `@no_additional_args` is a bogus token; `config:clear` is redundant (phpunit.xml sets testing env).
  *Action:* reduce to `"@php artisan test"`.

- [ ] **R25 · Remove `inspire` demo command** ⚪ S — `routes/console.php:8-10`
  Default Laravel scaffold in a single-purpose app. *Action:* delete it + the unused import.

- [ ] **R26 · Single source for image extensions** ⚪ S — `ArchiveInspector.php:13`, `config/scan.php:13`, `PageController.php:15-22`
  Extension set defined 3× → adding one to config silently breaks `PageController::MIME`.
  *Action:* derive all from one place (config references the constant, or vice-versa); at minimum cross-ref comment.

- [ ] **R27 · Extract shared form/card Blade partials** 🟠 M — `resources/views/`
  No `x-input`; the solid primary `<button>` is re-inlined ~6× (drifts from `x-button`: `7px 16px` vs
  `11px 22px`); mangaka/series tiles + `auto-fill minmax(150px)` grid + Prev/Next pagination are hand-rolled.
  *Action:* add `x-input`, reuse `x-button` for submits, add `x-collection-card` + `x-card-grid` +
  `x-pagination`; fold raw control padding into these (token-based). *(Larger — split into commits.)*

- [ ] **R28 · A11y: `aria-current` nav + page `<h1>`** 🟠 S — `components/nav.blade.php:7-11`, `home/mangaka-index/maintenance`
  Active nav conveyed by colour only; populated home/mangaka-index/maintenance have `<h2>`s but no `<h1>`.
  *Action:* `aria-current="page"` (+ data-driven link loop); add an `<h1>`/`x-page-heading` per top page.

- [ ] **R29 · Dark-mode remap for `--color-error`** ⚪ S — `resources/css/app.css:32`
  `#b8453e` tuned for light; no `[data-dark]` override → low contrast on dark canvas.
  *Action:* add a lighter red under `[data-dark="true"]`.

- [ ] **R30 · Rewrite README** ⚪ S — `README.md`
  Top ~58 lines are stock `laravel/laravel` (incl. "email security issues to Taylor"); only 12 lines
  are project-specific. *Action:* concise wydoujin README (toolchain `PATH=` quirk, test/build/docker,
  env keys APP_PASSWORD/LIBRARY_PATH/DATA_PATH).

- [ ] **R31 · Dependency hygiene** ⚪ S — `composer.json`, `package.json`
  Remove unused `laravel/pao`; move `pestphp/pest` + `pest-plugin-browser` to `require-dev`
  (already excluded from image via `--no-dev`). Keep `laravel/pail` only if `composer dev` is used.

- [ ] **R32 · Remove empty scaffold stubs / document DI** ⚪ S — `Controller.php`, `AppServiceProvider.php`
  Strip the stray `//` in the base controller + empty `boot()`; add a bilingual note on why
  scanner/detector use `bind` (per-scan state) vs `singleton`.

---

## Deferred / Won't-do (rationale)

- **Logout route** — additive, single-user, low value. Revisit only if shared-machine use matters.
- **Pint/Larastan in CI** — would force a formatting churn commit; house style is "minimal CI". Optional.
- **Full Range-request streaming rewrite** (PageController) — risks the 304 fast-path; only R13 (report+comment) done.
- **Squash stale scalar columns in works migration / drop default `users`+`password_reset_tokens` tables** — editing baseline migrations is risky for any already-migrated DB; low value pre-deployment.
- **Full-width-bracket parser pattern** — no evidence of such filenames; adding one now is speculative (registry is ready if real inputs appear).
- **`SeriesDetector` chunking / `cluster()` O(n²)** — fine for realistic per-mangaka counts (YAGNI); add a docblock note only.
- **`NamePattern::$mangaka` param** — intentionally reserved for future patterns; keep.
- **Standalone "add a test" items** (scan honours `tags_locked`; across-dim facet narrowing; multi-folder cover pick) — folded into the corresponding code change's tests.

---

## Execution notes
- Work on branch `refactor/code-quality-sweep`; one atomic commit per item (or tight group); TDD where
  behaviour changes. Run `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest` after each.
- Merge to `main` locally when green (pre-deployment policy).
- 100% line coverage is the baseline — keep it.
