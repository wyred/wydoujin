# Multi-value Tags (F4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the scalar `works` metadata columns (`circle/parody/event/author/language` + the `flags` JSON) with one normalized, multi-value tag system, populated by the scanner and curated manually (per-work add/remove + global rename/merge), durable across rescans.

**Architecture:** A polymorphic `tags(type, value)` table + a `work_tag` pivot. `WorkTagSync` derives a work's auto tags from the (unchanged) parser and syncs the pivot, skipping `tags_locked` works. Manual per-work edits set `works.tags_locked`; global rename/merge writes a `tags.merged_into_id` tombstone alias that the scanner resolves on every scan. Faceting, work cards, and work detail read from the pivot; the `/browse` URL contract is unchanged.

**Tech Stack:** Laravel 13 (PHP 8.3+) · Blade + Alpine.js (only JS lib) + Tailwind v4 · design-system tokens · SQLite (dev/test) / MySQL (prod) · Intervention Image (covers, untouched here).

**Spec:** `docs/superpowers/specs/2026-06-23-wydoujin-tags-design.md`. Read it before starting.

## Global Constraints

- **PHP toolchain (this dev machine):** prefix every `php`/`artisan`/`composer` command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5 local). Node/npm are on the normal PATH.
- **Migrations stay portable** across SQLite + MySQL — no MySQL-only column types, **no raw SQL DDL**. All connection details come from env.
- **A work's identity is `content_hash`** — never path. Tags attach to works; rescans never disturb reading progress.
- **Tests run on in-memory SQLite** (`php artisan test`), matching CI. View tests call `$this->withoutVite()`.
- **Tokens only** — never inline a raw hex or size; reference design tokens (`var(--color-primary)`, `var(--radius-pill)`, `var(--type-body)`, …). Weight ladder 300/400/600/700 (no 500). Elevation = 1px hairline ring. Buttons press to `scale(0.95)`.
- **Alpine.js is the only JS** — no SPA framework, no jQuery. Register components inline via `alpine:init` in the view (existing pattern).
- **PHP style:** single quotes unless interpolating; inline typed properties (no `@var` for native types); `final class`; code comments in **both English and Japanese** in the same line/phpdoc, kept short.
- **All curation endpoints are `POST`/`GET` JSON, auth-gated, CSRF via the `<meta name="csrf-token">` header** (existing pattern). Mutations reload the page on success.
- **No slugs** — Japanese values don't slugify. Identity is the tag row; URLs keep type-keyed value arrays.
- **Tag types:** `Tag::TYPES = [circle, parody, event, author, flag, theme]`; `Tag::AUTO_TYPES = [circle, parody, event, author, flag]` (scanner-derived). `theme` is manual-only. `language` is dropped.

---

## File Structure

**New files**
- `database/migrations/2026_06_23_000001_create_tags_table.php` — `tags` table.
- `database/migrations/2026_06_23_000002_create_work_tag_table.php` — pivot.
- `database/migrations/2026_06_23_000003_add_tags_locked_to_works.php` — `works.tags_locked`.
- `database/migrations/2026_06_23_000004_backfill_scalar_metadata_into_tags.php` — one-time backfill (calls `LegacyScalarBackfill`).
- `database/migrations/2026_06_23_000005_drop_scalar_metadata_from_works.php` — drop the 6 legacy columns.
- `app/Models/Tag.php` — Tag model (types, relations, canonical scope, browse URL).
- `app/Tagging/WorkTagSync.php` — derive + sync a work's auto tags; prune orphans.
- `app/Tagging/LegacyScalarBackfill.php` — one-time column→tag backfill (query-builder).
- `app/Http/Controllers/WorkTagController.php` — per-work attach/detach/reset + suggest.
- `app/Http/Controllers/TagController.php` — global index/rename/merge.
- `resources/views/tags/index.blade.php` — `/tags` management page.
- `database/factories/TagFactory.php` — Tag factory (tests).
- `tests/Concerns/SeedsTags.php` — test helper to attach tags.
- Test files per task (see tasks).

**Modified files**
- `app/Models/Work.php` — `tags()` relation, `tags_locked` cast.
- `app/Scanning/LibraryScanner.php` — write tags via `WorkTagSync`, drop scalar writes, prune orphans.
- `app/Providers/AppServiceProvider.php` — inject `WorkTagSync` into the scanner.
- `app/Browse/WorkSearch.php` — 6 dims; facets/filter via the pivot.
- `resources/views/work/show.blade.php` — tags as clickable links + editor.
- `resources/views/components/work-card.blade.php` — circle from tags.
- `resources/views/browse/index.blade.php` — 6 facet groups (Alpine).
- `resources/views/components/nav.blade.php` — `/tags` nav link.
- `app/Http/Controllers/{Browse,Mangaka,Series}Controller.php` — eager-load `tags` for cards.
- `routes/web.php` — tag routes.
- Tests: `SchemaTest`, `ModelRelationsTest`, `Series/SeriesDetectorTest`, `Scanning/LibraryScannerMatchingTest`, `Browse/WorkSearchTest`, `Browse/BrowseSearchTest`, `Browse/SeriesAndWorkTest`, `Browse/ComponentsTest`.

---

## Task 1: Tags schema + models + test helper

**Files:**
- Create: `database/migrations/2026_06_23_000001_create_tags_table.php`, `..._000002_create_work_tag_table.php`, `..._000003_add_tags_locked_to_works.php`
- Create: `app/Models/Tag.php`, `database/factories/TagFactory.php`, `tests/Concerns/SeedsTags.php`
- Modify: `app/Models/Work.php`
- Test: `tests/Feature/TagsSchemaTest.php`; Modify `tests/Feature/SchemaTest.php`

**Interfaces:**
- Produces: `App\Models\Tag` with consts `TYPES`/`AUTO_TYPES`, relations `works()` (BelongsToMany via `work_tag`), `mergedInto()`, `aliases()` (HasMany self via `merged_into_id`), scope `canonical()`, method `browseUrl(): string`, auto-filled `sort_value`. `Work::tags(): BelongsToMany`. `Work.tags_locked` bool cast. Trait `Tests\Concerns\SeedsTags::attachTag(Work, string $type, string $value): Tag`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/TagsSchemaTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SeedsTags;
use Tests\TestCase;

class TagsSchemaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTags;

    public function test_tables_and_column_exist(): void
    {
        $this->assertTrue(Schema::hasTable('tags'));
        $this->assertTrue(Schema::hasTable('work_tag'));
        $this->assertTrue(Schema::hasColumns('tags', ['type', 'value', 'sort_value', 'merged_into_id']));
        $this->assertTrue(Schema::hasColumn('works', 'tags_locked'));
    }

    public function test_creating_a_tag_autofills_sort_value(): void
    {
        $tag = Tag::create(['type' => 'circle', 'value' => '【Z.A.P.】']);
        $this->assertSame('Z.A.P.】', $tag->sort_value); // leading bracket stripped
    }

    public function test_work_tags_relation_and_canonical_scope(): void
    {
        $work = Work::factory()->create();
        $canon = $this->attachTag($work, 'circle', 'Z.A.P.');
        $alias = Tag::create(['type' => 'circle', 'value' => 'ZAP', 'merged_into_id' => $canon->id]);

        $this->assertTrue($work->tags->contains($canon));
        $this->assertSame([$canon->id], Tag::canonical()->pluck('id')->all());
        $this->assertSame($canon->id, $alias->mergedInto->id);
        $this->assertTrue($canon->aliases->contains($alias));
    }

    public function test_browse_url_encodes_type_and_value(): void
    {
        $tag = Tag::create(['type' => 'parody', 'value' => 'Fate/Grand Order']);
        $this->assertSame('/browse?'.http_build_query(['parody' => ['Fate/Grand Order']]), $tag->browseUrl());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=TagsSchemaTest`
Expected: FAIL — `Class "App\Models\Tag" not found` / `Trait "Tests\Concerns\SeedsTags" not found`.

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_06_23_000001_create_tags_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('value');
            $table->string('sort_value')->nullable();
            // Alias/tombstone pointer to the canonical tag (merge/rename). / 別名ポインタ。
            $table->foreignId('merged_into_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'value']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
```

`database/migrations/2026_06_23_000002_create_work_tag_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_tag', function (Blueprint $table) {
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['work_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_tag');
    }
};
```

`database/migrations/2026_06_23_000003_add_tags_locked_to_works.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->boolean('tags_locked')->default(false)->after('series_locked');
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropColumn('tags_locked');
        });
    }
};
```

- [ ] **Step 4: Create the Tag model + factory**

`app/Models/Tag.php`:
```php
<?php

namespace App\Models;

use App\Parsing\ParsedName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A normalized metadata value (type + value), linked to works via work_tag. / 正規化タグ。
 * A row with merged_into_id set is a tombstone alias pointing at the canonical
 * tag and holds no work_tag rows. / merged_into_id付きは正規タグを指す別名(墓石)。
 */
class Tag extends Model
{
    use HasFactory;

    /** All types. AUTO_TYPES are scanner-derived; others are manual-only. / 全タイプ。 */
    public const TYPES = ['circle', 'parody', 'event', 'author', 'flag', 'theme'];
    public const AUTO_TYPES = ['circle', 'parody', 'event', 'author', 'flag'];

    protected $guarded = [];

    protected static function booted(): void
    {
        // Derive sort_value from value when not supplied. / 未指定ならvalueから導出。
        static::creating(function (Tag $tag): void {
            if (($tag->sort_value ?? '') === '') {
                $tag->sort_value = ParsedName::deriveSortTitle((string) $tag->value);
            }
        });
    }

    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'work_tag');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** Inbound aliases (tombstones pointing here). / このタグを指す別名。 */
    public function aliases(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /** Canonical (non-alias) tags only. / 正規タグのみ。 */
    public function scopeCanonical(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }

    /** Deep-link to /browse pre-filtered by this tag. / このタグで絞った/browseへのリンク。 */
    public function browseUrl(): string
    {
        return '/browse?'.http_build_query([$this->type => [$this->value]]);
    }
}
```

`database/factories/TagFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        return [
            'type' => 'circle',
            'value' => $this->faker->unique()->company(),
        ];
    }
}
```

- [ ] **Step 5: Add the `tags()` relation + cast to Work** (`app/Models/Work.php`)

Add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` to the imports. Add `'tags_locked' => 'boolean',` to `$casts`. Add this relation method:
```php
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'work_tag');
    }
