# wydoujin — LLM Organize API (F5) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a stateless, token-authenticated JSON API under `/api/v1` so an LLM agent can read the library (search works, inspect mangaka/series/tags/facets) and organize it (per-work + bulk tags, global tag rename/merge, series group/add/ungroup/rename, scan + rescan) — reusing the F3/F4 logic so no invariant ever drifts — plus a public `/llms.txt` discovery doc.

**Architecture:** A new `routes/api.php` (registered in `bootstrap/app.php`) under the stateless `api` group, `/api/v1` prefix, every route behind an `EnsureApiToken` middleware (bearer `API_TOKEN`; unset → 503, bad/missing → 401). The web controllers' organize logic is extracted into `app/Actions/**` classes; the existing web controllers AND the new `App\Http\Controllers\Api\**` controllers both call them. Responses go through API Resources. A static `public/llms.txt` documents the API for agents.

**Tech Stack:** Laravel 13, PHP 8.3+ · API Resources · no new dependencies, no schema changes, no migrations.

**Spec:** `docs/superpowers/specs/2026-06-25-wydoujin-llm-api-design.md`. Parent: `docs/superpowers/specs/2026-06-21-wydoujin-design.md` §4, §5, §10.

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+ (local dev 8.5). No `declare(strict_types=1)`.
- **Broken local toolchain:** prefix EVERY php/artisan/composer command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5). Env doesn't persist between Bash calls — repeat it. Tests via `php artisan test`. Node/npm on the normal PATH.
- **Avoid `cd` in compound bash;** use absolute paths / `git -C`.
- **Commit trailer:** every commit ends with a blank line then exactly:
  `Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>`
- **PHP style:** single quotes unless interpolation; inline typed properties; short **bilingual (EN / JP)** doc comments on new classes/methods. `final` on new controllers/actions (match the codebase).
- **NO DRIFT (mandatory):** the API must not re-implement organize logic. Extract Actions, then make BOTH transports call them. The existing web feature tests (`tests/Feature/...` for tags/series/maintenance) MUST stay green after each refactor — run them every task.
- **THE LOCK CONTRACTS (mandatory):** every tag write sets `works.tags_locked=true` (reset clears it + re-derives via `WorkTagSync`); every series write sets `works.series_locked=true` (+ `is_auto=false` on touched series, prune empty auto). A scan/redetect after an API write MUST leave the change intact — assert this.
- **Read-only library, DB-only:** never write to `/library` — all changes are DB rows.
- **Per-mangaka series:** every series op validates all `work_ids` share one `mangaka_id` (422 otherwise) via the shared guard.
- **Auth is the API's own gate:** `/api/v1/*` is **NOT** under the `web` group, so `RequirePassword`/`SecurityHeaders` (web-appended) don't apply. Auth is `EnsureApiToken` only. **Token unset → 503 (fail-closed).**
- **DB portability:** Eloquent only, no raw SQL beyond the existing `ESCAPE '!'` LIKE (reused via `WorkSearch`). Feature tests use `RefreshDatabase` (in-memory SQLite).
- **JSON:** API tests use `getJson`/`postJson`/`patchJson`/`deleteJson` with the auth header. Errors are Laravel-standard (`{message, errors}`); 401/503/404/422 per spec.
- **Workflow:** TDD, DRY, YAGNI, bite-sized commits — one task ≈ one commit.

## Scope Decisions (locked, per spec)

1. **Auth = static bearer `API_TOKEN`** (also `X-Api-Token`), `hash_equals`; unset → 503; bad/missing → 401.
2. **`/api/v1`** prefix, stateless `api` group, new `routes/api.php`.
3. **Reuse via Actions** — web + API share `app/Actions/**`; no new organize semantics.
4. **Read** = `GET` works/works·show/mangaka/mangaka·show/series·show/tags/facets (works search via `WorkSearch`).
5. **Write** = per-work tags (attach/replace/detach/reset), bulk tags (attach/detach), global tags (rename/merge), series (group/add/ungroup/rename), maintenance (scan/status/rescan).
6. **`/llms.txt`** = static `public/llms.txt`, public, no secrets.
7. **Out of scope:** multiple tokens/scopes/rate-limits/OAuth; page/cover bytes + progress over API; tag/series delete + un-merge; OpenAPI/SDK/webhooks/cursor pagination; token-management UI.

