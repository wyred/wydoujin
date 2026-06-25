# wydoujin — LLM Organize API (F5) Design

**Status:** approved (brainstorming, 2026-06-25). New feature **F5** — a machine-facing API.
**Parent spec:** `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §4 (auth/deployment), §5 (data model), §10 (browse surfaces).
**Depends on:** F3a search/facets (`WorkSearch`, `BrowseSearchController`), F3b scan/maintenance (`MaintenanceController`, `ScanLibrary`, `RescanWork`), F3c series management (`SeriesManagementController`), F4 tags (`Tag`, `WorkTagSync`, `WorkTagController`, `TagController`) and the **`series_locked` / `tags_locked` lock contracts** (the API must preserve them exactly).

## 1. Summary

A token-authenticated, stateless JSON API under `/api/v1` so an **LLM agent** can read the library
and help organize it: search/inspect works, read the tag vocabulary, and mutate tags + series +
trigger scans. It exposes the same operations the web UI already performs (F3c series, F4 tags, F3b
scans) over HTTP-JSON instead of session-gated Blade pages.

The trust boundary is a single bearer token in `.env` (`API_TOKEN`). The API is **DB-only** — like
series and tag management it never touches `/library`; every mutation keys off works whose identity
is `content_hash` and honours the existing `tags_locked` / `series_locked` durability contracts.

A **publicly accessible `/llms.txt`** at the site root is the agent's entry point: a short Markdown
doc (the [llmstxt.org](https://llmstxt.org) convention) describing the base URL, the bearer-token
auth scheme, and the available endpoints, so an LLM can self-onboard without the token. It contains
**no secrets** and is reachable even when `APP_PASSWORD` gates the rest of the web UI.

To guarantee the API and the web UI can never drift, the genuinely-shared business logic (series
group/add/ungroup/rename, tag rename/merge, per-work attach/detach/reset) is **extracted into Action
classes** that both the existing web controllers and the new API controllers call. The API adds no
new organize semantics — only a new transport + auth + bulk conveniences.

## 2. Goals & Non-Goals

**Goals**
- A **stateless token-auth API** (`API_TOKEN` in `.env`) separate from the session/password web gate.
- **Read surface** for discovery: search/filter works (reusing `WorkSearch`), work detail, mangaka,
  series, the canonical **tag vocabulary** + counts, and facet counts.
- **Write surface** to organize: per-work tag attach/detach/replace/reset, **bulk** attach/detach,
  global tag rename/merge, series group/add/ungroup/rename, and scan trigger/status + single rescan.
- **One implementation of every invariant** — web + API share Action classes; locks and per-mangaka
  series rules are enforced in one place.
- A **public `/llms.txt`** discovery doc (no auth, no secrets) so agents can self-onboard.
- Consistent JSON via **API Resources**; Laravel-standard validation errors (`{message, errors}`).
- Portable, no new infra, no new DB tables, no migrations.

**Non-Goals (later / out of scope)**
- **Multiple tokens / scopes / per-token rate limits / OAuth** — one shared bearer token, single-user.
- **Page/cover bytes over the API** (the LLM organizes metadata; image streaming stays on the web
  routes) and **reading-progress** writes.
- **Parser/scanner changes**, multi-value splitting, un-merge, tag/series **delete**.
- **OpenAPI/Swagger generation, SDKs, webhooks, pagination cursors** (offset pages are enough).
- A web UI for managing the token (it's an env var, like `APP_PASSWORD`).

## 3. Decisions (locked in brainstorming)

- **Auth = static bearer token.** `Authorization: Bearer <token>` compared to `config('app.api_token')`
  (← `env('API_TOKEN')`) with `hash_equals` (constant-time). Also accept `X-Api-Token: <token>` as a
  convenience. **401** on missing/bad token.
- **Unset token → API disabled (503), not open.** Deliberately the *opposite* of the web rule
  (`APP_PASSWORD` unset → open). The web surface is read-mostly behind a human; this surface is
  write-capable and headless, so "no token configured" must fail closed.
- **Stateless `api` group.** API routes live in a new `routes/api.php`, registered in
  `bootstrap/app.php`, under the `api` middleware group — **no session, no CSRF, and the
  `RequirePassword` web gate does not apply** (it's web-group only). Prefix **`/api/v1`** (versioned).
- **Reuse, don't reimplement.** Extract Action classes from the web controllers; both transports call
  them. The API never re-derives locks/series rules itself.
- **Read uses `WorkSearch`.** `GET /works` is `WorkSearch` over query params (same `q` + 6 tag dims),
  plus organize-oriented filters (`mangaka`, `series`, `untagged`, `tags_locked`, `missing`).
- **Bulk tag ops are first-class.** `POST /api/v1/tags/attach|detach` take `{type,value,work_ids[]}`
  to cut LLM round-trips; each still sets `tags_locked` per work (same contract as a single edit).
- **JSON envelopes via API Resources.** Lists return Laravel's paginator JSON (`data` + `meta`/`links`).
- **No new tables, no migrations.** Only `config/app.php` (`api_token`) + `.env.example` change.
- **`/llms.txt` is a static `public/llms.txt`** — served directly by FrankenPHP, so it bypasses all
  Laravel middleware and is **always public** (independent of `APP_PASSWORD`/`API_TOKEN`). It is the
  canonical llmstxt.org location (`/llms.txt`) and holds documentation only — never the token.

## 4. Architecture

**Stack:** Laravel 13 · API Resources · no new dependencies. New `routes/api.php`, one middleware,
`Api\*` controllers, and extracted Action classes. No schema changes.

### 4.1 Auth & wiring

- `config/app.php`: add `'api_token' => env('API_TOKEN')`.
- `bootstrap/app.php`: register `api: __DIR__.'/../routes/api.php'` in `withRouting(...)`. Laravel
  applies the framework `api` group (no `web` middleware), so `SecurityHeaders`/`RequirePassword`
  (appended to `web`) don't run here.
- **`EnsureApiToken` middleware** (aliased, applied to the `/api/v1` group):
  1. `config('app.api_token')` empty/null → `503 {"message":"API disabled"}`.
  2. Read bearer (`$request->bearerToken()`) or `X-Api-Token`; `hash_equals` vs config →
     mismatch/empty → `401 {"message":"Unauthenticated"}`.
- JSON everywhere: requests should send `Accept: application/json`; the existing
  `shouldRenderJsonWhen(expectsJson)` plus an explicit `Accept` on the group force JSON error bodies
  for validation (422), model-not-found (404), and aborts.

### 4.2 Shared Action classes (single source of truth)

Extract the current inline controller logic into `app/Actions/` so web + API share it verbatim
(the web controllers are refactored to call these; behaviour and tests stay green):

- `App\Actions\Tags\AttachWorkTag` — `(Work,$type,$value)`: `Tag::canonicalIdFor` →
  `syncWithoutDetaching` → set `tags_locked`. Returns the canonical tag id.
- `App\Actions\Tags\DetachWorkTag` — `(Work,$tagId)`: guarded detach + `tags_locked`.
- `App\Actions\Tags\ResetWorkTags` — `(Work)`: clear lock + `WorkTagSync::sync`.
- `App\Actions\Tags\RenameTag` / `MergeTag` — the current `TagController` rename/merge bodies
  (tombstone creation; transactional repoint + chain-flatten in `mergeInto`).
- `App\Actions\Series\{GroupWorks,AddWorksToSeries,UngroupWorks,RenameSeries}` — the
  `SeriesManagementController` bodies incl. the `sameMangakaWorks` guard and `pruneEmptyAuto`.
- Scans reuse `MaintenanceController`'s dedupe-then-dispatch logic (extract `TriggerScan` action) and
  `RescanWork::dispatch`.

Bulk wrappers (`POST /tags/attach|detach`) iterate `AttachWorkTag`/`DetachWorkTag` over validated
`work_ids` inside a transaction.

### 4.3 API Resources (response shapes)

- **`WorkResource`** — `id`, `content_hash`, `mangaka` (id, name, slug), `series` (id, name, nullable),
  `filename`, `title`, `title_raw`, `page_count`, `is_missing`, `tags_locked`, `series_locked`,
  `tags` grouped by type `{circle:[…],parody:[…],…}` where each is `{id,value}`, and `progress`
  (`current_page`, `is_completed`) when loaded. URLs for cover/read/pages included for convenience.
- **`TagResource`** — `id`, `type`, `value`, `works_count` (when counted).
- **`MangakaResource`** — `id`, `name`, `slug`, `works_count`, `series_count`.
- **`SeriesResource`** — `id`, `name`, `is_auto`, `mangaka_id`, `works` (when loaded).
- **`ScanResource`** — mirrors `MaintenanceController::serialize` (`id,status,triggered_by,stats,*_at`).

All eager-load `Work::CARD_RELATIONS` (+ `mangaka`,`series`) to avoid N+1.

### 4.4 Endpoints (`/api/v1`, all behind `EnsureApiToken`)

**Read**
| Method & path | Purpose |
|---|---|
| `GET /works` | Search/list. Params: `q`, `circle[]`,`parody[]`,`event[]`,`author[]`,`flag[]`,`theme[]` (→ `WorkSearch`), plus `mangaka` (id/slug), `series` (id), `untagged` (no tags), `tags_locked` (bool), `missing` (bool), `page`, `per_page` (≤100). Paginated `WorkResource`. |
| `GET /works/{work}` | One work, full detail. |
| `GET /mangaka` | Paginated mangaka with counts. |
| `GET /mangaka/{mangaka}` | Mangaka + its series + works. |
| `GET /series/{series}` | Series + works (reading order). |
| `GET /tags` | Canonical tags (the vocabulary). Params: `type`, `q`, `page`. `works_count` each. |
| `GET /facets` | Dynamic facet counts for a given filter (reuse `WorkSearch::facets`). |

**Write — per-work tags**
| `POST /works/{work}/tags` | Attach `{type,value}` → `AttachWorkTag`. `201`. |
| `PUT /works/{work}/tags` | Replace the work's whole set: `{tags:[{type,value}…]}` → resolve canon, `sync`, set `tags_locked`. |
| `DELETE /works/{work}/tags` | Detach `{tag_id}` **or** `{type,value}` → `DetachWorkTag`. |
| `POST /works/{work}/tags/reset` | `ResetWorkTags` (unlock + re-derive). |

**Write — bulk tags**
| `POST /tags/attach` | `{type,value,work_ids[]}` attach to many. |
| `POST /tags/detach` | `{type,value,work_ids[]}` detach from many. |

**Write — global tags**
| `PATCH /tags/{tag}` | Rename `{value}` → `RenameTag` (tombstone; merge if target exists). |
| `POST /tags/{tag}/merge` | `{into_id}` → `MergeTag`. |

**Write — series** (per-mangaka invariant enforced by the shared guard)
| `POST /series` | Group `{work_ids[],name}` → new manual series. `201` `{series_id}`. |
| `POST /series/{series}/works` | Add `{work_ids[]}`. |
| `DELETE /series/works` | Ungroup `{work_ids[]}` → standalone. |
| `PATCH /series/{series}` | Rename `{name}`. |

**Write — maintenance**
| `POST /scan` | Trigger a full scan (dedupes an active scan). `202` `{scan}`. |
| `GET /scan` | Latest scan status. |
| `POST /works/{work}/rescan` | Queue a single-work rescan. `202`. |

### 4.5 Discovery — `public/llms.txt`

A hand-maintained Markdown file at `public/llms.txt`, served at `https://host/llms.txt`. Per the
llmstxt.org convention it leads with an `# wydoujin API` H1 + a one-line blockquote summary, then
sections covering:
- **Base URL** (`{APP_URL}/api/v1`) and required headers (`Authorization: Bearer <API_TOKEN>`,
  `Accept: application/json`).