```

- [ ] **Step 6: Create the test helper** (`tests/Concerns/SeedsTags.php`)

```php
<?php

namespace Tests\Concerns;

use App\Models\Tag;
use App\Models\Work;

trait SeedsTags
{
    /** Attach a (type,value) tag to a work, creating the tag if needed. / タグ付与。 */
    protected function attachTag(Work $work, string $type, string $value): Tag
    {
        $tag = Tag::firstOrCreate(['type' => $type, 'value' => $value]);
        $work->tags()->syncWithoutDetaching([$tag->id]);

        return $tag;
    }
}
```

- [ ] **Step 7: Extend `SchemaTest` to assert the new tables** (`tests/Feature/SchemaTest.php`)

Add this method (leave the existing `test_core_tables_and_key_columns_exist` untouched — the legacy columns still exist until Task 7):
```php
    public function test_tag_tables_and_lock_column_exist(): void
    {
        $this->assertTrue(Schema::hasTable('tags'));
        $this->assertTrue(Schema::hasTable('work_tag'));
        $this->assertTrue(Schema::hasColumn('works', 'tags_locked'));
    }
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter='TagsSchemaTest|SchemaTest'`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Models/Tag.php app/Models/Work.php database/migrations/2026_06_23_00000{1,2,3}_* database/factories/TagFactory.php tests/Concerns/SeedsTags.php tests/Feature/TagsSchemaTest.php tests/Feature/SchemaTest.php
git commit -m "feat: tags + work_tag schema, Tag model, tags_locked"
```

---

## Task 2: `WorkTagSync` service

**Files:**
- Create: `app/Tagging/WorkTagSync.php`
- Test: `tests/Feature/Tagging/WorkTagSyncTest.php`

**Interfaces:**
- Consumes: `Tag` (Task 1), `App\Parsing\FilenameParser` + `App\Parsing\ParsedName` (existing).
- Produces: `WorkTagSync` with `sync(Work $work, ?ParsedName $parsed = null): void`, `derive(ParsedName $parsed): array` (list of `[type, value]`), `pruneOrphans(): int`. Resolves merge-aliases to canonical tags; no-ops on `tags_locked` works.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Tagging/WorkTagSyncTest.php`

```php
<?php

namespace Tests\Feature\Tagging;

use App\Models\Tag;
use App\Models\Work;
use App\Parsing\ParsedName;
use App\Tagging\WorkTagSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsTags;
use Tests\TestCase;

class WorkTagSyncTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTags;

    private function sync(): WorkTagSync
    {
        return app(WorkTagSync::class);
    }

    public function test_derives_one_tag_per_field_and_one_per_flag(): void
    {
        $parsed = ParsedName::make('四畳半物語', 'raw', event: 'C89', circle: 'Z.A.P.', author: 'ズッキーニ', parody: 'オリジナル', flags: ['DL版', 'pixiv']);

        $pairs = $this->sync()->derive($parsed);

        $this->assertEqualsCanonicalizing([
            ['circle', 'Z.A.P.'], ['parody', 'オリジナル'], ['event', 'C89'],
            ['author', 'ズッキーニ'], ['flag', 'DL版'], ['flag', 'pixiv'],
        ], $pairs);
    }

    public function test_sync_attaches_canonical_tags_and_dedupes(): void
    {
        $work = Work::factory()->create();
        $parsed = ParsedName::make('t', 'raw', circle: 'A', parody: 'P');

        $this->sync()->sync($work, $parsed);

        $this->assertEqualsCanonicalizing(
            [['circle', 'A'], ['parody', 'P']],
            $work->tags()->get()->map(fn (Tag $t) => [$t->type, $t->value])->all(),
        );
    }

    public function test_sync_resolves_merge_alias_to_canonical(): void
    {
        $work = Work::factory()->create();
        $canon = Tag::create(['type' => 'parody', 'value' => 'Fate/Grand Order']);
        Tag::create(['type' => 'parody', 'value' => 'FGO', 'merged_into_id' => $canon->id]);

        // The parser still produces the raw "FGO"; sync must attach the canonical.
        $this->sync()->sync($work, ParsedName::make('t', 'raw', parody: 'FGO'));

        $this->assertSame([$canon->id], $work->tags()->pluck('tags.id')->all());
    }

    public function test_sync_skips_locked_works(): void
    {
        $work = Work::factory()->create(['tags_locked' => true]);
        $this->attachTag($work, 'theme', 'manual-only');

        $this->sync()->sync($work, ParsedName::make('t', 'raw', circle: 'A'));

        $this->assertSame([['theme', 'manual-only']], $work->tags()->get()->map(fn (Tag $t) => [$t->type, $t->value])->all());
    }

    public function test_prune_orphans_removes_only_unused_non_alias_non_target(): void
    {
        $work = Work::factory()->create();
        $used = $this->attachTag($work, 'circle', 'used');
        $orphan = Tag::create(['type' => 'circle', 'value' => 'orphan']);
        $target = Tag::create(['type' => 'circle', 'value' => 'target']);
        Tag::create(['type' => 'circle', 'value' => 'tombstone', 'merged_into_id' => $target->id]);

        $deleted = $this->sync()->pruneOrphans();

        $this->assertSame(1, $deleted);
        $this->assertNotNull($used->fresh());
        $this->assertNull($orphan->fresh());      // unused canonical → pruned
        $this->assertNotNull($target->fresh());    // merge target → kept
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=WorkTagSyncTest`
Expected: FAIL — `Class "App\Tagging\WorkTagSync" not found`.

- [ ] **Step 3: Create the service** (`app/Tagging/WorkTagSync.php`)

```php
<?php

namespace App\Tagging;

use App\Models\Tag;
use App\Models\Work;
use App\Parsing\FilenameParser;
use App\Parsing\ParsedName;

/**
 * Derives a work's auto tags from the parsed filename and syncs the work_tag
 * pivot. Skips tags_locked works; resolves merge-aliases to canonical tags.
 * 解析結果から自動タグを導出し同期。ロック作品はスキップ。別名は正規へ解決。
 */
final class WorkTagSync
{
    public function __construct(private readonly FilenameParser $parser)
    {
    }

    /** Sync one work's auto tags. No-op when tags_locked. / ロック時は何もしない。 */
    public function sync(Work $work, ?ParsedName $parsed = null): void
    {
        if ($work->tags_locked) {
            return;
        }
        // The parsed fields aren't stored post-migration: re-parse the filename. / 解析値は保存しないため再解析。
        $parsed ??= $this->parser->parse(pathinfo($work->filename, PATHINFO_FILENAME), $work->mangaka->name);

        $ids = [];
        foreach ($this->derive($parsed) as [$type, $value]) {
            $ids[] = $this->canonicalId($type, $value);
        }
        $work->tags()->sync(array_values(array_unique($ids)));
    }

    /**
     * Auto tag set for a parse: one per non-empty scalar + one per flag. / 自動タグ集合。
     *
     * @return list<array{0:string,1:string}> [type, value] pairs
     */
    public function derive(ParsedName $parsed): array
    {
        $pairs = [];
        $scalars = ['circle' => $parsed->circle, 'parody' => $parsed->parody, 'event' => $parsed->event, 'author' => $parsed->author];
        foreach ($scalars as $type => $value) {
            if ($value !== null && $value !== '') {
                $pairs[] = [$type, $value];
            }
        }
        foreach ($parsed->flags as $flag) {
            if ($flag !== '') {
                $pairs[] = ['flag', $flag];
            }
        }

        return $pairs;
    }

    /** firstOrCreate the (type,value) tag, resolved through any merge-alias. / 別名解決付き。 */
    private function canonicalId(string $type, string $value): int
    {
        $tag = Tag::firstOrCreate(['type' => $type, 'value' => $value]);

        return (int) ($tag->merged_into_id ?? $tag->id);
    }

    /** Delete canonical tags with no works that aren't a merge target. / 孤立タグ削除。 */
    public function pruneOrphans(): int
    {
        return Tag::query()
            ->whereNull('merged_into_id')
            ->whereDoesntHave('works')
            ->whereDoesntHave('aliases')
            ->delete();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=WorkTagSyncTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Tagging/WorkTagSync.php tests/Feature/Tagging/WorkTagSyncTest.php
git commit -m "feat: WorkTagSync — derive/sync auto tags, resolve aliases, prune orphans"
```

---

## Task 3: Scanner writes tags via `WorkTagSync`

**Files:**
- Modify: `app/Scanning/LibraryScanner.php`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Scanning/LibraryScannerMatchingTest.php` (update assertions)

**Interfaces:**
- Consumes: `WorkTagSync` (Task 2).
- Produces: after a scan, each work has its auto tags attached; the scanner no longer writes the scalar columns; orphan tags are pruned at the end of `scan()`.

- [ ] **Step 1: Update the scanner test** — `tests/Feature/Scanning/LibraryScannerMatchingTest.php`

Replace the metadata assertions in `test_fresh_scan_creates_works_with_parsed_metadata_and_cover` (the `$this->assertSame('C89', $work->event);` … `$work->flags` block, lines ~42-46) with tag-relation assertions. The method becomes:
```php
    public function test_fresh_scan_creates_works_with_parsed_metadata_and_cover(): void
    {
        $this->makeDoujin('Z.A.P.', '(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]');

        $stats = $this->scanner()->scan();

        $this->assertSame(1, $stats['added']);
        $work = Work::firstOrFail();
        $this->assertSame('四畳半物語', $work->title);
        // Metadata now lives in tags. / メタデータはタグに。
        $this->assertEqualsCanonicalizing([
            ['event', 'C89'], ['circle', 'Z.A.P.'], ['author', 'ズッキーニ'],
            ['parody', 'オリジナル'], ['flag', 'DL版'],
        ], $work->tags()->get()->map(fn ($t) => [$t->type, $t->value])->all());
        $this->assertSame(2, $work->page_count);
        $this->assertSame(['001.jpg', '002.jpg'], $work->entries);
        $this->assertSame('Z.A.P.', $work->mangaka->name);
        $this->assertNotEmpty($work->mangaka->slug);
        $this->assertNotNull($work->cover_path);
        $this->assertFileExists($this->dataPath.'/'.$work->cover_path);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter='LibraryScannerMatchingTest::test_fresh_scan'`
Expected: FAIL — work has no tags (scanner still writes scalar columns).

- [ ] **Step 3: Inject `WorkTagSync` into the scanner** (`app/Scanning/LibraryScanner.php`)

Add `use App\Tagging\WorkTagSync;`. Add the dependency to the constructor (after `$parser`):
```php
    public function __construct(
        private readonly ArchiveInspector $inspector,
        private readonly CoverGenerator $covers,
        private readonly FilenameParser $parser,
        private readonly WorkTagSync $tags,
        private readonly string $libraryPath,
    ) {
    }
```

- [ ] **Step 4: Write tags instead of scalar columns + prune orphans** (`app/Scanning/LibraryScanner.php`)

In `processZip`, delete these six lines from the `$attributes` array (lines ~109-114):
```php
            'event' => $parsed->event,
            'circle' => $parsed->circle,
            'author' => $parsed->author,
            'parody' => $parsed->parody,
            'language' => $parsed->language,
            'flags' => $parsed->flags,
```
Then sync tags on both write paths. The by-hash branch becomes:
```php
        $byHash = Work::where('content_hash', $inspection->contentHash)->first();
        if ($byHash !== null) {
            $moved = $byHash->relative_path !== $relativePath;
            $byHash->update($attributes); // keeps content_hash + reading_progress (separate row)
            $this->tags->sync($byHash, $parsed); // sync metadata tags / メタデータタグを同期
            $stats[$moved ? 'moved' : 'updated']++;

            return;
        }
```
And the new-work branch becomes:
```php
        $attributes['content_hash'] = $inspection->contentHash;
        $attributes['cover_path'] = $inspection->imageEntries === []
            ? null
            : $this->covers->generate($zipPath, $inspection->imageEntries[0], $inspection->contentHash);

        $work = Work::create($attributes);
        $this->tags->sync($work, $parsed);
        $stats['added']++;
```
Finally, in `scan()`, after the missing sweep and before `return $stats;`, add:
```php
        $this->tags->pruneOrphans(); // drop tags no work references / 参照されないタグを削除
```

- [ ] **Step 5: Pass `WorkTagSync` to the scanner binding** (`app/Providers/AppServiceProvider.php`)

Add `use App\Tagging\WorkTagSync;`. Update the `LibraryScanner` binding closure to inject it (arg order matches the constructor):
```php
        $this->app->bind(LibraryScanner::class, fn ($app) => new LibraryScanner(
            $app->make(ArchiveInspector::class),
            $app->make(CoverGenerator::class),
            $app->make(FilenameParser::class),
            $app->make(WorkTagSync::class),
            config('scan.library_path'),
        ));
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter='LibraryScanner|ScanLibraryJob|ScanCommand'`
Expected: PASS (matching, missing, job, command scanner suites all green).

- [ ] **Step 7: Commit**

```bash
git add app/Scanning/LibraryScanner.php app/Providers/AppServiceProvider.php tests/Feature/Scanning/LibraryScannerMatchingTest.php
git commit -m "feat: scanner writes metadata as tags via WorkTagSync; prune orphans"
```

---

## Task 4: `WorkSearch` reads tags + 6 facet dimensions

**Files:**
- Modify: `app/Browse/WorkSearch.php`
- Test: `tests/Feature/Browse/WorkSearchTest.php`, `tests/Feature/Browse/BrowseSearchTest.php` (rewrite to seed tags)

**Interfaces:**
- Consumes: `Work::tags()` (Task 1), `Tag` (Task 1).
- Produces: `WorkSearch::DIMENSIONS = ['circle','parody','event','author','flag','theme']`; constructor + `fromRequest` accept all six; `results()` eager-loads `tags`; `facets()` returns the same `array<string, list<array{value,count}>>` shape keyed by all six dims, computed from the pivot. URL/JSON contract unchanged.

- [ ] **Step 1: Rewrite `WorkSearchTest` to seed tags** — `tests/Feature/Browse/WorkSearchTest.php`

Add `use Tests\Concerns\SeedsTags;` and `use SeedsTags;` in the class. Keep `test_excludes_missing_works`, `test_q_matches_title_and_title_raw_case_insensitively`, `test_q_treats_percent_and_underscore_literally`, `test_results_ordered_by_sort_title_and_paginated` unchanged (they don't touch facets). Replace the three facet tests with tag-seeded versions:
```php
    public function test_facets_or_within_and_and_across(): void
    {
        $a = $this->work(['title' => 'A-P', 'sort_title' => '1']); $this->attachTag($a, 'circle', 'A'); $this->attachTag($a, 'parody', 'P');
        $b = $this->work(['title' => 'B-P', 'sort_title' => '2']); $this->attachTag($b, 'circle', 'B'); $this->attachTag($b, 'parody', 'P');
        $c = $this->work(['title' => 'C-Q', 'sort_title' => '3']); $this->attachTag($c, 'circle', 'C'); $this->attachTag($c, 'parody', 'Q');

        $or = (new WorkSearch(circle: ['A', 'B']))->results()->pluck('title')->all();
        sort($or);
        $this->assertSame(['A-P', 'B-P'], $or);

        $and = (new WorkSearch(circle: ['A', 'B'], parody: ['Q']))->results()->pluck('title')->all();
        $this->assertSame([], $and);
    }

    public function test_counts_are_dynamic_and_exclude_own_dimension(): void
    {
        $w1 = $this->work(['title' => 'w1', 'sort_title' => '1']); $this->attachTag($w1, 'circle', 'A'); $this->attachTag($w1, 'parody', 'P');
        $w2 = $this->work(['title' => 'w2', 'sort_title' => '2']); $this->attachTag($w2, 'circle', 'A'); $this->attachTag($w2, 'parody', 'Q');
        $w3 = $this->work(['title' => 'w3', 'sort_title' => '3']); $this->attachTag($w3, 'circle', 'B'); $this->attachTag($w3, 'parody', 'P');

        $facets = (new WorkSearch(parody: ['P']))->facets();

        $circle = collect($facets['circle'])->pluck('count', 'value')->all();
        $this->assertSame(['A' => 1, 'B' => 1], $circle);

        $parody = collect($facets['parody'])->pluck('count', 'value')->all();
        $this->assertSame(['P' => 2, 'Q' => 1], $parody);
    }

    public function test_selected_value_kept_visible_when_zero(): void
    {
        $w = $this->work(['title' => 'only', 'sort_title' => '1']); $this->attachTag($w, 'circle', 'A');

        $facets = (new WorkSearch(circle: ['B']))->facets();

        $circle = collect($facets['circle'])->pluck('count', 'value')->all();
        $this->assertSame(1, $circle['A']);
        $this->assertArrayHasKey('B', $circle);
        $this->assertSame(0, $circle['B']);
    }

    public function test_facets_cover_author_and_flag_dimensions(): void
    {
        $w = $this->work(['title' => 'x', 'sort_title' => '1']);
        $this->attachTag($w, 'author', 'Z'); $this->attachTag($w, 'flag', 'DL版');

        $facets = (new WorkSearch())->facets();

        $this->assertSame(['circle', 'parody', 'event', 'author', 'flag', 'theme'], array_keys($facets));
        $this->assertSame(['Z' => 1], collect($facets['author'])->pluck('count', 'value')->all());
        $this->assertSame(['DL版' => 1], collect($facets['flag'])->pluck('count', 'value')->all());
    }
```
Add `use Tests\Concerns\SeedsTags;` to the imports.

- [ ] **Step 2: Rewrite `BrowseSearchTest` facet seeds** — `tests/Feature/Browse/BrowseSearchTest.php`

Add `use Tests\Concerns\SeedsTags;` + `use SeedsTags;`. Where it seeds `circle` via the `work([... 'circle' => ...])` helper (lines ~52-53, 70, 78), seed via tags instead. For example the facet-filter test:
```php
        $zap = $this->work(['title' => 'ZapWork', 'sort_title' => 'a']); $this->attachTag($zap, 'circle', 'Z.A.P.');
        $foo = $this->work(['title' => 'FooWork', 'sort_title' => 'b']); $this->attachTag($foo, 'circle', 'Foo');
```
(apply the same swap to the embeds-facet-data and json-shape tests). Update the JSON-shape assertion's facet keys to all six:
```php
                'facets' => ['circle', 'parody', 'event', 'author', 'flag', 'theme'],
```

- [ ] **Step 3: Run them to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter='WorkSearchTest|BrowseSearchTest'`
Expected: FAIL — facets keyed by only 3 dims / counts read scalar columns.

- [ ] **Step 4: Rewrite `WorkSearch`** (`app/Browse/WorkSearch.php`)

Add `use Illuminate\Support\Facades\DB;`. Replace the class body from `DIMENSIONS` through `facets()`:
```php
    public const DIMENSIONS = ['circle', 'parody', 'event', 'author', 'flag', 'theme'];

    /**
     * @param string[] $circle
     * @param string[] $parody
     * @param string[] $event
     * @param string[] $author
     * @param string[] $flag
     * @param string[] $theme
     */
    public function __construct(
        public readonly ?string $q = null,
        public readonly array $circle = [],
        public readonly array $parody = [],
        public readonly array $event = [],
        public readonly array $author = [],
        public readonly array $flag = [],
        public readonly array $theme = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        $clean = static fn ($v): array => array_values(array_filter(
            array_map('strval', (array) $v),
            static fn (string $s): bool => $s !== '',
        ));
        $q = trim((string) $request->query('q', ''));

        return new self(
            q: $q === '' ? null : $q,
            circle: $clean($request->query('circle', [])),
            parody: $clean($request->query('parody', [])),
            event: $clean($request->query('event', [])),
            author: $clean($request->query('author', [])),
            flag: $clean($request->query('flag', [])),
            theme: $clean($request->query('theme', [])),
        );
    }

    /** Selected values for a dimension. / 次元の選択値。 */
    private function selected(string $dim): array
    {
        return $this->{$dim};
    }

    /** Base query: not-missing + optional title LIKE. / 基底: 欠落除外＋題名LIKE。 */
    private function base(): Builder
    {
        return Work::query()
            ->where('is_missing', false)
            ->when($this->q !== null, function (Builder $w): void {
                // ESCAPE '!' keeps literal % / _ literal and is portable (SQLite+MySQL). / 移植性のためのエスケープ。
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $this->q).'%';
                $w->where(function (Builder $x) use ($term): void {
                    $x->whereRaw("title LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("title_raw LIKE ? ESCAPE '!'", [$term]);
                });
            });
    }

    /** Apply facet filters via the pivot, optionally skipping one dimension. / ファセット適用。 */
    private function applyFacets(Builder $query, ?string $except = null): Builder
    {
        foreach (self::DIMENSIONS as $dim) {
            $values = $this->selected($dim);
            if ($dim !== $except && $values !== []) {
                $query->whereHas('tags', fn (Builder $t) => $t->where('type', $dim)->whereIn('value', $values));
            }
        }

        return $query;
    }

    public function results(int $page = 1, int $perPage = 60): LengthAwarePaginator
    {
        return $this->applyFacets($this->base())
            ->with(['readingProgress', 'tags'])
            ->orderBy('sort_title')
            ->paginate($perPage, ['*'], 'page', max(1, $page));
    }

    /** Work ids under base + the OTHER facets (excluding $except's own selection). / 基底＋他次元の作品ID。 */
    private function matchingWorkIds(?string $except): array
    {
        return $this->applyFacets($this->base(), except: $except)->pluck('id')->all();
    }

    /**
     * Dynamic facet counts from the pivot. / 動的ファセット件数。
     *
     * @return array<string, list<array{value:string,count:int}>>
     */
    public function facets(): array
    {
        $out = [];
        foreach (self::DIMENSIONS as $dim) {
            $counts = DB::table('work_tag')
                ->join('tags', 'tags.id', '=', 'work_tag.tag_id')
                ->whereIn('work_tag.work_id', $this->matchingWorkIds($dim))
                ->where('tags.type', $dim)
                ->whereNull('tags.merged_into_id')
                ->groupBy('tags.value')
                ->selectRaw('tags.value as value, COUNT(DISTINCT work_tag.work_id) as count')
                ->pluck('count', 'value')
                ->all();

            foreach ($this->selected($dim) as $sel) {
                $counts[$sel] ??= 0;
            }
            $rows = [];
            foreach ($counts as $value => $count) {
                $rows[] = ['value' => (string) $value, 'count' => (int) $count];
            }
            usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);
            $out[$dim] = $rows;
        }

        return $out;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter='WorkSearchTest|BrowseSearchTest'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Browse/WorkSearch.php tests/Feature/Browse/WorkSearchTest.php tests/Feature/Browse/BrowseSearchTest.php
git commit -m "feat: WorkSearch facets/filters over tags; 6 dimensions"
```

---

## Task 5: Views read tags (work detail links, card, browse facets) + eager loads

**Files:**
- Modify: `resources/views/work/show.blade.php`, `resources/views/components/work-card.blade.php`, `resources/views/browse/index.blade.php`
- Modify: `app/Http/Controllers/BrowseController.php`, `app/Http/Controllers/MangakaController.php`, `app/Http/Controllers/SeriesController.php`
- Test: `tests/Feature/Browse/SeriesAndWorkTest.php`, `tests/Feature/Browse/ComponentsTest.php` (seed tags)

**Interfaces:**
- Consumes: `Work::tags()`, `Tag::browseUrl()` (Task 1).
- Produces: work detail + card render metadata from the `tags` relation as clickable `/browse` links; browse facet rail shows all six dimensions; `tags` is eager-loaded everywhere cards render.

- [ ] **Step 1: Update the work-detail + card tests** — seed tags, assert links

In `tests/Feature/Browse/SeriesAndWorkTest.php` add `use Tests\Concerns\SeedsTags;` + `use SeedsTags;`, and rewrite `test_work_detail_shows_metadata_badges_progress_and_read_cta` to seed tags + assert a clickable parody link:
```php
    public function test_work_detail_shows_metadata_badges_progress_and_read_cta(): void
    {
        $m = Mangaka::factory()->create(['name' => 'Z.A.P.']);
        $work = Work::factory()->for($m)->create(['title' => '四畳半物語', 'page_count' => 24, 'cover_path' => 'covers/h.webp']);
        $this->attachTag($work, 'circle', 'Z.A.P.');
        $this->attachTag($work, 'author', 'ズッキーニ');
        $this->attachTag($work, 'parody', 'オリジナル');
        $this->attachTag($work, 'event', 'C89');
        $this->attachTag($work, 'flag', 'DL版');
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 3]);

        $this->get('/work/'.$work->id)->assertOk()
            ->assertSee('四畳半物語')
            ->assertSee('ズッキーニ')
            ->assertSee('オリジナル')
            ->assertSee('C89')
            ->assertSee('DL版')
            ->assertSee('24 pages')
            ->assertSee('3/24')
            ->assertSee('href="'.e('/browse?'.http_build_query(['parody' => ['オリジナル']])).'"', false) // clickable tag
            ->assertSee('href="/work/'.$work->id.'/read"', false)
            ->assertSee('Continue');
    }
```
In `tests/Feature/Browse/ComponentsTest.php` add `use Tests\Concerns\SeedsTags;` + `use SeedsTags;`, and in `test_work_card_links_to_work_and_shows_progress` replace the `'circle' => 'サークルX'` create-attribute with a tag attach after creation:
```php
        // create the work without the scalar, then attach the circle tag
        $work = Work::factory()->for($m)->create(['title' => 'カードの題', 'page_count' => 20, 'cover_path' => 'covers/h.webp']);
        $this->attachTag($work, 'circle', 'サークルX');
```
(keep the rest of that test — it still asserts `サークルX` is shown).

- [ ] **Step 2: Run them to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter='SeriesAndWorkTest|ComponentsTest'`
Expected: FAIL — the views still read `$work->circle` etc. (now empty); no clickable link.

- [ ] **Step 3: Rewrite the work-detail metadata block** (`resources/views/work/show.blade.php`)

Replace the mangaka/circle/author line block (lines ~15-25) with tag-driven rendering:
```blade
                @php $byType = $work->tags->groupBy('type'); @endphp
                <div style="margin-top:var(--space-xs); font:var(--type-body); color:var(--text-muted);">
                    <a href="/mangaka/{{ $work->mangaka->slug }}" class="no-underline" style="color:var(--text-link);">{{ $work->mangaka->name }}</a>
                    @foreach (($byType['circle'] ?? []) as $tag)<span> · </span><a href="{{ $tag->browseUrl() }}" class="no-underline" style="color:var(--text-link);">{{ $tag->value }}</a>@endforeach
                    @foreach (($byType['author'] ?? []) as $tag)<span> · </span><a href="{{ $tag->browseUrl() }}" class="no-underline" style="color:var(--text-link);">{{ $tag->value }}</a>@endforeach
                </div>

                <div class="flex" style="gap:var(--space-xs); flex-wrap:wrap; margin-top:var(--space-md);">
                    @foreach (['parody', 'event', 'flag', 'theme'] as $t)
                        @foreach (($byType[$t] ?? []) as $tag)
                            <a href="{{ $tag->browseUrl() }}" class="no-underline"><x-badge>{{ $tag->value }}</x-badge></a>
                        @endforeach
                    @endforeach
                </div>
```

- [ ] **Step 4: Rewrite the work-card circle** (`resources/views/components/work-card.blade.php`)

Replace the `@if ($work->circle) … @endif` block (lines ~14-16) with:
```blade
        @php $circle = $work->tags->firstWhere('type', 'circle'); @endphp
        @if ($circle)
            <div class="truncate" style="font:var(--type-fine); color:var(--text-muted);">{{ $circle->value }}</div>
        @endif
```

- [ ] **Step 5: Widen the browse facet rail to 6 dims** (`resources/views/browse/index.blade.php`)

In the `@php $initial` block (line ~6), expand `selected` to all six:
```php
        'selected' => [
            'circle' => $search->circle, 'parody' => $search->parody, 'event' => $search->event,
            'author' => $search->author, 'flag' => $search->flag, 'theme' => $search->theme,
        ],
```
In the Alpine `browse` component, update these defaults/lists so all six dims exist:
```js
            selected: initial.selected ?? { circle: [], parody: [], event: [], author: [], flag: [], theme: [] },
            facets: initial.facets ?? { circle: [], parody: [], event: [], author: [], flag: [], theme: [] },
```
```js
            groups: [
                { key: 'circle', label: 'Circle' },
                { key: 'parody', label: 'Parody' },
                { key: 'event', label: 'Event' },
                { key: 'author', label: 'Author' },
                { key: 'flag', label: 'Flag' },
                { key: 'theme', label: 'Theme' },
            ],
            expanded: { circle: false, parody: false, event: false, author: false, flag: false, theme: false },
            within: { circle: '', parody: '', event: '', author: '', flag: '', theme: '' },
```
```js
            dims() { return ['circle', 'parody', 'event', 'author', 'flag', 'theme']; },
```
```js
            clear() {
                this.q = '';
                this.selected = { circle: [], parody: [], event: [], author: [], flag: [], theme: [] };
                this.refresh();
            },
            activeCount() {
                return this.dims().reduce((n, d) => n + this.selected[d].length, 0) + (this.q ? 1 : 0);
            },
```
Empty groups render nothing (the `x-for="row in visibleRows"` simply has no rows), so unused dims (often `theme`) stay invisible — no extra guard needed.

- [ ] **Step 6: Eager-load `tags` wherever cards render**

`app/Http/Controllers/BrowseController.php` — add `tags`:
```php
            ->with('work.mangaka', 'work.readingProgress', 'work.tags')
```
```php
            ->with('mangaka', 'readingProgress', 'tags')
```
`app/Http/Controllers/SeriesController.php` — `->with('readingProgress', 'tags')`.
`app/Http/Controllers/MangakaController.php` — in `show`, the standalone query `->with('readingProgress')` becomes `->with('readingProgress', 'tags')`, and the series eager-load `->with(['works' => fn ($q) => $q->where('is_missing', false)->orderBy('sort_title')])` becomes `->with(['works' => fn ($q) => $q->where('is_missing', false)->with('tags')->orderBy('sort_title')])`.

- [ ] **Step 7: Run tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter='SeriesAndWorkTest|ComponentsTest|HomeTest|MangakaTest'`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/views/work/show.blade.php resources/views/components/work-card.blade.php resources/views/browse/index.blade.php app/Http/Controllers/BrowseController.php app/Http/Controllers/MangakaController.php app/Http/Controllers/SeriesController.php tests/Feature/Browse/SeriesAndWorkTest.php tests/Feature/Browse/ComponentsTest.php
git commit -m "feat: views render metadata from tags (clickable); eager-load tags"
```

---

## Task 6: Backfill migration (legacy columns → tags)

**Files:**
- Create: `app/Tagging/LegacyScalarBackfill.php`, `database/migrations/2026_06_23_000004_backfill_scalar_metadata_into_tags.php`
- Test: `tests/Feature/Tagging/LegacyScalarBackfillTest.php`

**Interfaces:**
- Consumes: the still-present scalar columns on `works` (dropped in Task 7).
- Produces: `LegacyScalarBackfill::run(): void` — idempotent; for every work, creates tags from `circle/parody/event/author` + each `flags[]` element and links the pivot, all via the query builder (engine-agnostic, model-independent).

- [ ] **Step 1: Write the failing test** — `tests/Feature/Tagging/LegacyScalarBackfillTest.php`

```php
<?php

namespace Tests\Feature\Tagging;

use App\Models\Tag;
use App\Models\Work;
use App\Tagging\LegacyScalarBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyScalarBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_tags_and_pivots_from_scalar_columns(): void
    {
        $work = Work::factory()->create();
        // Set the legacy columns directly (still present pre-drop). / 旧カラムを直接設定。
        DB::table('works')->where('id', $work->id)->update([
            'circle' => 'Z.A.P.', 'parody' => 'オリジナル', 'event' => 'C89',
            'author' => 'ズッキーニ', 'flags' => json_encode(['DL版', 'pixiv']),
        ]);

        (new LegacyScalarBackfill())->run();

        $this->assertEqualsCanonicalizing([
            ['circle', 'Z.A.P.'], ['parody', 'オリジナル'], ['event', 'C89'],
            ['author', 'ズッキーニ'], ['flag', 'DL版'], ['flag', 'pixiv'],
        ], $work->fresh()->tags->map(fn (Tag $t) => [$t->type, $t->value])->all());
    }

    public function test_is_idempotent(): void
    {
        $work = Work::factory()->create();
        DB::table('works')->where('id', $work->id)->update(['circle' => 'A']);

        (new LegacyScalarBackfill())->run();
        (new LegacyScalarBackfill())->run();

        $this->assertSame(1, Tag::count());
        $this->assertSame(1, $work->fresh()->tags()->count());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=LegacyScalarBackfillTest`
Expected: FAIL — `Class "App\Tagging\LegacyScalarBackfill" not found`.

- [ ] **Step 3: Create the backfill class** (`app/Tagging/LegacyScalarBackfill.php`)

```php
<?php

namespace App\Tagging;

use App\Parsing\ParsedName;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill of the legacy scalar metadata columns into the tag tables.
 * Query-builder only (model-independent, portable). Idempotent. / 旧カラム→タグ移行。
 */
final class LegacyScalarBackfill
{
    public function run(): void
    {
        DB::table('works')->orderBy('id')->each(function (object $work): void {
            $pairs = [];
            foreach (['circle', 'parody', 'event', 'author'] as $type) {
                $value = $work->{$type} ?? null;
                if ($value !== null && $value !== '') {
                    $pairs[] = [$type, (string) $value];
                }
            }
            foreach ((array) json_decode($work->flags ?? '[]', true) as $flag) {
                if ($flag !== '' && $flag !== null) {
                    $pairs[] = ['flag', (string) $flag];
                }
            }
            foreach ($pairs as [$type, $value]) {
                $tagId = $this->tagId($type, $value);
                DB::table('work_tag')->insertOrIgnore(['work_id' => $work->id, 'tag_id' => $tagId]);
            }
        });
    }

    /** Select-or-insert a canonical tag, returning its id. / 正規タグをselect-or-insert。 */
    private function tagId(string $type, string $value): int
    {
        $existing = DB::table('tags')->where('type', $type)->where('value', $value)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }
        $now = now();

        return (int) DB::table('tags')->insertGetId([
            'type' => $type,
            'value' => $value,
            'sort_value' => ParsedName::deriveSortTitle($value),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
```

- [ ] **Step 4: Create the migration that runs it** (`database/migrations/2026_06_23_000004_backfill_scalar_metadata_into_tags.php`)

```php
<?php

use App\Tagging\LegacyScalarBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        // Runs after the scalar columns still exist, before they're dropped. / 列削除前に移行。
        (new LegacyScalarBackfill())->run();
    }

    public function down(): void
    {
        // Forward-only: tags are not un-backfilled. / 後方移行なし。
    }
};
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=LegacyScalarBackfillTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Tagging/LegacyScalarBackfill.php database/migrations/2026_06_23_000004_* tests/Feature/Tagging/LegacyScalarBackfillTest.php
git commit -m "feat: one-time backfill of scalar metadata into tags"
```

---

## Task 7: Drop the legacy scalar columns

**Files:**
- Create: `database/migrations/2026_06_23_000005_drop_scalar_metadata_from_works.php`
- Modify: `app/Models/Work.php`
- Test: update `tests/Feature/SchemaTest.php`, `tests/Feature/ModelRelationsTest.php`, `tests/Feature/Series/SeriesDetectorTest.php`; delete `tests/Feature/Tagging/LegacyScalarBackfillTest.php`

**Interfaces:**
- Consumes: backfill (Task 6) has run; no reads of the scalar columns remain (Tasks 3–5).
- Produces: `works` no longer has `circle/parody/event/author/language/flags`; `Work` drops the `flags` cast.

- [ ] **Step 1: Update the tests that still reference dropped columns**

`tests/Feature/SchemaTest.php` — change `test_core_tables_and_key_columns_exist` so the asserted `works` columns list **drops** `'event', 'circle', 'author', 'parody', 'language', 'flags'`, and add an absence assertion:
```php
        $this->assertTrue(Schema::hasColumns('works', [
            'content_hash', 'mangaka_id', 'series_id', 'relative_path',
            'title', 'title_raw', 'sort_title', 'entries', 'page_count',
            'cover_path', 'file_size', 'file_mtime', 'last_seen_at',
            'is_missing', 'series_locked', 'tags_locked',
        ]));
        $this->assertFalse(Schema::hasColumn('works', 'circle'));
        $this->assertFalse(Schema::hasColumn('works', 'flags'));
```
`tests/Feature/ModelRelationsTest.php` — in `test_relationships_and_casts`, drop the `flags` from the create + the `flags` assertion (the column is gone); keep `entries`:
```php
        $work = Work::factory()->for($mangaka)->for($series)->create(['entries' => ['001.jpg', '002.jpg']]);
        // …
        $this->assertSame(['001.jpg', '002.jpg'], $work->entries);
```
(remove the `'flags' => ['DL版']` create key and the `$this->assertSame(['DL版'], $work->flags);` line.)
`tests/Feature/Series/SeriesDetectorTest.php:75` — the Fate-trap seed sets a dropped column. Series detection ignores parody (it clusters by title), so attach a parody **tag** instead to preserve the scenario. Replace the `$p = ['parody' => 'Fate/Grand Order'];` seeding with works seeded normally, then `Tag::firstOrCreate(['type' => 'parody', 'value' => 'Fate/Grand Order'])` attached to each via `->tags()->attach(...)` (add `use App\Models\Tag;`). The assertions (that these works do **not** form a series) are unchanged.

- [ ] **Step 2: Delete the now-obsolete backfill test**

```bash
git rm tests/Feature/Tagging/LegacyScalarBackfillTest.php
```
Rationale: it seeds the scalar columns, which no longer exist after this task; the migration's `LegacyScalarBackfill` class stays (the migration runs before the drop on a fresh DB). The derivation logic remains covered by `WorkTagSyncTest`.

- [ ] **Step 3: Run them to verify they fail**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter='SchemaTest|ModelRelationsTest|SeriesDetectorTest'`
Expected: FAIL — columns still present, so the absence assertions fail.

- [ ] **Step 4: Create the drop migration** (`database/migrations/2026_06_23_000005_drop_scalar_metadata_from_works.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropIndex(['parody']);
            $table->dropIndex(['circle']);
            $table->dropIndex(['event']);
            $table->dropColumn(['circle', 'parody', 'event', 'author', 'language', 'flags']);
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->string('event')->nullable();
            $table->string('circle')->nullable();
            $table->string('author')->nullable();
            $table->string('parody')->nullable();
            $table->string('language')->nullable();
            $table->json('flags')->nullable();
            $table->index('parody');
            $table->index('circle');
            $table->index('event');
        });
    }
};
```

- [ ] **Step 5: Drop the `flags` cast from Work** (`app/Models/Work.php`)

Remove the `'flags' => 'array',` line from `$casts` (the column is gone). Keep `'entries' => 'array'`.

- [ ] **Step 6: Run the full suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: PASS (entire suite green on the tag model).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: drop legacy scalar metadata columns; tags are the source of truth"
```

