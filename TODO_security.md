# TODO — Security audit & hardening

Generated 2026-06-24 from a 7-agent parallel security audit (auth/access-control · path-traversal ·
untrusted zip/image processing · injection/mass-assignment · XSS/output-encoding · config/secrets/headers ·
dependencies/supply-chain). Findings are deduped and rated **against this app's threat model**, not in the
abstract.

**Threat model.** Single-user, self-hosted. Auth = one optional password (`APP_PASSWORD`; **unset ⇒ the app
is fully open**). The authenticated user is *trusted*, so self-inflicted issues (SQLi/mass-assign against
one's own data) are Low unless they escalate. The real high-value threats are: **(1) untrusted file content**
— the `.zip`s and their images are downloaded from arbitrary sources and processed by the scanner/cover-gen;
**(2) unauthenticated access** — bypass/brute-force the gate; **(3) path traversal / host-file read.**

**Overall posture: good.** No Critical. No SQLi, no command injection, no unsafe deserialization, no XSS
(all output escaped), CSRF intact, timing-safe password compare, session regenerated on login. See
*Confirmed safe* at the bottom — don't re-litigate those.

Legend — Sev: 🔴 High · 🟠 Medium · ⚪ Low/hardening · Effort S/M/L · ⚠️ = can't fully verify in this env.

---

## Tier H — High

- [ ] **S1 · `.env.example` ships `APP_DEBUG=true` + `APP_ENV=local`** 🔴 S — `.env.example:2,6` (CWE-489/209)
  README + CI do `cp .env.example .env`, so operators inherit debug-on. Any unhandled exception (incl. on
  the unauthenticated `/login`) then renders Laravel's error page dumping the **full env** (`APP_KEY`,
  `DB_PASSWORD`, `APP_PASSWORD`) + stack traces to an unauthenticated client. *Highest-impact item.*
  *Fix:* set `APP_ENV=production`, `APP_DEBUG=false` in `.env.example`; optionally a boot guard that refuses
  to serve when `APP_ENV=production && APP_DEBUG=true`. *Attacker:* unauthenticated.

- [ ] **S2 · Pixel-flood / decompression-bomb image OOM-kills the queue worker** 🔴 S — `app/Archive/CoverGenerator.php:30-44` (CWE-400/789)
  `ImageManager(Gd)->decode($bytes)` runs on the first zip image with **no pre-decode dimension/byte guard**;
  `scaleDown()` only runs *after* the full bitmap exists. A few-KB 30000×30000 PNG → ~3.6 GB → OOM. The OOM
  is a fatal (not a catchable `Throwable`), so `ScanLibrary`'s try/catch never runs → scan wedged.
  *Fix:* `getimagesizefromstring($bytes)` first, reject over a pixel budget (~40 MP) via `ArchiveException`
  (which *is* caught); pair with S15 (memory_limit). *Attacker:* crafted zip in `/library`, scan runs.

- [ ] **S3 · No size cap before decompressing a zip entry into memory (zip bomb)** 🔴 M — `app/Archive/ZipPageReader.php:21` (callers PageController:36, CoverGenerator:30) (CWE-409)
  `getFromName()` inflates the whole entry into one PHP string, unbounded. A zip-bomb page entry OOM-kills the
  **web** worker (page path) or **queue** worker (cover path). `ArchiveInspector` is safe (reads sizes only).
  *Fix:* read `statIndex(...)['size']` and refuse entries over a cap (~50 MB) before `getFromName`; for a
  lying central directory, stream via `getStream()` with a hard byte ceiling. *Attacker:* crafted zip.

---

## Tier M — Medium

- [ ] **S4 · No rate-limiting on `POST /login`** 🟠 S — `routes/web.php:24` (CWE-307)
  The whole app sits behind one shared password with no throttle/lockout → unlimited online guessing.
  `hash_equals` stops timing leaks but nothing slows brute-force. *Fix:* `->middleware('throttle:5,1')` on
  the login POST; document a min `APP_PASSWORD` length. *Attacker:* unauthenticated.

