# Scanner Refinement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the library scanner correctly handle a real doujin library — nested files, title-first filenames, `_series`/`_small` buckets, and folder-name author derivation — without losing data.

**Architecture:** A new pure resolver (`PathMetadataResolver`) turns a work's library-relative path into `{ mangakaName, ParsedName }`, where the `ParsedName` is enriched with folder-derived and subfolder-derived tags via a new `extraTags` field. Discovery becomes recursive; mangaka resolution stays sequential in `LibraryScanner::planJobs` (no-race). Because every derived field is a pure function of `relative_path` + the basename, the scan path and the rescan re-derive path produce identical tags.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4 (in-memory SQLite), PCOV for coverage.

**Spec:** `docs/superpowers/specs/2026-06-26-wydoujin-scanner-refinement-design.md`

## Global Constraints

- **PHP toolchain quirk:** prefix every `php`/`artisan`/`composer`/`pest` command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (PHP 8.5). Node is on the normal PATH.
- **No schema changes.** Everything rides the existing `tags(type,value)` + `work_tag` model.
- **Identity is `content_hash`, never path.** Do not touch work identity.
- **Tag durability:** `tags_locked` works are skipped by the scanner; derived tags must be reconstructible from stored `relative_path` + filename so rescans never strip them.
- **Bucket folders are exactly `_series` and `_small`** (explicit allowlist). Every other top folder — including `_雑誌` — is a literal mangaka.
- **Bucket mangaka name:** prefer the filename-derived `author`, then `circle`, else the `Unknown` sentinel.
- **TDD, real filenames as fixtures, frequent small commits. Target stays 100% line coverage of `app/`.**
- **Test command:** `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest`. Coverage: `PATH="/opt/homebrew/opt/php/bin:$PATH" php -d pcov.enabled=1 vendor/bin/pest --coverage`.

---

### Task 1: Add `extraTags` to `ParsedName`

**Files:**
- Modify: `app/Parsing/ParsedName.php`
- Test: `tests/Unit/Parsing/ParsedNameTest.php`

**Interfaces:**
- Produces: `ParsedName::$extraTags` (`list<array{0:string,1:string}>`, default `[]`); `ParsedName::make(..., array $extraTags = [])`; `ParsedName::withExtraTags(array $extraTags): self` (returns a copy with tags appended).

- [ ] **Step 1: Write the failing test** — append to `tests/Unit/Parsing/ParsedNameTest.php`:

```php
test('extra tags default empty and withExtraTags appends immutably', function (): void {
    $base = ParsedName::make(title: 'T', titleRaw: 'T');
    $this->assertSame([], $base->extraTags);

    $with = $base->withExtraTags([['parody', '化物語'], ['author', '松果']]);
    $this->assertSame([['parody', '化物語'], ['author', '松果']], $with->extraTags);
    // original untouched (immutability) and the rest of the value object is carried over
    $this->assertSame([], $base->extraTags);
    $this->assertSame('T', $with->title);

    $more = $with->withExtraTags([['flag', 'DL版']]);
    $this->assertSame([['parody', '化物語'], ['author', '松果'], ['flag', 'DL版']], $more->extraTags);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/ParsedNameTest.php`
Expected: FAIL — `Unknown named parameter` / `extraTags` property undefined.

- [ ] **Step 3: Implement** — edit `app/Parsing/ParsedName.php`. Add the constructor param, the `make()` param, and a `withExtraTags()` method:

```php
    /**
     * @param string[] $flags
     * @param list<array{0:string,1:string}> $extraTags  folder/subfolder-derived [type,value] tags
     */
    public function __construct(
        public readonly string $title,
        public readonly string $titleRaw,
        public readonly string $sortTitle,
        public readonly ?string $event = null,
        public readonly ?string $circle = null,
        public readonly ?string $author = null,
        public readonly ?string $parody = null,
        public readonly array $flags = [],
        public readonly array $extraTags = [],
    ) {
    }
```

In `make()` add `array $extraTags = []` as the final parameter and pass `extraTags: $extraTags` into the `new self(...)`. Then add:

```php
    /**
     * Copy with extra [type,value] tags appended (folder/subfolder enrichment). / 追加タグを付与した複製。
     *
     * @param list<array{0:string,1:string}> $extraTags
     */
    public function withExtraTags(array $extraTags): self
    {
        return new self(
            title: $this->title,
            titleRaw: $this->titleRaw,
            sortTitle: $this->sortTitle,
            event: $this->event,
            circle: $this->circle,
            author: $this->author,
            parody: $this->parody,
            flags: $this->flags,
            extraTags: [...$this->extraTags, ...$extraTags],
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/ParsedNameTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Parsing/ParsedName.php tests/Unit/Parsing/ParsedNameTest.php
git commit -m "Add extraTags field + withExtraTags to ParsedName"
```

---

### Task 2: Extract bracket-peeling into a `PeelsGroups` trait

Behavior-preserving refactor so the new patterns can reuse `StandardDoujinPattern`'s peel helpers (DRY). The existing `StandardDoujinPattern` tests are the regression net.

**Files:**
- Create: `app/Parsing/Patterns/PeelsGroups.php`
- Modify: `app/Parsing/Patterns/StandardDoujinPattern.php`

**Interfaces:**
- Produces: trait `App\Parsing\Patterns\PeelsGroups` with `peelLeadingGroup(string &$rest, string $open, string $close): ?string`, `peelTrailingGroup(string &$rest, string $open, string $close): ?string`, `peelTrailingFlags(string &$rest): array` (returns `string[]` left-to-right), `splitCircleAuthor(?string $block): array` (returns `array{0:?string,1:?string}`).

- [ ] **Step 1: Confirm the existing pattern tests pass (baseline)**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/Patterns`
Expected: PASS (these guard the refactor).

- [ ] **Step 2: Create the trait** — `app/Parsing/Patterns/PeelsGroups.php`. Move the four private methods out of `StandardDoujinPattern` verbatim, changing the file to a trait:

```php
<?php

namespace App\Parsing\Patterns;

/**
 * Shared bracket-group peeling for filename patterns. / ファイル名の括弧群剥がし（共有）。
 */