---

## Task 8: Per-work tag curation (attach / detach / revert)

**Files:**
- Create: `app/Http/Controllers/WorkTagController.php`
- Modify: `routes/web.php`, `resources/views/work/show.blade.php`
- Test: `tests/Feature/Tagging/WorkTagControllerTest.php`

**Interfaces:**
- Consumes: `Tag`, `Work::tags()` (Task 1), `WorkTagSync` (Task 2), `Tag::TYPES`.
- Produces: routes `work.tags.attach` / `work.tags.detach` / `work.tags.reset` (POST) + `tags.suggest` (GET); each per-work edit sets `works.tags_locked = true`; reset clears it and re-derives. Alpine `workTags` editor on the work page.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Tagging/WorkTagControllerTest.php`

```php
<?php

namespace Tests\Feature\Tagging;

use App\Models\Tag;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsTags;
use Tests\TestCase;

class WorkTagControllerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTags;

    public function test_attach_creates_links_and_locks(): void
    {
        $work = Work::factory()->create();

        $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'theme', 'value' => 'netorare'])
            ->assertStatus(201);

        $tag = Tag::where('type', 'theme')->where('value', 'netorare')->firstOrFail();
        $this->assertTrue($work->fresh()->tags->contains($tag));
        $this->assertTrue($work->fresh()->tags_locked);
    }

    public function test_attach_resolves_alias_to_canonical(): void
    {
        $work = Work::factory()->create();
        $canon = Tag::create(['type' => 'parody', 'value' => 'Fate/Grand Order']);
        Tag::create(['type' => 'parody', 'value' => 'FGO', 'merged_into_id' => $canon->id]);

        $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'parody', 'value' => 'FGO'])->assertStatus(201);

        $this->assertSame([$canon->id], $work->fresh()->tags->pluck('id')->all());
    }

    public function test_attach_validates_type_and_value(): void
    {
        $work = Work::factory()->create();
        $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'bogus', 'value' => 'x'])->assertStatus(422);
        $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'circle', 'value' => '  '])->assertStatus(422);
    }

    public function test_detach_removes_and_locks(): void
    {
        $work = Work::factory()->create();
        $tag = $this->attachTag($work, 'circle', 'A');

        $this->postJson('/work/'.$work->id.'/tags/detach', ['tag_id' => $tag->id])->assertOk();

        $this->assertFalse($work->fresh()->tags->contains($tag));
        $this->assertTrue($work->fresh()->tags_locked);
    }

    public function test_reset_unlocks_and_rederives_from_filename(): void
    {
        // filename parses to circle "Z.A.P." + title; a stray manual tag is wiped on reset.
        $work = Work::factory()->create(['filename' => '[Z.A.P.] Title.zip', 'tags_locked' => true]);
        $this->attachTag($work, 'theme', 'stray');

        $this->postJson('/work/'.$work->id.'/tags/reset')->assertOk();

        $work->refresh();
        $this->assertFalse($work->tags_locked);
        $this->assertSame([['circle', 'Z.A.P.']], $work->tags->map(fn (Tag $t) => [$t->type, $t->value])->all());
    }

    public function test_suggest_returns_matching_canonical_values(): void
    {
        $w = Work::factory()->create();
        $this->attachTag($w, 'circle', 'Zucchini');
        $this->attachTag($w, 'circle', 'Zenith');
        $this->attachTag($w, 'parody', 'Other');

        $this->getJson('/tags/suggest?type=circle&q=Zu')->assertOk()->assertExactJson(['Zucchini']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=WorkTagControllerTest`
Expected: FAIL — routes/controller missing (404/`Class not found`).

- [ ] **Step 3: Create the controller** (`app/Http/Controllers/WorkTagController.php`)

```php
<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\Work;
use App\Tagging\WorkTagSync;
use Illuminate\Http\Request;

/**
 * Per-work manual tag editing (F4). Every edit sets tags_locked so the scanner
 * won't re-derive the work; reset clears it and re-derives. / 作品別タグ編集。
 */
final class WorkTagController extends Controller
{
    public function attach(Request $request, Work $work)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', Tag::TYPES)],
            'value' => ['required', 'string', 'max:255'],
        ]);
        $value = trim($data['value']);
        abort_if($value === '', 422, 'Value is required.');

        $tag = Tag::firstOrCreate(['type' => $data['type'], 'value' => $value]);
        $canonicalId = (int) ($tag->merged_into_id ?? $tag->id);
        $work->tags()->syncWithoutDetaching([$canonicalId]);
        $work->update(['tags_locked' => true]);

        return response()->json(['ok' => true, 'tag_id' => $canonicalId], 201);
    }

    public function detach(Request $request, Work $work)
    {
        $data = $request->validate(['tag_id' => ['required', 'integer']]);
        $work->tags()->detach($data['tag_id']);
        $work->update(['tags_locked' => true]);

        return response()->json(['ok' => true]);
    }

    public function reset(Work $work, WorkTagSync $sync)
    {
        $work->update(['tags_locked' => false]);
        $sync->sync($work); // re-derive from the filename / ファイル名から再導出
        $work->load('tags');

        return response()->json(['ok' => true]);
    }

    public function suggest(Request $request, WorkTagSync $sync)
    {
        $type = (string) $request->query('type', '');
        abort_unless(in_array($type, Tag::TYPES, true), 422);
        $q = trim((string) $request->query('q', ''));

        $values = Tag::query()->canonical()->where('type', $type)
            ->when($q !== '', function ($query) use ($q): void {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->whereRaw("value LIKE ? ESCAPE '!'", [$term]);
            })
            ->orderBy('sort_value')->limit(10)->pluck('value');

        return response()->json($values);
    }
}
```
(The `WorkTagSync` injection on `suggest` is unused but harmless; drop the param if your linter objects — keep `reset`'s.)

- [ ] **Step 4: Register the routes** (`routes/web.php`)

Add `use App\Http\Controllers\WorkTagController;`. Add (group them with the other `/work` routes, suggest before the param routes):
```php
Route::get('/tags/suggest', [WorkTagController::class, 'suggest'])->name('tags.suggest');
Route::post('/work/{work}/tags/attach', [WorkTagController::class, 'attach'])->name('work.tags.attach');
Route::post('/work/{work}/tags/detach', [WorkTagController::class, 'detach'])->name('work.tags.detach');
Route::post('/work/{work}/tags/reset', [WorkTagController::class, 'reset'])->name('work.tags.reset');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=WorkTagControllerTest`
Expected: PASS.

- [ ] **Step 6: Add the editor UI to the work page** (`resources/views/work/show.blade.php`)

Wrap the right-hand column (`<div style="flex:1; min-width:260px;">`) so it carries Alpine state. Change that opening tag to:
```blade
            <div style="flex:1; min-width:260px;" x-data="workTags(@js(['id' => $work->id, 'locked' => $work->tags_locked, 'tags' => $work->tags->map(fn ($t) => ['id' => $t->id, 'type' => $t->type, 'value' => $t->value])->values()->all(), 'types' => \App\Models\Tag::TYPES]))">