- [ ] **S5 · No security response headers** 🟠 S — app-wide (no middleware) (CWE-1021/693)
  No CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`. The reader +
  destructive POSTs (scan/merge/reset) are framable (clickjacking); served image bytes lack `nosniff`.
  *Fix:* a small `SecurityHeaders` middleware appended in `bootstrap/app.php`: `nosniff`, `X-Frame-Options:
  DENY`, `Referrer-Policy`, `Permissions-Policy`, and a **conservative** CSP (`default-src 'self';
  object-src 'none'; base-uri 'self'; frame-ancestors 'none'` + `'unsafe-inline'` on script/style to match
  the inline-Alpine design — do NOT lock down script-src or it breaks the app). *Attacker:* malicious page the user visits / defense-in-depth.

- [ ] **S6 · `relative_path` not confined — symlink/`..` in library names → host-file read** 🟠 S — `app/Scanning/LibraryScanner.php:62,93` → `app/Http/Controllers/PageController.php:36` (CWE-59/22)
  `relative_path` is a raw substring of `glob()` output (never normalized); `glob()` follows symlinks.
  A symlinked folder/zip or `..` in a name lets the scanner index, and PageController serve (naive
  concat, no `realpath` confinement), files outside the read-only `/library`. *Fix:* `realpath()`-confine
  the resolved path in PageController to `library_path`; reject `..`/leading-`/` `relative_path` and skip
  symlinks at scan time. *Attacker:* whoever controls `/library` contents (boundary hardening).

- [ ] **S7 · Worker job has no `$tries` cap / `failed()` handler → stuck-running + crash-loop** 🟠 S — `app/Jobs/ScanLibrary.php:16-55` (CWE-754)
  The try/catch only catches `Throwable`; an OOM/segfault/`--max-time` kill bypasses it, leaving the `scans`
  row `running` forever while the DB queue redelivers (×3) and re-crashes. *Fix:* `public int $tries = 1;`
  + a `failed(Throwable $e)` that marks the in-flight scan `failed`. Pairs with S2/S3/S15.

- [ ] **S8 · Session cookie not `Secure`; plain-HTTP default; `same_site=lax`** 🟠 S — `config/session.php:172,202`, `.env.example` (CWE-614/311)
  Bundled deploy serves plain HTTP on `:8080`; `SESSION_SECURE_COOKIE` unset → the `password_ok` cookie is
  sniffable/replayable on a LAN, bypassing the password. *Fix:* `.env.example` `SESSION_SAME_SITE=strict`;
  document `SESSION_SECURE_COOKIE=true` behind TLS (and recommend a TLS terminator). `http_only` already true.

- [ ] **S9 · ⚠️ Unverified s6-overlay tarballs (no checksum) in image** 🟠 S — `Dockerfile:21-26` (CWE-494)
  `ADD https://…/s6-overlay-*.tar.xz` extracted into `/` as PID-1 init, with no SHA-256/sig check (version
  *is* pinned). *Fix:* fetch the published `.sha256` and `sha256sum -c` (or hardcode known digests) before
  `tar`. ⚠️ Can't build the image here — verify with `docker build`.

- [ ] **S10 · ⚠️ Base images not digest-pinned** 🟠 S — `Dockerfile:4,13,19` (CWE-1357)
  `node:22-alpine`, `composer:2`, `dunglas/frankenphp:1-php8.3` are moving tags (non-reproducible; a re-tag
  ships silently to GHCR). *Fix:* pin `@sha256:…`; let Dependabot bump. ⚠️ Needs registry access to resolve digests.

- [ ] **S11 · ⚠️ GitHub Actions pinned to mutable tags, not SHAs** 🟠 S — `.github/workflows/{build,ci}.yml` (CWE-829)
  `build.yml` runs with `packages: write` + `GITHUB_TOKEN`; a hijacked action `vN` tag could exfiltrate the
  token / poison the pushed image. *Fix:* pin actions to full commit SHAs + enable Dependabot `github-actions`.

---

## Tier L — Low / hardening

- [ ] **S12 · `$guarded = []` on all 6 models — latent mass-assignment footgun** ⚪ M — `app/Models/*` (CWE-915)
  No current call site mass-assigns request input (all create/update arrays are hardcoded whitelists — *verified*),
  so not exploitable today. But any future `->update($request->validated())` instantly becomes a privilege/
  invariant break (forge `content_hash`, pre-set `*_locked`/`is_missing`/`merged_into_id`). *Fix:* explicit
  `$fillable` per model (omit identity/lock/FK columns) + keep 100% tests green.