## File Structure

- `config/app.php` — **modify**. Add `'api_token' => env('API_TOKEN')`.
- `bootstrap/app.php` — **modify**. Register `api: __DIR__.'/../routes/api.php'` in `withRouting`.
- `routes/api.php` — **create**. `/api/v1` group behind `EnsureApiToken` + `SubstituteBindings`.
- `app/Http/Middleware/EnsureApiToken.php` — **create**.
- `app/Http/Resources/{Work,Tag,Mangaka,Series,Scan}Resource.php` — **create**.
- `app/Actions/Tags/{AttachWorkTag,DetachWorkTag,ResetWorkTags,RenameTag,MergeTag}.php` — **create**.
- `app/Actions/Series/{GroupWorks,AddWorksToSeries,UngroupWorks,RenameSeries}.php` — **create**.
- `app/Actions/Maintenance/TriggerScan.php` — **create**.
- `app/Http/Controllers/Api/{Work,Mangaka,Series,Tag,WorkTag,BulkTag,Facet,Scan}Controller.php` — **create**.
- `app/Http/Controllers/{WorkTagController,TagController,SeriesManagementController,MaintenanceController}.php` — **modify** (delegate to Actions).
- `public/llms.txt` — **create**.
- `.env.example` — **modify**. Add `API_TOKEN=` + note.
- `tests/Feature/Api/*` — **create** per task.

**Reference — existing shapes (verbatim, do not re-derive):**

- `App\Models\Tag`: `TYPES = [circle,parody,event,author,flag,theme]`; `AUTO_TYPES`; `SCALAR_TYPES`; `canonicalIdFor(string $type,string $value): int` (firstOrCreate + alias-resolve, race-safe); `scopeCanonical` (`whereNull('merged_into_id')`); `works()` BelongsToMany via `work_tag`; `aliases()` HasMany; `browseUrl()`. `$guarded=[]`, cast `merged_into_id` int; `sort_value` auto-derived on create.
- `App\Models\Work`: `CARD_RELATIONS = ['readingProgress','tags']`; `$guarded=[]`; casts incl. `is_missing`/`series_locked`/`tags_locked` bool; `mangaka()`,`series()`,`readingProgress()`,`tags()`; scopes `present()` (`is_missing=false`), `missing()`. Columns incl. `content_hash`,`filename`,`title`,`title_raw`,`page_count`.
- `App\Models\Mangaka`: `$table='mangaka'`; `works()`,`series()`; columns `name`,`slug`.
- `App\Models\Series`: casts `is_auto` bool; `mangaka()`,`works()`; `Series::pruneEmptyAuto(int $mangakaId): int`.
- `App\Models\Scan`: scopes `active()` (queued|running), `latest()`; columns `status`,`triggered_by`,`stats`,`started_at`,`finished_at`. `MaintenanceController::serialize` is the canonical scan shape.
- `App\Browse\WorkSearch`: ctor `(?string $q, array $circle,$parody,$event,$author,$flag,$theme)`; `fromRequest(Request)`; `results(int $page=1,int $perPage=60): LengthAwarePaginator`; `facets(): array<string,list<{value,count}>>`. `DIMENSIONS = Tag::TYPES`.
- `App\Tagging\WorkTagSync`: `sync(Work, ?ParsedName=null): void` (no-op when `tags_locked`); `pruneOrphans(): int`. Resolve via DI (`app(WorkTagSync::class)`).
- `App\Jobs\RescanWork::dispatch(int $workId)`; `App\Jobs\ScanLibrary::dispatch(string $triggeredBy, ?int $scanId=null)`.
- Current web controller bodies to extract (verbatim logic): `WorkTagController` (attach/detach/reset/suggest), `TagController` (rename/merge/mergeInto), `SeriesManagementController` (group/add/ungroup/rename/sameMangakaWorks), `MaintenanceController` (scan/status/serialize).
- `bootstrap/app.php` current `withRouting(web:..., commands:...)` + `withMiddleware` (trustProxies + web append) + `withExceptions(shouldRenderJsonWhen expectsJson)`.