```
Then, just before the closing `</div>` of that column (after the Read button block), add the editor:
```blade
                <div style="margin-top:var(--space-xl); border-top:1px solid var(--color-hairline); padding-top:var(--space-md);">
                    <div class="flex items-center" style="gap:var(--space-sm); margin-bottom:var(--space-sm);">
                        <span style="font:var(--type-fine); letter-spacing:0.4px; text-transform:uppercase; color:var(--text-muted);">Edit tags</span>
                        <span x-show="locked" style="font:var(--type-fine); color:var(--text-muted);">· manual</span>
                        <button type="button" x-show="locked" @click="reset()" :disabled="busy"
                                style="background:none; border:none; padding:0; cursor:pointer; font:var(--type-fine); color:var(--text-link);">Revert to auto</button>
                    </div>

                    <div class="flex" style="gap:var(--space-xs); flex-wrap:wrap; margin-bottom:var(--space-sm);">
                        <template x-for="t in tags" :key="t.id + ':' + t.type">
                            <span class="flex items-center" style="gap:4px; padding:3px 6px 3px 10px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); font:var(--type-caption); color:var(--text-body);">
                                <span x-text="t.type + ': ' + t.value"></span>
                                <button type="button" @click="detach(t)" :disabled="busy" aria-label="Remove tag"
                                        style="background:none; border:none; cursor:pointer; color:var(--text-muted); font:inherit; line-height:1;">✕</button>
                            </span>
                        </template>
                    </div>

                    <div class="flex items-center" style="gap:var(--space-xs); flex-wrap:wrap; position:relative;">
                        <select x-model="newType" style="padding:6px 9px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
                            <template x-for="ty in types" :key="ty"><option :value="ty" x-text="ty"></option></template>
                        </select>
                        <input type="text" x-model="newValue" @input.debounce.200ms="suggest()" @keydown.enter.prevent="attach()" placeholder="value…"
                               style="flex:1; min-width:140px; padding:6px 9px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
                        <button type="button" @click="attach()" :disabled="busy || ! newValue.trim()"
                                style="padding:6px 14px; border:none; border-radius:var(--radius-pill); background:var(--color-primary); color:var(--color-on-primary); font:var(--type-caption); cursor:pointer;">Add</button>

                        <div x-show="suggestions.length" style="position:absolute; top:100%; left:0; right:0; margin-top:4px; background:var(--surface-card); border:1px solid var(--color-hairline); border-radius:var(--radius-sm); z-index:20;">
                            <template x-for="s in suggestions" :key="s">
                                <button type="button" @click="newValue = s; suggestions = []" class="w-full" style="display:block; text-align:left; padding:5px 10px; background:none; border:none; cursor:pointer; font:var(--type-caption); color:var(--text-body);" x-text="s"></button>
                            </template>
                        </div>
                    </div>

                    <div x-show="error" x-text="error" style="margin-top:var(--space-xs); color:var(--color-error); font:var(--type-caption);"></div>
                </div>