- [ ] **S13 · App open by default; compose leaves `APP_PASSWORD` empty** ⚪ S — `docker-compose.yml:9`, `RequirePassword.php:15` (CWE-1188)
  Intended single-user behavior, but the compose default nudges toward "exposed & wide open". *Fix:* make it
  fail-safe like `DB_PASSWORD` (`${APP_PASSWORD:?…}`) **or** log a startup warning when the gate is disabled.

- [ ] **S14 · No logout / session invalidation** ⚪ S — `routes/web.php`, `PasswordLoginController` (CWE-613)
  `password_ok` can't be cleared; a stolen session can't be revoked short of rotating `APP_KEY`. *Fix:*
  `POST /logout` → `forget('password_ok')` + `invalidate()` + `regenerateToken()`.

- [ ] **S15 · No explicit `memory_limit` for the container PHP processes** ⚪ S — `Dockerfile` (no php.ini) (CWE-770)
  Sets how violently S2/S3 fail (catchable exception vs cgroup OOM-kill outside the try). *Fix:* a php.ini
  snippet `memory_limit=256M` for web+worker — the backstop that makes S2/S3 degrade gracefully.

- [ ] **S16 · Unbounded zip entry count → DB bloat / slow rehydrate** ⚪ S — `app/Archive/ArchiveInspector.php:36-45` → `entries` JSON col (CWE-770)
  A central directory listing millions of entries inflates memory + the `entries` JSON re-hydrated on every
  page render. *Fix:* cap max image entries in `inspect()`.

- [ ] **S17 · ⚠️ Build-time font fetch from bunny.net** ⚪ M — `vite.config.js:12` (CWE-829)
  `npm run build` downloads "Instrument Sans" from bunny.net (self-hosted into the bundle — good for runtime,
  but a build-time network dep + breaks offline builds). *Fix:* vendor the woff2 files locally.

- [ ] **S18 · `install-php-extensions` unpinned** ⚪ S — `Dockerfile:29` — largely subsumed by S10 (pin FrankenPHP digest). Note only.

- [ ] **S19 · CI lacks `composer validate --strict`** ⚪ S — `.github/workflows/ci.yml` — Info; catches lock/json drift.

- [ ] **S20 · dev-only `shell-quote`/`concurrently` npm advisory** ⚪ S — `package.json:11` — Info; not shipped (image copies only `public/build`). `npm audit fix` when convenient.

- [ ] **S21 · `laravel/framework` one patch behind (13.16.1→13.17.0)** ⚪ S — `composer.lock` — Low hygiene (audit clean). `composer update laravel/framework`.

- [ ] **S22 · Optional container hardening** ⚪ M — `docker-compose.yml` — `--cap-drop=ALL`/`no-new-privileges`/read-only rootfs. Defense-in-depth.

---

## Confirmed safe (audited & cleared — do not re-litigate)
- **CSRF**: all mutating routes are POST in the `web` group; `ValidateCsrfToken` active, no `except` allowlist; `@csrf` on the login form.
- **Auth crypto**: `hash_equals` (timing-safe) + `(string)` casts; session `regenerate()` on login (no fixation); exempt-path check is an allowlist that can't expose a protected route.
- **Path/serving**: `/covers/{hash}` route-constrained to `[0-9a-f]{64}`; `/work/{id}/page/{n}` `whereNumber` + bounds-checked; `entryName` comes from DB (scanner), never the request; cover filename = `content_hash` (no zip-slip write).
- **Injection**: the 3 raw-SQL spots are static fragments with bound params + correct `ESCAPE '!'`; no user input in `selectRaw`; zero `exec/shell_exec/system/proc_open/eval/unserialize`.
- **XSS**: zero `{!! !!}`/`x-html`; all file-derived text via `{{ }}`/`x-text`; the `_cards`→`innerHTML` path is server-rendered escaped Blade; `@js` = `Js::from`; `browseUrl()` uses `http_build_query`; `entries` restricted to image extensions (no HTML served as text/html).
- **Repo hygiene**: `.env` not committed; `database.sqlite` not committed + dockerignored; no secrets in logs; `/health` returns only `{status:ok}`; `composer audit` + `npm audit --omit=dev` clean; lockfiles committed; `allow-plugins` explicit; prod build `--no-dev`.

## Execution notes
- Branch `security/audit-hardening`; atomic commits; TDD/tests after each; keep 100% `app/` coverage.
- Defaults I'll use unless told otherwise: image cap ~40 MP, entry cap ~50 MB, `memory_limit=256M`,
  conservative CSP (no `script-src` lockdown). Merge to `main` locally when green.