trait PeelsGroups
{
    /** Peel a leading $open...$close group; trims $rest; null if absent. / 先頭の括弧群を剥がす。 */
    private function peelLeadingGroup(string &$rest, string $open, string $close): ?string
    {
        $rest = ltrim($rest);
        if ($rest === '' || $rest[0] !== $open) {
            return null;
        }
        $closePos = strpos($rest, $close);
        if ($closePos === false) {
            return null;
        }
        $inner = substr($rest, 1, $closePos - 1);
        $rest = ltrim(substr($rest, $closePos + 1));

        return trim($inner);
    }

    /** Peel a trailing $open...$close group; trims $rest; null if absent. / 末尾の括弧群を剥がす。 */
    private function peelTrailingGroup(string &$rest, string $open, string $close): ?string
    {
        $rest = rtrim($rest);
        $len = strlen($rest);
        if ($len === 0 || $rest[$len - 1] !== $close) {
            return null;
        }
        $openPos = strrpos($rest, $open);
        if ($openPos === false) {
            return null;
        }
        $inner = substr($rest, $openPos + 1, $len - $openPos - 2);
        $rest = rtrim(substr($rest, 0, $openPos));

        return trim($inner);
    }

    /**
     * Peel all trailing [flags], returned left-to-right as they appear. / 末尾の[フラグ]を全て剥がす。
     *
     * @return string[]
     */
    private function peelTrailingFlags(string &$rest): array
    {
        $flags = [];
        while (($flag = $this->peelTrailingGroup($rest, '[', ']')) !== null) {
            array_unshift($flags, $flag);
        }

        return $flags;
    }

    /**
     * Split a "[...]" inner block into circle + optional trailing (author). / サークルと(作者)に分割。
     *
     * @return array{0: ?string, 1: ?string} [circle, author]
     */
    private function splitCircleAuthor(?string $block): array
    {
        if ($block === null || $block === '') {
            return [null, null];
        }
        $rest = $block;
        $author = $this->peelTrailingGroup($rest, '(', ')');
        $circle = trim($rest);

        return [$circle !== '' ? $circle : null, $author];
    }
}
```

- [ ] **Step 3: Update `StandardDoujinPattern`** — add `use PeelsGroups;` inside the class body (after the opening brace) and **delete** the four private methods now living in the trait. Keep `matches()` and `parse()` exactly as they are.

- [ ] **Step 4: Run the pattern tests to verify still green**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/Patterns`
Expected: PASS (no behavior change).

- [ ] **Step 5: Commit**

```bash
git add app/Parsing/Patterns/PeelsGroups.php app/Parsing/Patterns/StandardDoujinPattern.php
git commit -m "Extract bracket-peeling into a PeelsGroups trait"
```

---

### Task 3: `TrailingMetadataPattern` for title-first filenames (#2)

**Files:**
- Create: `app/Parsing/Patterns/TrailingMetadataPattern.php`
- Modify: `config/parser.php`
- Test: `tests/Unit/Parsing/Patterns/TrailingMetadataPatternTest.php`

**Interfaces:**
- Consumes: `PeelsGroups` trait (Task 2), `ParsedName::make` (Task 1).
- Produces: `App\Parsing\Patterns\TrailingMetadataPattern implements NamePattern`. Matches a filename with **no** leading `(`/`[` that **ends** in `)` or `]`; peels trailing `(parody)` + `[flags]`, the rest is the title. Registered in `config/parser.php` between `StandardDoujinPattern` and `FallbackPattern`.

- [ ] **Step 1: Write the failing test** — `tests/Unit/Parsing/Patterns/TrailingMetadataPatternTest.php`:

```php
<?php

use App\Parsing\Patterns\TrailingMetadataPattern;

test('matches title-first names with a trailing group only', function (): void {
    $p = new TrailingMetadataPattern();
    $this->assertTrue($p->matches('羽川ちゃんは語りたい (化物語) [DL版]'));
    $this->assertTrue($p->matches('乳乱舞 Vol.03 (ラグナロクオンライン)'));
    // leading bracket → StandardDoujinPattern's job, not this one
    $this->assertFalse($p->matches('(C89) [Z.A.P.] タイトル'));
    $this->assertFalse($p->matches('[サークル] タイトル'));
    // nothing to peel → leave it to the fallback
    $this->assertFalse($p->matches('はじめてのお泊りセックス 中編'));
});

test('peels trailing parody and flags, keeping the title', function (): void {
    $r = (new TrailingMetadataPattern())->parse('羽川ちゃんは語りたい (化物語) [DL版]', 'のり伍郎');
    $this->assertSame('羽川ちゃんは語りたい', $r->title);
    $this->assertSame('化物語', $r->parody);
    $this->assertSame(['DL版'], $r->flags);
    $this->assertNull($r->circle);
    $this->assertNull($r->event);
});

test('parody only, no flags', function (): void {
    $r = (new TrailingMetadataPattern())->parse('乳乱舞 Vol.03 (ラグナロクオンライン)', 'M');
    $this->assertSame('乳乱舞 Vol.03', $r->title);
    $this->assertSame('ラグナロクオンライン', $r->parody);
    $this->assertSame([], $r->flags);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/Patterns/TrailingMetadataPatternTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement** — `app/Parsing/Patterns/TrailingMetadataPattern.php`:

```php
<?php

namespace App\Parsing\Patterns;

use App\Parsing\NamePattern;
use App\Parsing\ParsedName;

/**
 * Title-first names: TITLE (PARODY) [FLAGS...] with NO leading bracket. Peels the trailing
 * groups so the parody + flags survive instead of being swallowed into the title.
 * 先頭括弧なしのタイトル先頭形式。末尾の(パロディ)と[フラグ]を剥がす。
 */
final class TrailingMetadataPattern implements NamePattern
{
    use PeelsGroups;

    public function matches(string $filename): bool
    {
        // No leading (event)/[circle], but there is a trailing group worth peeling. / 末尾に剥がす括弧がある場合。
        return ! preg_match('/^\s*[\(\[]/u', $filename)
            && (bool) preg_match('/[\)\]]\s*$/u', $filename);
    }