```
And add the Alpine registration at the end of `@section('content')` (before `@endsection`), mirroring `seriesManager`'s `post()` pattern:
```blade
    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('workTags', (initial) => ({
            id: initial.id,
            locked: initial.locked ?? false,
            tags: initial.tags ?? [],
            types: initial.types ?? [],
            newType: (initial.types ?? ['circle'])[0],
            newValue: '',
            suggestions: [],
            busy: false,
            error: '',

            async post(url, body) {
                this.busy = true; this.error = '';
                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                        },
                        body: JSON.stringify(body ?? {}),
                    });
                    if (! res.ok) throw new Error('http ' + res.status);
                    window.location.reload();
                } catch (e) { this.error = 'Action failed — try again.'; this.busy = false; }
            },
            attach() {
                const v = this.newValue.trim();
                if (v) this.post('/work/' + this.id + '/tags/attach', { type: this.newType, value: v });
            },
            detach(t) { this.post('/work/' + this.id + '/tags/detach', { tag_id: t.id }); },
            reset() { this.post('/work/' + this.id + '/tags/reset'); },
            async suggest() {
                const q = this.newValue.trim();
                if (! q) { this.suggestions = []; return; }
                try {
                    const res = await fetch('/tags/suggest?type=' + encodeURIComponent(this.newType) + '&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                    this.suggestions = await res.json();
                } catch (e) { this.suggestions = []; }
            },
        }));
    });
    </script>