---

## Task 1: Token auth foundation + first read endpoint (`GET /api/v1/works`)

**Files:** create `app/Http/Middleware/EnsureApiToken.php`, `routes/api.php`, `app/Http/Resources/WorkResource.php`, `app/Http/Controllers/Api/WorkController.php`, `tests/Feature/Api/AuthTest.php`, `tests/Feature/Api/WorkReadTest.php`; modify `config/app.php`, `bootstrap/app.php`.

**Interfaces produced:** `GET /api/v1/works` (paginated `WorkResource`); the `EnsureApiToken` gate; `config('app.api_token')`.

- [ ] **Step 1: Failing tests**

`tests/Feature/Api/AuthTest.php` — table-drives the auth matrix against `GET /api/v1/works`:
```php
<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_when_token_unset_returns_503(): void
    {
        config(['app.api_token' => null]);
        $this->getJson('/api/v1/works')->assertStatus(503);
    }

    public function test_missing_token_returns_401(): void
    {
        config(['app.api_token' => 'secret']);
        $this->getJson('/api/v1/works')->assertStatus(401);
    }

    public function test_wrong_token_returns_401(): void
    {
        config(['app.api_token' => 'secret']);
        $this->getJson('/api/v1/works', ['Authorization' => 'Bearer nope'])->assertStatus(401);
    }

    public function test_bearer_token_authenticates(): void
    {
        config(['app.api_token' => 'secret']);
        $this->getJson('/api/v1/works', ['Authorization' => 'Bearer secret'])->assertOk();
    }

    public function test_x_api_token_header_authenticates(): void
    {
        config(['app.api_token' => 'secret']);
        $this->getJson('/api/v1/works', ['X-Api-Token' => 'secret'])->assertOk();
    }

    public function test_web_routes_stay_session_gated(): void // regression: api gate ≠ web gate
    {
        config(['app.password' => 'pw', 'app.api_token' => 'secret']);
        $this->get('/')->assertRedirect('/login');
    }
}
```

`tests/Feature/Api/WorkReadTest.php` — seed a couple of works+tags (reuse/borrow the series `SeedsMangakaWorks` pattern or seed directly), set `config(['app.api_token'=>'t'])`, header helper, then assert: `GET /api/v1/works` returns `data[]` with `WorkResource` keys (`id,content_hash,title,tags` grouped by type, `mangaka`, `tags_locked`), `meta` pagination; `?q=` filters; `?per_page=200` is clamped to ≤100.

- [ ] **Step 2: Implement**

`config/app.php`: add near `'password'`:
```php
'api_token' => env('API_TOKEN'),
```

`app/Http/Middleware/EnsureApiToken.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer-token gate for /api/v1. Unset token → 503 (fail-closed). / APIトークン認証。 */
class EnsureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('app.api_token');
        if ($token === null || $token === '') {
            return response()->json(['message' => 'API disabled'], 503);
        }

        $presented = $request->bearerToken() ?: $request->header('X-Api-Token', '');
        if (! is_string($presented) || $presented === '' || ! hash_equals((string) $token, $presented)) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return $next($request);
    }
}
```

`bootstrap/app.php`: add to `withRouting(...)`:
```php
api: __DIR__.'/../routes/api.php',
```
(Default `apiPrefix` `api` + the framework `api` group, which includes `SubstituteBindings` for route-model binding. No `web` middleware here.)

`routes/api.php`:
```php
<?php

use App\Http\Controllers\Api\WorkController;
use App\Http\Middleware\EnsureApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(EnsureApiToken::class)->group(function (): void {
    Route::get('/works', [WorkController::class, 'index']);
    // (later tasks append the rest here)
});
```