    public function parse(string $filename, string $mangaka): ParsedName
    {
        $rest = trim($filename);
        $flags = $this->peelTrailingFlags($rest);
        $parody = $this->peelTrailingGroup($rest, '(', ')');
        $title = trim($rest);

        return ParsedName::make(
            title: $title !== '' ? $title : trim($filename),
            titleRaw: $filename,
            parody: $parody,
            flags: $flags,
        );
    }
}
```

- [ ] **Step 4: Register in the parser registry** — edit `config/parser.php`: add the `use` import and slot the pattern before the fallback:

```php
use App\Parsing\Patterns\FallbackPattern;
use App\Parsing\Patterns\StandardDoujinPattern;
use App\Parsing\Patterns\TrailingMetadataPattern;

return [
    'patterns' => [
        StandardDoujinPattern::class,
        TrailingMetadataPattern::class,
        FallbackPattern::class,
    ],
];
```

- [ ] **Step 5: Run tests to verify pass** (unit + the registry-resolution feature test, which must stay green — `相姦マニュアル` still routes to the fallback because it has no trailing group):

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/Patterns/TrailingMetadataPatternTest.php tests/Feature/Parsing/FilenameParserResolutionTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Parsing/Patterns/TrailingMetadataPattern.php config/parser.php tests/Unit/Parsing/Patterns/TrailingMetadataPatternTest.php
git commit -m "Parse title-first filenames for trailing parody and flags"
```

---

### Task 4: `CircleTitlePattern` for `circle - title` bucket names

Not registered in `config/parser.php` — the resolver (Task 6) consults it **only** for bucket paths, so a normal title containing ` - ` is never split.

**Files:**
- Create: `app/Parsing/Patterns/CircleTitlePattern.php`
- Test: `tests/Unit/Parsing/Patterns/CircleTitlePatternTest.php`

**Interfaces:**
- Consumes: `PeelsGroups` (Task 2), `ParsedName::make` (Task 1).
- Produces: `App\Parsing\Patterns\CircleTitlePattern implements NamePattern`. `matches()` is true when there is no leading bracket and the name contains `' - '`. `parse()` splits on the first `' - '` into `circle` + `title`, still peeling any trailing `(parody)`/`[flags]` first.

- [ ] **Step 1: Write the failing test** — `tests/Unit/Parsing/Patterns/CircleTitlePatternTest.php`:

```php
<?php

use App\Parsing\Patterns\CircleTitlePattern;

test('matches bracketless names containing a dash separator', function (): void {
    $p = new CircleTitlePattern();
    $this->assertTrue($p->matches('from SCRATCH - のどかなペンギン'));
    $this->assertTrue($p->matches('肉りんご - 日本あげるよ'));
    $this->assertFalse($p->matches('はじめてのお泊りセックス 中編')); // no ' - '
    $this->assertFalse($p->matches('[サークル] タイトル'));           // leading bracket
});

test('splits circle from title on the first dash', function (): void {
    $r = (new CircleTitlePattern())->parse('from SCRATCH - のどかなペンギン', '');
    $this->assertSame('from SCRATCH', $r->circle);
    $this->assertSame('のどかなペンギン', $r->title);
    $this->assertNull($r->author);
});

test('still peels a trailing parody after splitting', function (): void {
    $r = (new CircleTitlePattern())->parse('G-Scan Corp - Le Beau Maitre 2 (EN)', '');
    $this->assertSame('G-Scan Corp', $r->circle);
    $this->assertSame('Le Beau Maitre 2', $r->title);
    $this->assertSame('EN', $r->parody); // (EN) into the parody slot is an accepted minor imperfection
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/Patterns/CircleTitlePatternTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement** — `app/Parsing/Patterns/CircleTitlePattern.php`:

```php
<?php

namespace App\Parsing\Patterns;

use App\Parsing\NamePattern;
use App\Parsing\ParsedName;

/**
 * 'CIRCLE - TITLE' names with no bracket block. Used ONLY for bucket paths (_series/_small),
 * where the artist must be recovered from the filename. The resolver gates this so a normal
 * title that happens to contain ' - ' is never split. / バケット専用の「サークル - タイトル」形式。
 */
final class CircleTitlePattern implements NamePattern
{
    use PeelsGroups;

    public function matches(string $filename): bool
    {
        return ! preg_match('/^\s*[\(\[]/u', $filename) && str_contains($filename, ' - ');
    }