```

- [ ] **Step 7: Run the focused suite + build**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter='WorkTagControllerTest|SeriesAndWorkTest'`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/WorkTagController.php routes/web.php resources/views/work/show.blade.php tests/Feature/Tagging/WorkTagControllerTest.php
git commit -m "feat: per-work tag editing (attach/detach/revert) + suggest"
```

---

## Task 9: Global rename / merge (`/tags` page)

**Files:**
- Create: `app/Http/Controllers/TagController.php`, `resources/views/tags/index.blade.php`
- Modify: `routes/web.php`, `resources/views/components/nav.blade.php`
- Test: `tests/Feature/Tagging/TagControllerTest.php`

**Interfaces:**
- Consumes: `Tag` (Task 1), `WorkTagSync` (Task 2, for the durability assertion), `ParsedName::deriveSortTitle`.
- Produces: routes `tags.index` (GET `/tags`), `tags.rename` (POST `/tags/{tag}/rename`), `tags.merge` (POST `/tags/{tag}/merge`); rename writes a tombstone the scanner resolves; merge repoints/dedupes pivots, flattens chains; `/tags` management page + nav link.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Tagging/TagControllerTest.php`

```php
<?php

namespace Tests\Feature\Tagging;

use App\Models\Tag;
use App\Models\Work;
use App\Parsing\ParsedName;
use App\Tagging\WorkTagSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsTags;
use Tests\TestCase;

class TagControllerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTags;

    public function test_index_lists_canonical_tags_with_counts(): void
    {
        $w = Work::factory()->create();
        $this->attachTag($w, 'circle', 'Z.A.P.');

        $this->get('/tags')->assertOk()->assertSee('Z.A.P.');
    }

    public function test_rename_in_place_creates_tombstone_that_scanner_resolves(): void
    {
        $w = Work::factory()->create();
        $tag = $this->attachTag($w, 'parody', 'FGO');

        $this->postJson('/tags/'.$tag->id.'/rename', ['value' => 'Fate/Grand Order'])->assertOk();

        $tag->refresh();
        $this->assertSame('Fate/Grand Order', $tag->value);
        // A tombstone for the old value now points at the renamed tag.
        $tombstone = Tag::where('type', 'parody')->where('value', 'FGO')->firstOrFail();
        $this->assertSame($tag->id, $tombstone->merged_into_id);

        // The scanner re-deriving the raw "FGO" resolves to the canonical tag.
        $other = Work::factory()->create();
        app(WorkTagSync::class)->sync($other, ParsedName::make('t', 'raw', parody: 'FGO'));
        $this->assertSame([$tag->id], $other->tags()->pluck('tags.id')->all());
    }

    public function test_rename_onto_existing_value_merges(): void
    {
        $w1 = Work::factory()->create(); $a = $this->attachTag($w1, 'circle', 'A');
        $w2 = Work::factory()->create(); $b = $this->attachTag($w2, 'circle', 'B');

        $this->postJson('/tags/'.$a->id.'/rename', ['value' => 'B'])->assertOk();

        $this->assertSame($b->id, $a->fresh()->merged_into_id); // A becomes an alias of B
        $this->assertTrue($w1->fresh()->tags->contains($b));
    }

    public function test_merge_repoints_dedupes_and_flattens(): void
    {
        $shared = Work::factory()->create();
        $onlyA = Work::factory()->create();
        $a = $this->attachTag($shared, 'circle', 'A'); $this->attachTag($onlyA, 'circle', 'A');
        $b = $this->attachTag($shared, 'circle', 'B'); // shared already has B → dedupe
        $c = Tag::create(['type' => 'circle', 'value' => 'C', 'merged_into_id' => $a->id]); // chain into A

        $this->postJson('/tags/'.$a->id.'/merge', ['into_id' => $b->id])->assertOk();

        $this->assertSame($b->id, $a->fresh()->merged_into_id);
        $this->assertSame($b->id, $c->fresh()->merged_into_id);        // chain flattened A→B
        $this->assertSame(0, $a->fresh()->works()->count());           // pivots moved off A
        $this->assertTrue($onlyA->fresh()->tags->contains($b));        // repointed
        $this->assertSame(1, $shared->fresh()->tags()->where('tags.id', $b->id)->count()); // deduped
    }

    public function test_merge_validates(): void
    {
        $w = Work::factory()->create();
        $a = $this->attachTag($w, 'circle', 'A');
        $p = $this->attachTag($w, 'parody', 'A');

        $this->postJson('/tags/'.$a->id.'/merge', ['into_id' => $a->id])->assertStatus(422); // into self
        $this->postJson('/tags/'.$a->id.'/merge', ['into_id' => $p->id])->assertStatus(422); // cross type
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=TagControllerTest`
Expected: FAIL — routes/controller/view missing.

- [ ] **Step 3: Create the controller** (`app/Http/Controllers/TagController.php`)