- **Auth note:** the token is configured server-side in `.env`; the file never contains it, and a
  missing server token means the API is disabled (`503`).
- **Endpoint list** (the §4.4 table, condensed) with the request bodies for the write ops.
- **Workflow** — the discovery→organize loop (§5) and the lock/merge-alias durability notes.

It is **static** (no route, no PHP) so it stays reachable when `APP_PASSWORD` gates the app. It is
plain documentation; keeping it in step with §4.4 is a checklist item whenever endpoints change.

## 5. Client contract & examples

- **Base:** `https://host/api/v1`. **Headers:** `Authorization: Bearer $API_TOKEN`,
  `Accept: application/json`, and `Content-Type: application/json` for bodies.
- **Onboarding:** an agent first fetches `https://host/llms.txt` (no auth) to learn the base URL,
  auth header, and endpoints, then proceeds with the token.
- **Discovery → organize loop** (the intended LLM workflow):
  1. `GET /works?untagged=1&mangaka=ズッキーニ` → find unorganized works.
  2. `GET /tags?type=theme&q=…` → check existing vocabulary before inventing values.
  3. `POST /tags/attach {type:"theme",value:"netorare",work_ids:[12,15]}` → bulk-tag.
  4. `POST /series {work_ids:[12,13],name:"四畳半物語"}` → group a series.
  5. `PATCH /tags/{id} {value:"NTR"}` or `POST /tags/{id}/merge {into_id:…}` → tidy the vocabulary.