    public function parse(string $filename, string $mangaka): ParsedName
    {
        $rest = trim($filename);
        $flags = $this->peelTrailingFlags($rest);
        $parody = $this->peelTrailingGroup($rest, '(', ')');

        [$circle, $title] = array_pad(explode(' - ', $rest, 2), 2, '');
        $circle = trim($circle);
        $title = trim($title);

        return ParsedName::make(
            title: $title !== '' ? $title : $rest,
            titleRaw: $filename,
            circle: $circle !== '' ? $circle : null,
            parody: $parody,
            flags: $flags,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/Patterns/CircleTitlePatternTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Parsing/Patterns/CircleTitlePattern.php tests/Unit/Parsing/Patterns/CircleTitlePatternTest.php
git commit -m "Add CircleTitlePattern for circle - title bucket filenames"
```

---

### Task 5: `MangakaFolder` folder-name parser (#4)

**Files:**
- Create: `app/Parsing/MangakaFolder.php`
- Test: `tests/Unit/Parsing/MangakaFolderTest.php`

**Interfaces:**
- Produces: `App\Parsing\MangakaFolder::tags(string $folder): array` returning `list<array{0:string,1:string}>` — `[['circle',X],['author',Y]]` for a trailing `(...)` form, else `[['author', folder]]`, else `[]` for an empty string.

- [ ] **Step 1: Write the failing test** — `tests/Unit/Parsing/MangakaFolderTest.php`:

```php
<?php

use App\Parsing\MangakaFolder;

test('circle (author) folder yields both tags', function (): void {
    $this->assertSame([['circle', '華容道'], ['author', '松果']], MangakaFolder::tags('華容道 (松果)'));
    $this->assertSame([['circle', 'スタジオBIG-X'], ['author', 'ありのひろし']], MangakaFolder::tags('スタジオBIG-X (ありのひろし)'));
});

test('romaji - japanese folder yields one author tag = whole name', function (): void {
    $this->assertSame([['author', 'Aiueoka - 愛上陸']], MangakaFolder::tags('Aiueoka - 愛上陸'));
});

test('plain folder yields one author tag', function (): void {
    $this->assertSame([['author', 'れむ']], MangakaFolder::tags('れむ'));
});

test('empty folder yields no tags', function (): void {
    $this->assertSame([], MangakaFolder::tags(''));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/MangakaFolderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement** — `app/Parsing/MangakaFolder.php`:

```php
<?php

namespace App\Parsing;

/**
 * Derives circle/author tags from a top-folder (mangaka) name. The folder string stays the
 * mangaka's display name; this only produces tags for faceting. / フォルダ名からサークル/作者タグを導出。
 *
 * - "Circle (Author)"      → [['circle', Circle], ['author', Author]]
 * - "Romaji - Japanese" / plain → [['author', whole folder]]
 */
final class MangakaFolder
{
    /** @return list<array{0:string,1:string}> */
    public static function tags(string $folder): array
    {
        $folder = trim($folder);
        if ($folder === '') {
            return [];
        }

        if (preg_match('/^(.*?)\s*\(([^()]+)\)\s*$/u', $folder, $m) && trim($m[2]) !== '') {
            $tags = [];
            if (trim($m[1]) !== '') {
                $tags[] = ['circle', trim($m[1])];
            }
            $tags[] = ['author', trim($m[2])];

            return $tags;
        }

        return [['author', $folder]];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/MangakaFolderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Parsing/MangakaFolder.php tests/Unit/Parsing/MangakaFolderTest.php
git commit -m "Derive circle/author tags from the mangaka folder name"
```

---

### Task 6: `PathMetadata` + `PathMetadataResolver`

The keystone: relative path → `{ mangakaName, ParsedName }`, enriched for normal/bucket/`_series` paths.

**Files:**
- Create: `app/Parsing/PathMetadata.php`
- Create: `app/Parsing/PathMetadataResolver.php`
- Test: `tests/Unit/Parsing/PathMetadataResolverTest.php`

**Interfaces:**
- Consumes: `FilenameParser` (config registry), `CircleTitlePattern` (Task 4), `MangakaFolder` (Task 5), `ParsedName::withExtraTags` (Task 1).
- Produces:
  - `App\Parsing\PathMetadata` — readonly DTO: `__construct(public readonly string $mangakaName, public readonly ParsedName $parsed)`.
  - `App\Parsing\PathMetadataResolver::__construct(FilenameParser $parser, CircleTitlePattern $circleTitle)` and `resolve(string $relativePath): PathMetadata`.

- [ ] **Step 1: Write the failing test** — `tests/Unit/Parsing/PathMetadataResolverTest.php`:

```php
<?php

use App\Parsing\FilenameParser;
use App\Parsing\PathMetadataResolver;
use App\Parsing\Patterns\CircleTitlePattern;
use App\Parsing\Patterns\FallbackPattern;
use App\Parsing\Patterns\StandardDoujinPattern;
use App\Parsing\Patterns\TrailingMetadataPattern;

function resolverUnderTest(): PathMetadataResolver
{
    $parser = new FilenameParser([
        new StandardDoujinPattern(),
        new TrailingMetadataPattern(),
        new FallbackPattern(),
    ]);

    return new PathMetadataResolver($parser, new CircleTitlePattern());
}

/** @return list<array{0:string,1:string}> sorted [type,value] pairs derived for a path */
function tagPairsFor(string $relativePath): array
{
    $p = resolverUnderTest()->resolve($relativePath)->parsed;
    $pairs = [];
    foreach (['circle', 'parody', 'event', 'author'] as $t) {
        if ($p->{$t} !== null && $p->{$t} !== '') {
            $pairs[] = [$t, $p->{$t}];
        }
    }
    foreach ($p->flags as $f) {
        $pairs[] = ['flag', $f];
    }
    foreach ($p->extraTags as $pair) {
        $pairs[] = $pair;
    }
    sort($pairs);

    return $pairs;
}

test('normal folder: mangaka is the folder, author derived from it', function (): void {
    $meta = resolverUnderTest()->resolve('華容道 (松果)/羽川ちゃんは語りたい (化物語) [DL版].zip');
    $this->assertSame('華容道 (松果)', $meta->mangakaName);
    $this->assertSame('羽川ちゃんは語りたい', $meta->parsed->title);
    $this->assertContains(['circle', '華容道'], tagPairsFor('華容道 (松果)/羽川ちゃんは語りたい (化物語) [DL版].zip'));
    $this->assertContains(['author', '松果'], tagPairsFor('華容道 (松果)/羽川ちゃんは語りたい (化物語) [DL版].zip'));
    $this->assertContains(['parody', '化物語'], tagPairsFor('華容道 (松果)/羽川ちゃんは語りたい (化物語) [DL版].zip'));
    $this->assertContains(['flag', 'DL版'], tagPairsFor('華容道 (松果)/羽川ちゃんは語りたい (化物語) [DL版].zip'));
});

test('nested file under a real mangaka: subfolder is ignored', function (): void {
    $meta = resolverUnderTest()->resolve('Kakao/Specials/純情ラブパンチ [DL版].zip');
    $this->assertSame('Kakao', $meta->mangakaName);
    $this->assertSame('純情ラブパンチ', $meta->parsed->title);
});

test('_series: mangaka from filename, subfolder becomes a parody tag', function (): void {
    $path = '_series/化物語/(同人誌) [ns2k (みまさかよろず)] 虜物語.zip';
    $meta = resolverUnderTest()->resolve($path);
    $this->assertSame('みまさかよろず', $meta->mangakaName); // author preferred
    $pairs = tagPairsFor($path);
    $this->assertContains(['circle', 'ns2k'], $pairs);
    $this->assertContains(['author', 'みまさかよろず'], $pairs);
    $this->assertContains(['parody', '化物語'], $pairs);
});

test('_series parody from folder de-dupes with the filename parody', function (): void {
    // filename already carries (化物語); folder is also 化物語 → only one parody value
    $path = '_series/化物語/(同人誌) [ns2k (みまさかよろず)] 虜物語 (化物語).zip';
    $parodies = array_values(array_filter(tagPairsFor($path), fn ($p) => $p[0] === 'parody'));
    $this->assertSame([['parody', '化物語']], array_values(array_unique($parodies, SORT_REGULAR)));
});

test('_series bracketless circle - title resolves an artist', function (): void {
    $meta = resolverUnderTest()->resolve('_series/-Saki-/from SCRATCH - のどかなペンギン.zip');
    $this->assertSame('from SCRATCH', $meta->mangakaName); // circle (no author) → circle
    $this->assertSame('のどかなペンギン', $meta->parsed->title);
    $this->assertContains(['parody', '-Saki-'], tagPairsFor('_series/-Saki-/from SCRATCH - のどかなペンギン.zip'));
});

test('_small: mangaka from the filename bracket block', function (): void {
    $meta = resolverUnderTest()->resolve('_small/(同人誌) [三崎 (inoino)] 姉を売った…少年Mの手記 (オリジナル).zip');
    $this->assertSame('inoino', $meta->mangakaName);
});

test('_small with no derivable artist falls back to Unknown', function (): void {
    $meta = resolverUnderTest()->resolve('_small/時間を止めて　セクハラ天国.zip');
    $this->assertSame('Unknown', $meta->mangakaName);
});

test('_雑誌 stays a literal mangaka (not a bucket)', function (): void {
    $meta = resolverUnderTest()->resolve('_雑誌/(成年コミック) [雑誌] COMIC saseco Vol. 3 [DL版].zip');
    $this->assertSame('_雑誌', $meta->mangakaName);
});

test('normal title containing a dash is NOT split into circle', function (): void {
    $meta = resolverUnderTest()->resolve('しでん晶/A - B というタイトル.zip');
    $this->assertSame('しでん晶', $meta->mangakaName);
    $this->assertSame('A - B というタイトル', $meta->parsed->title);
    $this->assertNull($meta->parsed->circle);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/PathMetadataResolverTest.php`
Expected: FAIL — `PathMetadataResolver` / `PathMetadata` not found.

- [ ] **Step 3: Implement the DTO** — `app/Parsing/PathMetadata.php`:

```php
<?php

namespace App\Parsing;

/**
 * The full metadata for one library path: which mangaka it belongs to and the (enriched)
 * filename parse. / 1パスのメタ情報（所属マンガ家＋強化済み解析）。
 */
final class PathMetadata
{
    public function __construct(
        public readonly string $mangakaName,
        public readonly ParsedName $parsed,
    ) {
    }
}
```

- [ ] **Step 4: Implement the resolver** — `app/Parsing/PathMetadataResolver.php`:

```php
<?php

namespace App\Parsing;

use App\Parsing\Patterns\CircleTitlePattern;

/**
 * Turns a library-relative path into {mangakaName, enriched ParsedName}. Pure: depends only on
 * the path string, so the scan path and the rescan re-derive path agree. / パス→メタ情報（純粋関数）。
 *
 * - Normal / other "_" folder: mangaka = top folder; add folder-derived circle/author.
 * - "_series"/"_small" bucket: mangaka = filename author→circle→"Unknown"; "_series" adds the
 *   middle subfolder as a parody tag.
 */
final class PathMetadataResolver
{
    /** Top folders that are organisational buckets, not artists. / バケット（作者ではない）。 */
    private const BUCKETS = ['_series', '_small'];

    private const UNKNOWN = 'Unknown';

    public function __construct(
        private readonly FilenameParser $parser,
        private readonly CircleTitlePattern $circleTitle,
    ) {
    }

    public function resolve(string $relativePath): PathMetadata
    {
        $segments = explode('/', $relativePath);
        $top = $segments[0];
        $basename = pathinfo($segments[array_key_last($segments)], PATHINFO_FILENAME);

        if (in_array($top, self::BUCKETS, true)) {
            return $this->resolveBucket($top, $segments, $basename);
        }

        // Normal or other "_" folder: the top folder is the mangaka. / トップフォルダ＝マンガ家。
        $parsed = $this->parser->parse($basename, $top)
            ->withExtraTags(MangakaFolder::tags($top));

        return new PathMetadata($top, $parsed);
    }

    private function resolveBucket(string $top, array $segments, string $basename): PathMetadata
    {
        // Bucket files carry the artist in the filename. Honour 'circle - title' here only. / 作者はファイル名側。
        $parsed = $this->circleTitle->matches($basename)
            ? $this->circleTitle->parse($basename, '')
            : $this->parser->parse($basename, '');

        $extra = [];
        if ($top === '_series' && count($segments) >= 3) {
            $extra[] = ['parody', $segments[1]]; // the franchise subfolder / フランチャイズ名
        }
        $parsed = $parsed->withExtraTags($extra);

        $mangaka = $parsed->author ?? $parsed->circle ?? self::UNKNOWN;

        return new PathMetadata($mangaka, $parsed);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Unit/Parsing/PathMetadataResolverTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Parsing/PathMetadata.php app/Parsing/PathMetadataResolver.php tests/Unit/Parsing/PathMetadataResolverTest.php
git commit -m "Add PathMetadataResolver mapping a path to mangaka + enriched tags"
```

---

### Task 7: `WorkTagSync` — emit `extraTags`, re-derive via the resolver

**Files:**
- Modify: `app/Tagging/WorkTagSync.php`
- Test: `tests/Feature/Tagging/WorkTagSyncExtraTagsTest.php` (create)

**Interfaces:**
- Consumes: `PathMetadataResolver` (Task 6), `ParsedName::$extraTags` (Task 1).
- Produces: `WorkTagSync::__construct(PathMetadataResolver $resolver)` (was `FilenameParser`); `derive()` now also emits `extraTags`; `sync()`'s null-`$parsed` branch re-derives from the work's stored `relative_path` via the resolver.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Tagging/WorkTagSyncExtraTagsTest.php`. (Note: `derive()` is a pure method; the durability assertion uses a persisted `Work` so the rescan branch resolves from `relative_path`.)

```php
<?php

use App\Models\Mangaka;
use App\Models\Work;
use App\Parsing\ParsedName;
use App\Tagging\WorkTagSync;

test('derive includes extraTags alongside scalar fields and flags', function (): void {
    $parsed = ParsedName::make(
        title: '虜物語',
        titleRaw: '虜物語',
        circle: 'ns2k',
        author: 'みまさかよろず',
    )->withExtraTags([['parody', '化物語']]);

    $pairs = app(WorkTagSync::class)->derive($parsed);

    expect($pairs)->toContain(['circle', 'ns2k'])
        ->toContain(['author', 'みまさかよろず'])
        ->toContain(['parody', '化物語']);
});

test('rescan re-derives identical tags for a _series work from its stored path', function (): void {
    $mangaka = Mangaka::create(['name' => 'みまさかよろず', 'slug' => 'mimasaka']);
    $work = Work::create([
        'mangaka_id' => $mangaka->id,
        'relative_path' => '_series/化物語/(同人誌) [ns2k (みまさかよろず)] 虜物語.zip',
        'filename' => '(同人誌) [ns2k (みまさかよろず)] 虜物語.zip',
        'title' => '虜物語',
        'title_raw' => '(同人誌) [ns2k (みまさかよろず)] 虜物語',
        'sort_title' => '虜物語',
        'content_hash' => 'hash-series-1',
        'page_count' => 1,
        'entries' => ['001.jpg'],
        'file_size' => 1,
        'file_mtime' => 1,
    ]);

    // sync() with no $parsed → the rescan branch, which must resolve from relative_path.
    app(WorkTagSync::class)->sync($work);

    $pairs = $work->tags()->get()->map(fn ($t) => [$t->type, $t->value])->all();
    expect($pairs)->toContain(['circle', 'ns2k'])
        ->toContain(['author', 'みまさかよろず'])
        ->toContain(['parody', '化物語']); // ← folder-derived, survives a rescan
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Tagging/WorkTagSyncExtraTagsTest.php`
Expected: FAIL — `derive()` omits `parody` from extraTags; the rescan branch still calls `FilenameParser` and loses the folder parody.

- [ ] **Step 3: Implement** — edit `app/Tagging/WorkTagSync.php`:

Change the import + constructor dependency from `FilenameParser` to `PathMetadataResolver`:

```php
use App\Parsing\PathMetadataResolver;
use App\Parsing\ParsedName;
// ...
    public function __construct(private readonly PathMetadataResolver $resolver)
    {
    }
```

In `sync()`, replace the null-coalescing re-parse line with a resolver call keyed on the stored path:

```php
        // Re-derive from the stored relative_path so folder/subfolder tags survive a rescan. / 保存パスから再導出。
        $parsed ??= $this->resolver->resolve($work->relative_path)->parsed;
```

In `derive()`, after the flags loop, append the extra tags:

```php
        foreach ($parsed->extraTags as [$type, $value]) {
            if ($value !== null && $value !== '') {
                $pairs[] = [$type, $value];
            }
        }

        return $pairs;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Tagging/WorkTagSyncExtraTagsTest.php`
Expected: PASS.

- [ ] **Step 5: Run the existing tagging suite to confirm no regressions**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Tagging tests/Unit/Tagging`
Expected: PASS. (If any existing test constructs `WorkTagSync` with a `FilenameParser`, switch it to resolve via `app(WorkTagSync::class)` or pass a `PathMetadataResolver`.)

- [ ] **Step 6: Commit**

```bash
git add app/Tagging/WorkTagSync.php tests/Feature/Tagging/WorkTagSyncExtraTagsTest.php
git commit -m "Emit extraTags and re-derive tags from the stored path on rescan"
```

---

### Task 8: `LibraryScanner` — recursive discovery + resolver-based mangaka

**Files:**
- Modify: `app/Scanning/LibraryScanner.php`
- Modify: `app/Providers/AppServiceProvider.php:53-56`
- Test: `tests/Feature/Scanning/LibraryScannerNestedTest.php` (create)

**Interfaces:**
- Consumes: `PathMetadataResolver` (Task 6).
- Produces: `LibraryScanner::__construct(WorkTagSync $tags, PathMetadataResolver $resolver, string $libraryPath)`; `planJobs()` walks the tree recursively and resolves each mangaka name via the resolver, memoised so one name maps to one `Mangaka` row (no race).

- [ ] **Step 1: Write the failing test** — `tests/Feature/Scanning/LibraryScannerNestedTest.php`:

```php
<?php

use App\Models\Mangaka;
use App\Models\Work;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('discovers nested zips under a real mangaka folder', function (): void {
    $this->makeDoujin('Kakao/Specials', '純情ラブパンチ [DL版]'); // depth-3 path

    $this->runScan();

    $work = Work::firstOrFail();
    $this->assertSame('Kakao/Specials/純情ラブパンチ [DL版].zip', $work->relative_path);
    $this->assertSame('Kakao', $work->mangaka->name); // subfolder ignored
    $this->assertSame('純情ラブパンチ', $work->title);
});

test('_series zip: mangaka from filename, franchise from subfolder', function (): void {
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語');

    $this->runScan();

    $work = Work::firstOrFail();
    $this->assertSame('みまさかよろず', $work->mangaka->name);
    $pairs = $work->tags()->get()->map(fn ($t) => [$t->type, $t->value])->all();
    $this->assertContains(['parody', '化物語'], $pairs);
});

test('one mangaka row per derived name even across many bucket files (no race)', function (): void {
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語');
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語2');

    $this->runScan();

    $this->assertSame(1, Mangaka::where('name', 'みまさかよろず')->count());
    $this->assertSame(2, Work::count());
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning/LibraryScannerNestedTest.php`
Expected: FAIL — nested files not discovered (old `glob` is one level), and `_series` works are filed under a `_series` mangaka.

- [ ] **Step 3: Implement** — edit `app/Scanning/LibraryScanner.php`.

Add the import and the constructor dependency:

```php
use App\Parsing\PathMetadataResolver;
// ...
    public function __construct(
        private readonly WorkTagSync $tags,
        private readonly PathMetadataResolver $resolver,
        private readonly string $libraryPath,
    ) {
    }
```

Replace `planJobs()` body to walk recursively and resolve mangaka by derived name (memoised):

```php
    public function planJobs(int $scanId, string $scanStartIso): array
    {
        $jobs = [];
        $mangakaByName = []; // memo: derived name → Mangaka (sequential, so no create race) / 競合回避メモ
        foreach ($this->zipFiles() as $zipPath) {
            $relativePath = substr($zipPath, strlen($this->libraryPath) + 1);
            $name = $this->resolver->resolve($relativePath)->mangakaName;
            $mangaka = $mangakaByName[$name] ??= $this->resolveMangaka($name);
            $jobs[] = new ProcessZip(
                $scanId,
                $mangaka->id,
                $mangaka->name,
                $zipPath,
                $relativePath,
                $scanStartIso,
            );
        }

        return $jobs;
    }
```

Replace `mangakaFolders()` with a recursive `zipFiles()`:

```php
    /** @return list<string> absolute paths of every .zip under the library, sorted. / 全zipの絶対パス。 */
    private function zipFiles(): array
    {
        if (! is_dir($this->libraryPath)) {
            return [];
        }
        $found = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->libraryPath, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'zip') {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }
```

Keep `resolveMangaka()` and `uniqueSlug()` unchanged. Remove the now-unused `mangakaFolders()` method.

- [ ] **Step 4: Update the DI binding** — edit `app/Providers/AppServiceProvider.php` (the `LibraryScanner::class` bind, around line 53). Add the resolver argument:

```php
        $this->app->bind(LibraryScanner::class, fn ($app) => new LibraryScanner(
            $app->make(WorkTagSync::class),
            $app->make(\App\Parsing\PathMetadataResolver::class),
            config('scan.library_path'),
        ));
```

- [ ] **Step 5: Run the new + existing scanner tests**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning`
Expected: PASS (new nested tests plus the existing matching/missing/edge suites).

- [ ] **Step 6: Commit**

```bash
git add app/Scanning/LibraryScanner.php app/Providers/AppServiceProvider.php tests/Feature/Scanning/LibraryScannerNestedTest.php
git commit -m "Discover zips recursively and resolve mangaka via PathMetadataResolver"
```

---

### Task 9: `ProcessZip` — parse via the resolver

**Files:**
- Modify: `app/Jobs/ProcessZip.php:47,69,87`
- Test: `tests/Feature/Scanning/ScanEnrichedTagsTest.php` (create)

**Interfaces:**
- Consumes: `PathMetadataResolver` (Task 6).
- Produces: `ProcessZip::handle(ArchiveInspector $inspector, PathMetadataResolver $resolver, WorkTagSync $tags)`; `process()` derives `$parsed` from `$resolver->resolve($this->relativePath)->parsed` (enriched), used for both the work attributes and `$tags->sync($work, $parsed)`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Scanning/ScanEnrichedTagsTest.php`:

```php
<?php

use App\Models\Work;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('title-first file in a normal folder gets folder author + filename parody/flags', function (): void {
    $this->makeDoujin('華容道 (松果)', '羽川ちゃんは語りたい (化物語) [DL版]');

    $this->runScan();

    $work = Work::firstOrFail();
    $this->assertSame('羽川ちゃんは語りたい', $work->title);
    $pairs = $work->tags()->get()->map(fn ($t) => [$t->type, $t->value])->all();
    $this->assertContains(['circle', '華容道'], $pairs);  // from the folder
    $this->assertContains(['author', '松果'], $pairs);    // from the folder
    $this->assertContains(['parody', '化物語'], $pairs);  // from the filename
    $this->assertContains(['flag', 'DL版'], $pairs);      // from the filename
});

test('a full scan of a _series work carries folder parody + filename artist', function (): void {
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語');

    $this->runScan();

    $work = Work::firstOrFail();
    $this->assertSame('みまさかよろず', $work->mangaka->name);
    $pairs = $work->tags()->get()->map(fn ($t) => [$t->type, $t->value])->all();
    $this->assertContains(['circle', 'ns2k'], $pairs);
    $this->assertContains(['parody', '化物語'], $pairs);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning/ScanEnrichedTagsTest.php`
Expected: FAIL — folder/`_series` tags are missing because `ProcessZip` still parses the bare filename.

- [ ] **Step 3: Implement** — edit `app/Jobs/ProcessZip.php`:

Change the import + `handle()` signature from `FilenameParser` to `PathMetadataResolver`:

```php
use App\Parsing\PathMetadataResolver;
// ...
    public function handle(ArchiveInspector $inspector, PathMetadataResolver $resolver, WorkTagSync $tags): void
    {
        // ... unchanged body, but pass $resolver into process()
        $outcome = $this->process($inspector, $resolver, $tags, $scanStart);
```

Change the `process()` signature and the parse line:

```php
    private function process(ArchiveInspector $inspector, PathMetadataResolver $resolver, WorkTagSync $tags, Carbon $scanStart): string
    {
        // ... unchanged up to the inspection ...
        $inspection = $inspector->inspect($this->zipPath);
        // Enriched parse keyed on the relative path (folder author, _series parody). / パス基準の強化解析。
        $parsed = $resolver->resolve($this->relativePath)->parsed;
```

Everything downstream (`$parsed->title`, `$parsed->sortTitle`, `$tags->sync($work, $parsed)`) is unchanged. Update the `applyToExisting()` call sites — they already receive `$parsed`, so no signature change there.

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning/ScanEnrichedTagsTest.php`
Expected: PASS.

- [ ] **Step 5: Run the broader scan + job suites**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning tests/Feature/Reader/RescanWorkEndpointTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ProcessZip.php tests/Feature/Scanning/ScanEnrichedTagsTest.php
git commit -m "Parse each zip via PathMetadataResolver in ProcessZip"
```

---

### Task 10: End-to-end integration, docs, full suite + coverage

**Files:**
- Test: `tests/Feature/Scanning/MixedLibraryScanTest.php` (create)
- Modify: `CLAUDE.md` (scanning/parser sections)

**Interfaces:**
- Consumes: the whole pipeline (Tasks 1–9).

- [ ] **Step 1: Write the integration test** — `tests/Feature/Scanning/MixedLibraryScanTest.php`. One scan over a miniature real-world library covering every layout, plus a rescan-durability assertion:

```php
<?php

use App\Models\Mangaka;
use App\Models\Work;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('a mixed library scans every layout correctly', function (): void {
    $this->makeDoujin('華容道 (松果)', '羽川ちゃんは語りたい (化物語) [DL版]');   // normal, title-first
    $this->makeDoujin('Kakao/Specials', '純情ラブパンチ [DL版]');                 // nested under real mangaka
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語'); // _series bucket
    $this->makeDoujin('_small', '(同人誌) [三崎 (inoino)] 姉を売った…少年Mの手記 (オリジナル)'); // _small bucket
    $this->makeDoujin('_雑誌', '(成年コミック) [雑誌] COMIC saseco Vol. 3 [DL版]'); // literal mangaka

    $scan = $this->runScan();

    $this->assertSame(5, $scan->stats['added']);
    $this->assertSame(5, Work::count());

    // Mangaka assignment per layout
    $this->assertSame('華容道 (松果)', Work::whereRelativePathLike('華容道%')->first()->mangaka->name);
    $this->assertSame('Kakao', Work::whereRelativePathLike('Kakao/%')->first()->mangaka->name);
    $this->assertSame('みまさかよろず', Work::whereRelativePathLike('_series/%')->first()->mangaka->name);
    $this->assertSame('inoino', Work::whereRelativePathLike('_small/%')->first()->mangaka->name);
    $this->assertSame('_雑誌', Work::whereRelativePathLike('_雑誌/%')->first()->mangaka->name);
});

test('folder + _series tags survive a rescan unchanged', function (): void {
    $this->makeDoujin('華容道 (松果)', '羽川ちゃんは語りたい (化物語) [DL版]');
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語');
    $this->runScan();

    $before = Work::with('tags')->get()
        ->mapWithKeys(fn ($w) => [$w->relative_path => $w->tags->map(fn ($t) => "{$t->type}:{$t->value}")->sort()->values()->all()]);

    // A no-op rescan must not change any work's tag set.
    $this->runScan();

    $after = Work::with('tags')->get()
        ->mapWithKeys(fn ($w) => [$w->relative_path => $w->tags->map(fn ($t) => "{$t->type}:{$t->value}")->sort()->values()->all()]);

    $this->assertEquals($before->all(), $after->all());
});
```

Add the `whereRelativePathLike` helper to the test only if `Work` lacks a convenient query; otherwise replace those lines with `Work::where('relative_path', 'like', '華容道%')`. **Prefer inlining** `Work::where('relative_path', 'like', '...%')->first()` to avoid touching the model:

```php
    $normal = Work::where('relative_path', 'like', '華容道%')->first();
    $this->assertSame('華容道 (松果)', $normal->mangaka->name);
    // ...same shape for the other four
```

- [ ] **Step 2: Run the integration test**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest tests/Feature/Scanning/MixedLibraryScanTest.php`
Expected: PASS. (If a rescan flips a work to `updated` because mtime changed, ensure the fixtures aren't rewritten between scans — `runScan` does not touch files, so the fast-skip keeps tags intact.)

- [ ] **Step 3: Update `CLAUDE.md`** — in the parser/scanner description, document the new behaviour. Add to the parser section (the ordered registry bullet) that the registry now also has `TrailingMetadataPattern` (title-first), and `CircleTitlePattern` is resolver-gated to buckets; and add to the scanning section that discovery is recursive, that `_series`/`_small` are buckets (mangaka from the filename, `_series` subfolder → parody tag), that other top folders (incl. `_雑誌`) stay literal mangaka, that nested files under a real mangaka are flattened, and that `PathMetadataResolver` makes all derived tags a pure function of `relative_path` + filename so they survive rescans. Keep edits to a few precise sentences in the existing sections — do not restructure the doc.

- [ ] **Step 4: Run the full suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" vendor/bin/pest`
Expected: PASS (entire suite green).

- [ ] **Step 5: Confirm 100% line coverage of `app/`**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php -d pcov.enabled=1 vendor/bin/pest --coverage --min=100`
Expected: PASS at 100%. If any new line is uncovered (e.g. the resolver's `Unknown` branch or the `zipFiles()` `is_dir` guard), add a focused unit test for it rather than lowering the threshold.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Scanning/MixedLibraryScanTest.php CLAUDE.md
git commit -m "Add mixed-library integration test and document scanner refinements"
```

---

## Self-Review

**Spec coverage:**
- Finding #1 (nested files) → Task 8 (recursive `zipFiles()`) + Task 6 (flatten subfolder under real mangaka).
- Finding #2 (title-first parody/flags) → Task 3 (`TrailingMetadataPattern`) + Task 9 (ProcessZip uses it).
- Finding #3 (`_` buckets) → Task 6 (bucket rule, `_series` parody, `Unknown` fallback) + Task 8/9 (wired into scan).
- Finding #4 (folder→author) → Task 5 (`MangakaFolder`) + Task 6 (attached as extraTags) + Task 7 (emitted as tags).
- Durability invariant (§4.5) → Task 7 (resolver-based re-derive) + Task 10 (rescan-stability test).
- No-race invariant (§4.2) → Task 8 (sequential memoised resolution in `planJobs`).
- 100% coverage → Task 10 Step 5.

**Placeholder scan:** none — every code step shows complete code; commands have expected output.

**Type consistency:** `PathMetadataResolver::resolve()` returns `PathMetadata{mangakaName, parsed}` (Task 6) consumed identically in Tasks 7/8/9. `ParsedName::withExtraTags`/`$extraTags` (Task 1) used in Tasks 6/7. `MangakaFolder::tags()` shape `list<array{0,1}>` (Task 5) matches `withExtraTags`'s parameter (Task 1) and `derive()`'s consumption (Task 7). `WorkTagSync` constructor changes from `FilenameParser` to `PathMetadataResolver` (Task 7) and is re-supplied wherever built (autowired; `LibraryScanner` binding updated in Task 8 Step 4). `ProcessZip::handle` swaps `FilenameParser`→`PathMetadataResolver` (Task 9) — method-injected, so the container supplies it.