`app/Http/Resources/WorkResource.php` — group tags by type:
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** JSON shape of a work for the API. / 作品のAPI表現。 */
class WorkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content_hash' => $this->content_hash,
            'filename' => $this->filename,
            'title' => $this->title,
            'title_raw' => $this->title_raw,
            'page_count' => $this->page_count,
            'is_missing' => (bool) $this->is_missing,
            'tags_locked' => (bool) $this->tags_locked,
            'series_locked' => (bool) $this->series_locked,
            'mangaka' => $this->whenLoaded('mangaka', fn () => [
                'id' => $this->mangaka->id, 'name' => $this->mangaka->name, 'slug' => $this->mangaka->slug,
            ]),
            'series' => $this->whenLoaded('series', fn () => $this->series ? [
                'id' => $this->series->id, 'name' => $this->series->name,
            ] : null),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags
                ->groupBy('type')
                ->map(fn ($g) => $g->map(fn ($t) => ['id' => $t->id, 'value' => $t->value])->values())),
            'progress' => $this->whenLoaded('readingProgress', fn () => $this->readingProgress ? [
                'current_page' => $this->readingProgress->current_page,
                'is_completed' => (bool) $this->readingProgress->is_completed,
            ] : null),
        ];
    }
}
```

`app/Http/Controllers/Api/WorkController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Browse\WorkSearch;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkResource;
use Illuminate\Http\Request;