- **Errors:** `401` (bad token), `503` (token unset), `404` (unknown id), `422` (validation /
  invariant violations — same messages the web ops already return, e.g. "Works span multiple
  mangaka.", "Target is an alias."). All JSON.

## 6. Edge cases

- **Token unset** → every `/api/v1/*` returns `503` (fail-closed); **bad/missing token** → `401`.
- **Per-mangaka series:** `work_ids` spanning mangaka, or an unknown work id → `422` (shared
  `sameMangakaWorks` guard), identical to the web path.
- **Tags:** attaching a tombstoned `(type,value)` resolves to canonical; rename onto an existing
  canonical → merge; merge guards (`from≠into`, same type, target canonical) → `422`; detach of a tag
  the work lacks → `422` (single) / no-op within a bulk op; chains stay one hop.
- **Locks honoured:** any tag edit sets `tags_locked`; any series op sets `series_locked` — so a later
  scan never undoes API changes (the whole point of reusing the Actions).
- **`PUT /works/{work}/tags`** with `tags:[]` clears manual tags **but still locks** (explicit "no
  tags", not "revert"); `reset` is the only path back to auto-derivation.
- **Bulk ops** validate every `work_id` up front and run in one transaction (all-or-nothing).
- **`per_page` clamped** to 1..100; `page` defaults to 1; unknown query params ignored.
- **Scan dedupe:** `POST /scan` while one is queued/running returns the active scan (`202`), never a
  second job (mirrors `MaintenanceController::scan`).
- **Japanese values** pass through as UTF-8 JSON (no slugging); `value` is trimmed, empty → `422`.
- **`/llms.txt`** is public even with `APP_PASSWORD` set (static file), contains **no token/secret**,
  and is served as Markdown (`text/markdown`/`text/plain`); it is documentation only — never an
  auth bypass for `/api/v1` (those still require the bearer token).

## 7. Migrations

**None.** No schema change — the API is a new transport over the F3/F4 data model. Only config
(`config/app.php` `api_token`), `.env.example` (`API_TOKEN=` with a "unset → API disabled" note),
and a new static `public/llms.txt` change. The token is read from env exactly like `APP_PASSWORD`.

## 8. Testing strategy

- **Auth matrix** (`tests/Feature/Api/AuthTest`): token unset → `503`; missing → `401`; wrong →
  `401`; correct (bearer **and** `X-Api-Token`) → `200`; web routes still session-gated (regression).
- **Read endpoints:** `GET /works` honours `q` + each facet dim + `untagged`/`tags_locked`/`missing`
  + pagination/`per_page` clamp; `WorkResource` shape (tags grouped by type, content_hash, progress);
  `tags`/`facets`/`mangaka`/`series` shapes and counts.
- **Write — tags:** attach/replace/detach/reset set `tags_locked` and survive a simulated rescan;
  bulk attach/detach over many works is transactional and all set the lock; rename creates a
  tombstone the scanner resolves; merge repoints+de-dupes+flattens and the value leaves facets;
  validation/guard `422`s — **assert the API path reuses the same Actions as the web path** (shared
  behaviour, no drift).
- **Write — series:** group/add/ungroup/rename set `series_locked`, enforce the per-mangaka guard,
  and prune empty auto series — parity with `SeriesManagementController` tests.
- **Maintenance:** `POST /scan` dedupes (no second job when active); `GET /scan` shape;
  `POST /works/{id}/rescan` queues `RescanWork` (assert dispatched, `202`).
- **Refactor safety:** the existing `WorkTagControllerTest` / `TagController` / series / maintenance
  feature tests stay green after the Action extraction (they pin the web side).
- **Coverage:** maintain **100% line coverage** of `app/` (the new controllers/actions/resources +
  middleware) via PCOV, matching the project bar. Tests run on in-memory SQLite like CI; no browser
  suite needed (no Alpine/UI in this feature).
- **`/llms.txt`:** a test asserts the file exists, leads with the `# wydoujin API` H1, documents the
  `Authorization: Bearer` scheme + `/api/v1` base, lists the core endpoints, and **contains no token**
  (guards against leaking `API_TOKEN`). (A static-file assertion, since FrankenPHP serves it directly.)

## 9. Out of scope (later)

Multiple/rotating tokens, scopes & per-token rate limiting, OAuth; page/cover bytes + reading-progress
over the API; tag/series **delete** and un-merge; parser multi-value splitting; OpenAPI/SDK
generation, webhooks, cursor pagination; a token-management UI.