```php
<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Parsing\ParsedName;
use Illuminate\Http\Request;

/**
 * Global tag management — rename / merge (F4). Both write a merge-alias so the
 * scanner permanently normalizes the old raw value. / タグ管理（リネーム/統合）。
 */
final class TagController extends Controller
{
    public function index()
    {
        $tagsByType = Tag::query()->canonical()
            ->withCount('works')
            ->orderBy('type')->orderBy('sort_value')
            ->get()
            ->groupBy('type');

        return view('tags.index', compact('tagsByType'));
    }

    public function rename(Request $request, Tag $tag)
    {
        abort_if($tag->merged_into_id !== null, 422, 'Tag is an alias.');
        $data = $request->validate(['value' => ['required', 'string', 'max:255']]);
        $value = trim($data['value']);
        abort_if($value === '', 422, 'Value is required.');
        if ($value === $tag->value) {
            return response()->json(['ok' => true]);
        }

        $existing = Tag::query()->canonical()->where('type', $tag->type)->where('value', $value)->first();
        if ($existing !== null) {
            return $this->mergeInto($tag, $existing);
        }

        $old = $tag->value;
        $tag->update(['value' => $value, 'sort_value' => ParsedName::deriveSortTitle($value)]);
        // Tombstone the old value so re-derivation normalizes to the renamed tag. / 旧値を別名化。
        Tag::create(['type' => $tag->type, 'value' => $old, 'merged_into_id' => $tag->id]);

        return response()->json(['ok' => true]);
    }

    public function merge(Request $request, Tag $tag)
    {
        $data = $request->validate(['into_id' => ['required', 'integer']]);
        $into = Tag::findOrFail($data['into_id']);
        abort_if($into->id === $tag->id, 422, 'Cannot merge a tag into itself.');
        abort_if($into->type !== $tag->type, 422, 'Tags are different types.');
        abort_if($into->merged_into_id !== null, 422, 'Target is an alias.');

        return $this->mergeInto($tag, $into);
    }

    /** Repoint $from's works to $into, tombstone $from, flatten chains. / 統合本体。 */
    private function mergeInto(Tag $from, Tag $into)
    {
        $workIds = $from->works()->pluck('works.id')->all();
        $into->works()->syncWithoutDetaching($workIds);
        $from->works()->detach();
        $from->update(['merged_into_id' => $into->id]);
        Tag::where('merged_into_id', $from->id)->update(['merged_into_id' => $into->id]);

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Register the routes + nav link**

`routes/web.php` — add `use App\Http\Controllers\TagController;` and:
```php
Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
Route::post('/tags/{tag}/rename', [TagController::class, 'rename'])->name('tags.rename');
Route::post('/tags/{tag}/merge', [TagController::class, 'merge'])->name('tags.merge');
```
`resources/views/components/nav.blade.php` — add a Tags link after the Browse link (line ~9):
```blade
        <a href="/tags" class="no-underline {{ $active === 'tags' ? '[color:var(--color-on-dark)]' : '[color:var(--color-body-muted)]' }} hover:[color:var(--color-on-dark)]" style="font:var(--type-nav);">Tags</a>
```

- [ ] **Step 5: Create the `/tags` view** (`resources/views/tags/index.blade.php`)

```blade
@extends('layouts.app')

@php
    $initial = ['groups' => $tagsByType->map(fn ($tags, $type) => [
        'type' => $type,
        'tags' => $tags->map(fn ($t) => ['id' => $t->id, 'value' => $t->value, 'count' => $t->works_count])->values()->all(),
    ])->values()->all()];
@endphp

@section('content')
    <x-nav active="tags" />

    <main class="mx-auto w-full" style="max-width:var(--container-text); padding:var(--space-xl) var(--space-lg);"
          x-data="tagManager(@js($initial))">
        <h1 style="margin:0 0 var(--space-lg); font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">Tags</h1>

        <template x-if="groups.length === 0">
            <p style="font:var(--type-body); color:var(--text-muted);">No tags yet. Run a scan from Maintenance.</p>
        </template>

        <template x-for="group in groups" :key="group.type">
            <section style="margin-bottom:var(--space-xxl);">
                <x-section-heading><span x-text="group.type"></span></x-section-heading>
                <template x-for="tag in group.tags" :key="tag.id">
                    <div class="flex items-center" style="gap:var(--space-sm); padding:var(--space-xs) 0; border-bottom:1px solid var(--color-hairline);">
                        <template x-if="editing !== tag.id">
                            <span class="truncate" style="flex:1; font:var(--type-body); color:var(--text-body);" x-text="tag.value"></span>
                        </template>
                        <template x-if="editing === tag.id">
                            <input type="text" x-model="editValue" @keydown.enter.prevent="rename(tag)" @keydown.escape="editing = null"
                                   style="flex:1; padding:5px 9px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-body);">
                        </template>

                        <span style="font:var(--type-fine); color:var(--text-muted);" x-text="tag.count"></span>

                        <button type="button" x-show="editing !== tag.id" @click="editing = tag.id; editValue = tag.value"
                                style="background:none; border:none; padding:0; cursor:pointer; font:var(--type-fine); color:var(--text-link);">Rename</button>
                        <button type="button" x-show="editing === tag.id" @click="rename(tag)" :disabled="busy"
                                style="background:none; border:none; padding:0; cursor:pointer; font:var(--type-fine); color:var(--text-link);">Save</button>

                        <select @change="mergeTag(tag, $event.target.value); $event.target.value = ''" :disabled="busy"
                                style="padding:4px 8px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-fine);">
                            <option value="">Merge into…</option>
                            <template x-for="t in group.tags.filter((o) => o.id !== tag.id)" :key="t.id">
                                <option :value="t.id" x-text="t.value"></option>
                            </template>
                        </select>
                    </div>
                </template>
            </section>
        </template>

        <div x-show="error" x-text="error" style="color:var(--color-error); font:var(--type-caption);"></div>
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('tagManager', (initial) => ({
            groups: initial.groups ?? [],
            editing: null,
            editValue: '',
            busy: false,
            error: '',

            async post(url, body) {
                this.busy = true; this.error = '';
                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                        },
                        body: JSON.stringify(body ?? {}),
                    });
                    if (! res.ok) throw new Error('http ' + res.status);
                    window.location.reload();
                } catch (e) { this.error = 'Action failed — try again.'; this.busy = false; }
            },
            rename(tag) {
                const v = this.editValue.trim();
                if (v && v !== tag.value) this.post('/tags/' + tag.id + '/rename', { value: v });
                else this.editing = null;
            },
            mergeTag(tag, intoId) {
                if (intoId) this.post('/tags/' + tag.id + '/merge', { into_id: Number(intoId) });
            },
        }));
    });
    </script>
@endsection
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=TagControllerTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TagController.php resources/views/tags/index.blade.php routes/web.php resources/views/components/nav.blade.php tests/Feature/Tagging/TagControllerTest.php
git commit -m "feat: /tags management — global rename/merge (durable via aliases)"
```

---

## Task 10: Asset build + full-suite gate + browser render-verify gate

**Files:** none (verification + build artifacts only).

**Interfaces:**
- Consumes: everything above.
- Produces: compiled assets; a fully green suite; verified interactive behavior in light + dark.

- [ ] **Step 1: Build the frontend assets**

Run: `npm run build`
Expected: Vite compiles to `public/build` with no errors.

- [ ] **Step 2: Run the full test suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: PASS — entire suite green (this is the CI gate).

- [ ] **Step 3: Browser render-verify gate** (the `agent-browser` skill; not PHPUnit)

Serve + seed the local dev SQLite per the project memory `wydoujin-local-browser-gate` (env-injecting router; `LIBRARY_PATH` defaults to the non-writable `/library`; a scan needs a running `php artisan queue:work`). Verify, in **both light and dark** themes, with no console errors:
- **Backfill:** after migrating the seeded dev DB, `/tags` lists the expected tags with usage counts (confirms the one-time `LegacyScalarBackfill`).
- **/browse:** all six facet groups appear when populated; checking values filters; counts are dynamic; deep-link URL (`?circle[]=…`) round-trips.
- **Work detail:** tags render as clickable links → land on a pre-filtered `/browse`; the editor adds a tag (autocomplete suggests), removes a tag, and "Revert to auto" restores parsed tags; the "· manual" marker appears once locked.
- **/tags:** rename a tag (inline) and merge one tag into another; the page reloads reflecting the change; a re-scan does not resurrect a merged value.

- [ ] **Step 4: Commit (only if the build emitted tracked changes)**

```bash
git add -A
git commit -m "build: compile assets for multi-value tags (F4)" || echo "nothing to commit"
```

---

## Self-Review

**Spec coverage** (against `2026-06-23-wydoujin-tags-design.md`):
- §4.1 schema (`tags`, `work_tag`, `tags_locked`, `merged_into_id`, drop 6 cols) → Tasks 1, 7. ✓
- §4.2 parser unchanged; `WorkTagSync` (skip-locked, alias-resolve, orphan-prune); scanner write-path; reset re-parses filename → Tasks 2, 3, 8. ✓
- §4.3 per-work attach/detach/reset + suggest; global rename/merge (tombstone, repoint, flatten) → Tasks 8, 9. ✓
- §4.4 6 dims; pivot facets/filters; URL contract unchanged; eager-load tags → Tasks 4, 5. ✓
- §5 views (clickable links, card, browse rail, /tags, nav) → Tasks 5, 8, 9. ✓
- §6 edge cases (alias attach, rename-onto-existing→merge, merge guards, idempotent detach, locked-skip, orphan rules) → covered by tests in Tasks 2, 8, 9. ✓
- §7 migrations A/B/C ordered & portable → Tasks 1, 6, 7. ✓
- §8 testing (WorkTagSync, scanner, facets, curation, views, browser gate, backfill at gate) → Tasks 2–10. ✓
- §9 out of scope (no parser splitting, no un-merge, no language type) → not built. ✓

**Type consistency:** `Tag::TYPES`/`AUTO_TYPES`, `Tag::canonical()`, `Tag::browseUrl()`, `Tag::aliases()`, `Work::tags()`, `WorkTagSync::{sync,derive,pruneOrphans}`, `WorkSearch::DIMENSIONS` (6), `LegacyScalarBackfill::run()`, route names (`work.tags.*`, `tags.suggest`, `tags.{index,rename,merge}`), `SeedsTags::attachTag()` — used identically across tasks. ✓

**Placeholder scan:** every step has concrete code/commands; no TBD/TODO. ✓

**Ordering invariant:** scanner write-flip (T3) precedes read migrations (T4–T5); backfill (T6) precedes column drop (T7); the obsolete backfill test is removed in T7; the suite is green after every task. ✓