final class WorkController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 60)));
        $page = max(1, (int) $request->integer('page', 1));
        $paginator = WorkSearch::fromRequest($request)->results($page, $perPage)
            ->loadMissing('mangaka', 'series'); // CARD_RELATIONS already eager via results()

        return WorkResource::collection($paginator);
    }
}
```
> Note: `WorkSearch::results()` eager-loads `Work::CARD_RELATIONS` (`readingProgress`,`tags`). Add `mangaka`/`series` for the resource. Extra filters (`mangaka`,`series`,`untagged`,`tags_locked`,`missing`) land in **Task 2** (extend `WorkSearch` or wrap in the controller — see Task 2).

- [ ] **Step 3: Green + regression**
  - `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=Api` → Task-1 tests pass.
  - Full suite green (nothing else touched).
- [ ] **Step 4: Commit** — `Add token-authenticated API foundation and GET /api/v1/works`.

---

## Task 2: Remaining read endpoints + Resources + facets

**Files:** create `app/Http/Resources/{Tag,Mangaka,Series}Resource.php`, `app/Http/Controllers/Api/{MangakaController,SeriesController,TagController,FacetController}.php`, `tests/Feature/Api/ReadEndpointsTest.php`; modify `routes/api.php`, `app/Http/Controllers/Api/WorkController.php` (add `show` + extra filters).

**Interfaces produced:** `GET /api/v1/works/{work}`, `/mangaka`, `/mangaka/{mangaka}`, `/series/{series}`, `/tags`, `/facets`.

- [ ] **Step 1: Failing tests** (`ReadEndpointsTest`): each endpoint shape + filters:
  - `GET /works/{work}` → full `WorkResource` (mangaka+series+tags+progress loaded).
  - `GET /works?untagged=1` → only works with no tags; `?tags_locked=1`; `?missing=1`; `?mangaka={id|slug}`; `?series={id}`.
  - `GET /mangaka` → `data[]` with `works_count`,`series_count`.
  - `GET /mangaka/{mangaka}` → mangaka + `series[]` + `works[]`.
  - `GET /series/{series}` → series + `works[]`.
  - `GET /tags?type=circle&q=Z` → canonical tags only (tombstones excluded), `works_count` each.
  - `GET /facets?circle[]=...` → `{circle:[{value,count}],parody:[...],...}` (reuse `WorkSearch::facets`).

- [ ] **Step 2: Implement**
  - **Extra `works` filters:** add private helpers in `Api\WorkController::index` applied to the `WorkSearch` paginator is awkward (it returns a paginator). Cleaner: have the controller build on `WorkSearch` for `q`+facets, then post-filter is wrong. **Decision:** extend the controller to apply `mangaka/series/untagged/tags_locked/missing` by querying `Work` directly when those are present, OR add optional constraints to `WorkSearch`. **Chosen:** add a thin `WorkSearch::query()` accessor is over-engineering — instead, in the API controller, when extra filters are set, wrap: start from `WorkSearch::fromRequest($request)` for `q`+facets via a new `WorkSearch::builder(): Builder` method (extract the `applyFacets(base())` into a public `builder()`), then chain `->when($mangaka)`, `->when(...)`, `->paginate()`. Add `builder()` to `WorkSearch` (returns `applyFacets($this->base())`) — keep `results()` delegating to it. Update existing `WorkSearch` tests stay green.
  - `MangakaResource` (`id,name,slug,works_count,series_count` via `withCount`), `SeriesResource` (`id,name,is_auto,mangaka_id`, `works` whenLoaded), `TagResource` (`id,type,value,works_count` whenCounted).
  - Controllers: `MangakaController@index` (`Mangaka::withCount(['works','series'])->orderBy('name')->paginate`), `@show` (load `series.works`,`works`); `SeriesController@show` (`$series->load('works')`); `TagController@index` (`Tag::canonical()->whereHas('works')->withCount('works')->when(type)->when(q via ESCAPE '!')->orderBy('type')->orderBy('sort_value')->paginate`); `FacetController@index` (`WorkSearch::fromRequest($request)->facets()`).
  - Append all routes to `routes/api.php` (with `{work}`,`{mangaka}`,`{series}` bindings; `{mangaka:slug}`-or-id → bind by id, resolve slug fallback in controller, OR accept id only — **accept id for works/series, id-or-slug for mangaka** via `Route::bind` is overkill; accept **id** everywhere for determinism and let `?mangaka=` on `/works` accept id-or-slug).

- [ ] **Step 3:** Task-2 tests + full suite green (esp. `WorkSearch`/browse tests after the `builder()` extraction).
- [ ] **Step 4: Commit** — `Add read endpoints for works, mangaka, series, tags, facets`.

---

## Task 3: Extract tag Actions + refactor web controllers (no behaviour change)

**Files:** create `app/Actions/Tags/{AttachWorkTag,DetachWorkTag,ResetWorkTags,RenameTag,MergeTag}.php`; modify `app/Http/Controllers/WorkTagController.php`, `app/Http/Controllers/TagController.php`.

**Interfaces produced:** invokable/`handle` Actions encapsulating the existing logic. **No route or behaviour change.**

- [ ] **Step 1:** No new tests — the **existing** `tests/Feature/...` tag tests are the safety net. (Optionally add a tiny `tests/Unit/Actions/...` happy-path for each.)
- [ ] **Step 2: Implement** — move logic verbatim:
  - `AttachWorkTag::handle(Work $work, string $type, string $value): int` — trim+validate value non-empty (throw `ValidationException`/`abort(422)`), `Tag::canonicalIdFor`, `syncWithoutDetaching`, `tags_locked=true`, return id.
  - `DetachWorkTag::handle(Work $work, int $tagId): void` — guard `abort_unless($work->tags()->where('tags.id',$tagId)->exists(),422)`, detach, lock.
  - `ResetWorkTags::handle(Work $work): void` — `tags_locked=false`, `app(WorkTagSync::class)->sync($work)`.
  - `RenameTag::handle(Tag $tag, string $value): void` — the `TagController::rename` body (guard alias, trim, no-op if same, delegate to `MergeTag` if a canonical target exists, else update + tombstone).
  - `MergeTag::handle(Tag $from, Tag $into): void` — the `mergeInto` transaction (guards live in the caller: `from≠into`, same type, target canonical).
  - Refactor `WorkTagController`/`TagController` to call the Actions (keep their request validation + JSON responses intact).
- [ ] **Step 3:** Full suite green (web tag tests unchanged).
- [ ] **Step 4: Commit** — `Extract tag operations into reusable Action classes`.

---

## Task 4: Per-work + bulk tag API endpoints

**Files:** create `app/Http/Controllers/Api/WorkTagController.php`, `app/Http/Controllers/Api/BulkTagController.php`, `tests/Feature/Api/WorkTagApiTest.php`; modify `routes/api.php`.

**Interfaces produced:** `POST /works/{work}/tags` (attach), `PUT /works/{work}/tags` (replace set), `DELETE /works/{work}/tags` (detach by `tag_id` or `type`+`value`), `POST /works/{work}/tags/reset`, `POST /tags/attach`, `POST /tags/detach`.

- [ ] **Step 1: Failing tests** — assert each sets `tags_locked`; replace `sync`s the exact set; detach by both id and (type,value); reset unlocks + re-derives from filename; bulk attach/detach over many `work_ids` is transactional and locks each; **a rescan after a write leaves it intact** (`app(WorkTagSync::class)->sync` skips locked); validation 422 (bad type, empty value, foreign tag_id, unknown work_id in bulk).
- [ ] **Step 2: Implement** controllers delegating to the Task-3 Actions:
  - attach → `AttachWorkTag` (201 `{tag_id}`); reset → `ResetWorkTags`; detach → resolve `tag_id` (or `Tag::canonicalIdFor(type,value)` then ensure on work) → `DetachWorkTag`.
  - `PUT` replace: validate `tags:[{type,value}]`, map each to `Tag::canonicalIdFor`, `$work->tags()->sync($ids)`, `tags_locked=true`.
  - Bulk: validate `type∈TYPES`, `value` non-empty, `work_ids:array<int>` all exist (`Work::whereIn('id',$ids)` count match → else 422); `DB::transaction` looping the Action.
  - Append routes.
- [ ] **Step 3:** Task-4 + full suite green.
- [ ] **Step 4: Commit** — `Add per-work and bulk tag write endpoints`.

---

## Task 5: Global tag + series API endpoints (+ extract series Actions)

**Files:** create `app/Actions/Series/{GroupWorks,AddWorksToSeries,UngroupWorks,RenameSeries}.php`, `app/Http/Controllers/Api/{TagController,SeriesController}` write methods (extend the Task-2 read controllers or add `Api\SeriesMutationController`), `tests/Feature/Api/{TagApiTest,SeriesApiTest}.php`; modify `routes/api.php`, `app/Http/Controllers/SeriesManagementController.php`.

**Interfaces produced:** `PATCH /tags/{tag}` (rename), `POST /tags/{tag}/merge`; `POST /series`, `POST /series/{series}/works`, `DELETE /series/works`, `PATCH /series/{series}`.

- [ ] **Step 1: Failing tests:**
  - Tags: `PATCH /tags/{tag}` renames (tombstone created; a later `WorkTagSync` resolves old→new); rename onto existing canonical → merge; `POST /tags/{tag}/merge` repoints+de-dupes+flattens, guards 422 (`from≠into`, same type, target canonical).
  - Series: group/add/ungroup/rename set `series_locked` + `is_auto=false`, prune empty auto, enforce per-mangaka guard (422 spanning/unknown), and **survive `SeriesDetectorContract::detect()`**.
- [ ] **Step 2: Implement:**
  - Extract `SeriesManagementController` bodies into `app/Actions/Series/*` (incl. the `sameMangakaWorks` guard as a shared static/util — e.g. `App\Actions\Series\SameMangakaWorks::resolve(array $ids): Collection`). Refactor the web controller to call them (web series tests stay green).
  - API tag write controller delegates to `RenameTag`/`MergeTag` (with the merge guards) from Task 3.
  - API series controller delegates to the new series Actions. `DELETE /series/works` reads `work_ids` from the JSON body.
  - Append routes.
- [ ] **Step 3:** Task-5 + full suite green (web series + tag tests unchanged).
- [ ] **Step 4: Commit** — `Add global tag and series organize endpoints`.

---

## Task 6: Maintenance endpoints (scan / status / rescan)

**Files:** create `app/Actions/Maintenance/TriggerScan.php`, `app/Http/Resources/ScanResource.php`, `app/Http/Controllers/Api/ScanController.php`, `tests/Feature/Api/ScanApiTest.php`; modify `routes/api.php`, `app/Http/Controllers/MaintenanceController.php` (delegate scan-trigger), `app/Http/Controllers/Api/WorkController.php` (add `rescan`).

**Interfaces produced:** `POST /scan` (202 `{scan}`, dedupes active), `GET /scan` (latest), `POST /works/{work}/rescan` (202).

- [ ] **Step 1: Failing tests** (`Bus::fake()`): `POST /scan` with no active scan dispatches `ScanLibrary` + returns the queued `ScanResource`; with an active scan returns the active one and dispatches nothing; `GET /scan` shape; `POST /works/{id}/rescan` dispatches `RescanWork::class` with the id (202).
- [ ] **Step 2: Implement** — `TriggerScan::handle(): Scan` = `MaintenanceController::scan` body (active-check → create `queued` + `ScanLibrary::dispatch`); refactor web `scan()` to use it. `ScanResource` mirrors `serialize`. `Api\ScanController@store`/`@show`; `Api\WorkController@rescan` → `RescanWork::dispatch($work->id)`.
- [ ] **Step 3:** Task-6 + full suite green.
- [ ] **Step 4: Commit** — `Add scan trigger, status, and single-work rescan endpoints`.

---

## Task 7: `public/llms.txt` + env + docs

**Files:** create `public/llms.txt`, `tests/Feature/Api/LlmsTxtTest.php`; modify `.env.example`.

- [ ] **Step 1: Failing test** (`LlmsTxtTest`): the file exists; starts with `# wydoujin API`; mentions `Authorization: Bearer`, `/api/v1`, and at least `GET /works`, `POST /tags/attach`, `POST /series`; and **does NOT contain** the literal token / `API_TOKEN=` value (leak guard — assert it doesn't include any `Bearer <something-that-looks-like-a-real-secret>`; simplest: assert it doesn't contain `config('app.api_token')` when set).
```php
public function test_llms_txt_documents_the_api_without_secrets(): void
{
    $path = public_path('llms.txt');
    $this->assertFileExists($path);
    $txt = file_get_contents($path);
    $this->assertStringStartsWith('# wydoujin API', $txt);
    foreach (['Authorization: Bearer', '/api/v1', 'GET /works', 'POST /tags/attach', 'POST /series'] as $needle) {
        $this->assertStringContainsString($needle, $txt);
    }
    config(['app.api_token' => 'super-secret-value']);
    $this->assertStringNotContainsString('super-secret-value', $txt);
}
```
- [ ] **Step 2: Implement** `public/llms.txt` (llmstxt.org format): `# wydoujin API` H1 + `> blockquote` summary; **Base URL** `{APP_URL}/api/v1`; **Auth** (`Authorization: Bearer <API_TOKEN>` or `X-Api-Token`; configured in server `.env`; unset → 503); **Headers** (`Accept: application/json`); **Endpoints** (condensed §4.4 table with bodies); **Workflow** (discovery→organize loop; lock + merge-alias durability). No token value.
  `.env.example`: add under the auth area —
  ```
  # LLM organize API. Unset → the /api/v1 API is DISABLED (503). Set a long random token to enable.
  # API_TOKEN=
  ```
- [ ] **Step 3:** Test green; full suite green.
- [ ] **Step 4: Commit** — `Add public llms.txt discovery doc and API_TOKEN env`.

---

## Task 8: Verification & polish

- [ ] **Step 1:** Full suite: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`.
- [ ] **Step 2:** Coverage: `PATH="/opt/homebrew/opt/php/bin:$PATH" php -d pcov.enabled=1 vendor/bin/pest --coverage --min=100` — bring new `app/` files (controllers/actions/resources/middleware) to 100% line coverage; add focused tests for any uncovered branch (e.g. `EnsureApiToken` 503 vs 401 both hit, `WorkResource` null-series/null-progress branches, bulk unknown-id 422).
- [ ] **Step 3:** Manual smoke (optional, local gate): serve + seed dev SQLite, set `API_TOKEN`, `curl -s -H "Authorization: Bearer …" localhost:8000/api/v1/works | jq`, `curl -s localhost:8000/llms.txt`. Confirm `curl localhost:8000/api/v1/works` (no token) → 401 and (token unset) → 503.
- [ ] **Step 4:** Update `CLAUDE.md` "Where things live" with the API surface + `routes/api.php` + `app/Actions/**` + `EnsureApiToken` + `/llms.txt`. **Commit** — `Document the F5 organize API`.

## Definition of Done

- All `/api/v1` endpoints in §4.4 work behind `EnsureApiToken` (503 unset / 401 bad / 200 good), returning Resource JSON.
- Web + API share `app/Actions/**`; every existing web feature test still green; lock contracts + per-mangaka guard hold across a rescan/redetect from the API path.
- `public/llms.txt` is public, accurate, secret-free.
- Full suite green on in-memory SQLite; **100% `app/` line coverage** maintained.
