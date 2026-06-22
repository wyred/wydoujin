# wydoujin — Filename Parser Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the pure, unit-testable filename parser (spec §6) that turns a doujin filename into a normalized `ParsedName` value object — the component the scanner (a later plan) will depend on.

**Architecture:** An ordered registry of `NamePattern` strategy classes (`config/parser.php`), each with `matches()`/`parse()`. A `FilenameParser` service tries them in order and returns the first match. v1 ships two patterns: a flexible `StandardDoujinPattern` (a right-to-left bracket peeler whose components are all optional, so it absorbs the no-event / no-parody / multi-flag / circle-without-author variants) and an always-matching `FallbackPattern`. Pure PHP — no DB, no Eloquent; resolvable from the Laravel container.

**Tech Stack:** PHP 8.3+ (readonly promoted constructor properties), Laravel 13 (config + container binding only), PHPUnit (unit + one feature test).

## Global Constraints

- **Framework/PHP:** Laravel 13, PHP 8.3+. Composer platform is pinned to `8.3.0`; local dev runs 8.5.
- **Broken local toolchain:** `php`/`composer` on PATH point at a broken php@7.4. Prefix EVERY php/composer command with `PATH="/opt/homebrew/opt/php/bin:$PATH"` (working PHP 8.5.4 / Composer 2.9.5). Shell env does not persist between commands — repeat the prefix.
- **Commit trailer:** every commit message ends with a blank line then exactly:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **PHP style:** single quotes unless interpolation is needed; native typed properties (promoted `readonly` constructor properties) over `@var`; keep `@param`/`@var` only for array element types (e.g. `string[]`) that PHP can't express natively; comments in BOTH English and Japanese in the same docblock, short.
- **Purity:** the parser touches NO database and NO Eloquent. `ParsedName` carries no `mangaka` (mangaka comes from the folder, stored separately by the scanner). `titleRaw` is the input filename unchanged.
- **v1 scope decisions (locked in brainstorming):** `language` is always `null` (§6 lists the field but gives no extraction rule); only ASCII brackets `()`/`[]` are handled (full-width `（）`/`［］` normalization is a future pattern); the parser EXTRACTS a parody but never groups by it (grouping is series detection, a later plan); `parse()` takes `$mangaka` per §6 even though v1 patterns don't use it.
- **Workflow:** TDD (failing test first), DRY, YAGNI, bite-sized commits.

## File Structure

Created in this plan:

- `app/Parsing/ParsedName.php` — immutable result value object + `sort_title` derivation. One responsibility: hold a parsed result and normalize its sort key.
- `app/Parsing/NamePattern.php` — the strategy interface (`matches()`/`parse()`).
- `app/Parsing/Patterns/FallbackPattern.php` — always-matching catch-all (whole filename → title).
- `app/Parsing/Patterns/StandardDoujinPattern.php` — the bracket peeler (the real parsing logic).
- `app/Parsing/FilenameParser.php` — the service: first-matching-pattern-wins over an injected pattern list.
- `config/parser.php` — the ordered pattern registry.
- `app/Providers/AppServiceProvider.php` — **modify**: bind `FilenameParser` to resolve patterns from the config registry.
- `tests/Unit/Parsing/ParsedNameTest.php`, `tests/Unit/Parsing/Patterns/FallbackPatternTest.php`, `tests/Unit/Parsing/Patterns/StandardDoujinPatternTest.php`, `tests/Unit/Parsing/FilenameParserTest.php`, `tests/Feature/Parsing/FilenameParserResolutionTest.php`.

---

## Task 1: `ParsedName` value object + sort_title derivation

**Files:**
- Create: `app/Parsing/ParsedName.php`
- Test: `tests/Unit/Parsing/ParsedNameTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Parsing\ParsedName` — a `final` class with readonly props `string $title, $titleRaw, $sortTitle; ?string $event, $circle, $author, $parody, $language; array $flags`. A static factory `ParsedName::make(string $title, string $titleRaw, ?string $event = null, ?string $circle = null, ?string $author = null, ?string $parody = null, ?string $language = null, array $flags = []): self` that derives `sortTitle`. A static `ParsedName::deriveSortTitle(string $title): string`. All later tasks construct results via `ParsedName::make(...)`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Parsing/ParsedNameTest.php`:
```php
<?php

namespace Tests\Unit\Parsing;

use App\Parsing\ParsedName;
use PHPUnit\Framework\TestCase;

class ParsedNameTest extends TestCase
{
    public function test_make_derives_sort_title_and_holds_fields(): void
    {
        $r = ParsedName::make(
            title: '四畳半物語',
            titleRaw: '(C89) [Z.A.P.] 四畳半物語',
            event: 'C89',
            circle: 'Z.A.P.',
            flags: ['DL版'],
        );

        $this->assertSame('四畳半物語', $r->title);
        $this->assertSame('(C89) [Z.A.P.] 四畳半物語', $r->titleRaw);
        $this->assertSame('四畳半物語', $r->sortTitle);
        $this->assertSame('C89', $r->event);
        $this->assertSame('Z.A.P.', $r->circle);
        $this->assertNull($r->author);
        $this->assertNull($r->parody);
        $this->assertNull($r->language);
        $this->assertSame(['DL版'], $r->flags);
    }

    public function test_derive_sort_title_strips_leading_symbols_and_brackets(): void
    {
        $this->assertSame('Title', ParsedName::deriveSortTitle('★Title'));
        $this->assertSame('Title', ParsedName::deriveSortTitle('  「Title'));
        $this->assertSame('四畳半物語', ParsedName::deriveSortTitle('四畳半物語'));
        $this->assertSame('abc', ParsedName::deriveSortTitle('...abc'));
    }

    public function test_derive_sort_title_falls_back_to_trimmed_title_when_all_stripped(): void
    {
        $this->assertSame('!!!', ParsedName::deriveSortTitle('  !!!  '));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ParsedNameTest`
Expected: FAIL (class `App\Parsing\ParsedName` not found).

- [ ] **Step 3: Write the implementation**

`app/Parsing/ParsedName.php`:
```php
<?php

namespace App\Parsing;

/**
 * Parsed result of a doujin filename. / 同人ファイル名の解析結果。
 * Immutable. No mangaka here — it comes from the folder. / mangakaはフォルダ由来のため含めない。
 */
final class ParsedName
{
    /** @param string[] $flags */
    public function __construct(
        public readonly string $title,
        public readonly string $titleRaw,
        public readonly string $sortTitle,
        public readonly ?string $event = null,
        public readonly ?string $circle = null,
        public readonly ?string $author = null,
        public readonly ?string $parody = null,
        public readonly ?string $language = null,
        public readonly array $flags = [],
    ) {
    }

    /**
     * Build a result, deriving sortTitle from the title. / タイトルからsortTitleを導出して生成。
     *
     * @param string[] $flags
     */
    public static function make(
        string $title,
        string $titleRaw,
        ?string $event = null,
        ?string $circle = null,
        ?string $author = null,
        ?string $parody = null,
        ?string $language = null,
        array $flags = [],
    ): self {
        return new self(
            title: $title,
            titleRaw: $titleRaw,
            sortTitle: self::deriveSortTitle($title),
            event: $event,
            circle: $circle,
            author: $author,
            parody: $parody,
            language: $language,
            flags: $flags,
        );
    }

    /**
     * Strip leading non-letter/non-digit chars (symbols, brackets, spaces) for ordering.
     * 並び替え用に先頭の記号・括弧・空白（英数字・CJK以外）を除去。
     */
    public static function deriveSortTitle(string $title): string
    {
        $stripped = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $title) ?? '');

        return $stripped !== '' ? $stripped : trim($title);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=ParsedNameTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Parsing/ParsedName.php tests/Unit/Parsing/ParsedNameTest.php
git commit -m "$(cat <<'EOF'
feat: add ParsedName value object with sort_title derivation

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `NamePattern` interface + `FallbackPattern`

**Files:**
- Create: `app/Parsing/NamePattern.php`
- Create: `app/Parsing/Patterns/FallbackPattern.php`
- Test: `tests/Unit/Parsing/Patterns/FallbackPatternTest.php`

**Interfaces:**
- Consumes: `App\Parsing\ParsedName` (Task 1).
- Produces:
  - `App\Parsing\NamePattern` — interface: `matches(string $filename): bool` and `parse(string $filename, string $mangaka): ParsedName`.
  - `App\Parsing\Patterns\FallbackPattern implements NamePattern` — `matches()` always `true`; `parse()` returns the whole (trimmed) filename as `title`, all other fields null/`[]`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Parsing/Patterns/FallbackPatternTest.php`:
```php
<?php

namespace Tests\Unit\Parsing\Patterns;

use App\Parsing\Patterns\FallbackPattern;
use PHPUnit\Framework\TestCase;

class FallbackPatternTest extends TestCase
{
    public function test_matches_everything(): void
    {
        $p = new FallbackPattern();
        $this->assertTrue($p->matches('anything at all'));
        $this->assertTrue($p->matches(''));
    }

    public function test_parse_uses_whole_filename_as_title(): void
    {
        $p = new FallbackPattern();

        $r = $p->parse('相姦マニュアル', 'SomeMangaka');
        $this->assertSame('相姦マニュアル', $r->title);
        $this->assertSame('相姦マニュアル', $r->titleRaw);
        $this->assertSame('相姦マニュアル', $r->sortTitle);
        $this->assertNull($r->event);
        $this->assertNull($r->circle);
        $this->assertNull($r->author);
        $this->assertNull($r->parody);
        $this->assertNull($r->language);
        $this->assertSame([], $r->flags);

        $r2 = $p->parse('Two Lovers EN', 'SomeMangaka');
        $this->assertSame('Two Lovers EN', $r2->title);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=FallbackPatternTest`
Expected: FAIL (classes not found).

- [ ] **Step 3: Write the interface**

`app/Parsing/NamePattern.php`:
```php
<?php

namespace App\Parsing;

/**
 * One filename-parsing strategy. / ファイル名解析の1ストラテジ。
 * Patterns are tried in registry order; the first whose matches() is true wins.
 * パターンは登録順に試行し、matches()が最初にtrueのものを採用。
 */
interface NamePattern
{
    /** Does this pattern apply to the filename? / このパターンが適用可能か。 */
    public function matches(string $filename): bool;

    /** Parse it. $mangaka is the folder name (reserved for future patterns). / 解析する。$mangakaはフォルダ名。 */
    public function parse(string $filename, string $mangaka): ParsedName;
}
```

- [ ] **Step 4: Write the FallbackPattern**

`app/Parsing/Patterns/FallbackPattern.php`:
```php
<?php

namespace App\Parsing\Patterns;

use App\Parsing\NamePattern;
use App\Parsing\ParsedName;

/**
 * Always-matching last resort: the whole filename becomes the title. / 最終手段：全体をタイトルに。
 */
final class FallbackPattern implements NamePattern
{
    public function matches(string $filename): bool
    {
        return true;
    }

    public function parse(string $filename, string $mangaka): ParsedName
    {
        return ParsedName::make(title: trim($filename), titleRaw: $filename);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=FallbackPatternTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Parsing/NamePattern.php app/Parsing/Patterns/FallbackPattern.php tests/Unit/Parsing/Patterns/FallbackPatternTest.php
git commit -m "$(cat <<'EOF'
feat: add NamePattern interface and FallbackPattern

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `StandardDoujinPattern` (the bracket peeler)

**Files:**
- Create: `app/Parsing/Patterns/StandardDoujinPattern.php`
- Test: `tests/Unit/Parsing/Patterns/StandardDoujinPatternTest.php`

**Interfaces:**
- Consumes: `App\Parsing\NamePattern`, `App\Parsing\ParsedName`.
- Produces: `App\Parsing\Patterns\StandardDoujinPattern implements NamePattern`. `matches()` is true only when the filename (after leading whitespace) begins with `(` or `[`. `parse()` peels, in order: leading `(event)`, leading `[circle (author)]`, trailing `[flags]` (repeatable, kept in left-to-right order), trailing `(parody)`; the remainder is the title.

**Note on bracket handling:** brackets are ASCII (`(` `)` `[` `]`), which are single bytes that never occur inside a multibyte UTF-8 sequence, so byte-based `strpos`/`substr`/index access is UTF-8-safe here. Matching is non-nested (first/last bracket of a kind) — sufficient for the convention; nested-bracket edge cases are out of v1 scope.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Parsing/Patterns/StandardDoujinPatternTest.php`:
```php
<?php

namespace Tests\Unit\Parsing\Patterns;

use App\Parsing\Patterns\StandardDoujinPattern;
use PHPUnit\Framework\TestCase;

class StandardDoujinPatternTest extends TestCase
{
    public function test_matches_only_when_leading_bracket_present(): void
    {
        $p = new StandardDoujinPattern();
        $this->assertTrue($p->matches('(C89) [Z.A.P.] 四畳半物語'));
        $this->assertTrue($p->matches('[サークル] タイトル'));
        $this->assertFalse($p->matches('相姦マニュアル'));
        $this->assertFalse($p->matches('Two Lovers EN'));
    }

    public function test_full_standard_filename(): void
    {
        $r = (new StandardDoujinPattern())
            ->parse('(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]', 'Z.A.P.');

        $this->assertSame('C89', $r->event);
        $this->assertSame('Z.A.P.', $r->circle);
        $this->assertSame('ズッキーニ', $r->author);
        $this->assertSame('四畳半物語', $r->title);
        $this->assertSame('オリジナル', $r->parody);
        $this->assertSame(['DL版'], $r->flags);
        $this->assertNull($r->language);
    }

    public function test_no_event(): void
    {
        $r = (new StandardDoujinPattern())->parse('[Z.A.P. (ズッキーニ)] 四畳半物語 二畳目', 'Z.A.P.');

        $this->assertNull($r->event);
        $this->assertSame('Z.A.P.', $r->circle);
        $this->assertSame('ズッキーニ', $r->author);
        $this->assertSame('四畳半物語 二畳目', $r->title);
        $this->assertNull($r->parody);
        $this->assertSame([], $r->flags);
    }

    public function test_circle_without_author(): void
    {
        $r = (new StandardDoujinPattern())->parse('[サークル] タイトル', 'サークル');

        $this->assertSame('サークル', $r->circle);
        $this->assertNull($r->author);
        $this->assertSame('タイトル', $r->title);
    }

    public function test_multiple_flags_and_parody(): void
    {
        $r = (new StandardDoujinPattern())
            ->parse('(C99) [Circle (Author)] Some Title (Fate/Grand Order) [English] [DL版]', 'Circle');

        $this->assertSame('C99', $r->event);
        $this->assertSame('Circle', $r->circle);
        $this->assertSame('Author', $r->author);
        $this->assertSame('Some Title', $r->title);
        $this->assertSame('Fate/Grand Order', $r->parody);
        $this->assertSame(['English', 'DL版'], $r->flags);
    }

    public function test_flags_without_parody(): void
    {
        $r = (new StandardDoujinPattern())->parse('[Circle (Author)] Title Here [DL版]', 'Circle');

        $this->assertSame('Circle', $r->circle);
        $this->assertSame('Author', $r->author);
        $this->assertSame('Title Here', $r->title);
        $this->assertNull($r->parody);
        $this->assertSame(['DL版'], $r->flags);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=StandardDoujinPatternTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write the implementation**

`app/Parsing/Patterns/StandardDoujinPattern.php`:
```php
<?php

namespace App\Parsing\Patterns;

use App\Parsing\NamePattern;
use App\Parsing\ParsedName;

/**
 * Standard doujin convention: (EVENT) [CIRCLE (AUTHOR)] TITLE (PARODY) [FLAGS...].
 * 標準同人形式。先頭/末尾の括弧群を剥がしてタイトルを得る。
 * Every bracket group is optional, so this also covers no-event / no-parody /
 * multi-flag / circle-without-author variants. / 各括弧は任意なので各種バリアントも処理。
 */
final class StandardDoujinPattern implements NamePattern
{
    public function matches(string $filename): bool
    {
        // Only when a leading (event) or [circle] group is present. / 先頭に (…) か […] がある場合のみ。
        return (bool) preg_match('/^\s*[\(\[]/u', $filename);
    }

    public function parse(string $filename, string $mangaka): ParsedName
    {
        $rest = trim($filename);

        $event = $this->peelLeadingGroup($rest, '(', ')');
        $circleBlock = $this->peelLeadingGroup($rest, '[', ']');
        [$circle, $author] = $this->splitCircleAuthor($circleBlock);

        $flags = $this->peelTrailingFlags($rest);
        $parody = $this->peelTrailingGroup($rest, '(', ')');

        $title = trim($rest);

        return ParsedName::make(
            title: $title !== '' ? $title : trim($filename),
            titleRaw: $filename,
            event: $event,
            circle: $circle,
            author: $author,
            parody: $parody,
            flags: $flags,
        );
    }

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
     * Peel all trailing [flags], returned left-to-right as they appear.
     * 末尾の[フラグ]を全て剥がし、出現順（左→右）で返す。
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
     * Split a "[...]" inner block into circle + optional trailing (author).
     * 「[...]」の中身をサークルと末尾の(作者)に分割。
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

- [ ] **Step 4: Run test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=StandardDoujinPatternTest`
Expected: PASS (all 6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Parsing/Patterns/StandardDoujinPattern.php tests/Unit/Parsing/Patterns/StandardDoujinPatternTest.php
git commit -m "$(cat <<'EOF'
feat: add StandardDoujinPattern bracket peeler

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `FilenameParser` service + config registry + container binding

**Files:**
- Create: `app/Parsing/FilenameParser.php`
- Create: `config/parser.php`
- Modify: `app/Providers/AppServiceProvider.php` (add a binding in `register()`)
- Test: `tests/Unit/Parsing/FilenameParserTest.php`
- Test: `tests/Feature/Parsing/FilenameParserResolutionTest.php`

**Interfaces:**
- Consumes: `App\Parsing\NamePattern`, `App\Parsing\Patterns\StandardDoujinPattern`, `App\Parsing\Patterns\FallbackPattern`, `App\Parsing\ParsedName`.
- Produces: `App\Parsing\FilenameParser` with `__construct(array $patterns)` (array of `NamePattern`, ordered) and `parse(string $filename, string $mangaka): ParsedName` returning the first matching pattern's result. `config('parser.patterns')` returns an ordered array of pattern class names. `app(FilenameParser::class)` resolves a parser built from that registry. The scanner (later plan) injects `FilenameParser`.

- [ ] **Step 1: Write the failing unit test**

`tests/Unit/Parsing/FilenameParserTest.php`:
```php
<?php

namespace Tests\Unit\Parsing;

use App\Parsing\FilenameParser;
use App\Parsing\Patterns\FallbackPattern;
use App\Parsing\Patterns\StandardDoujinPattern;
use PHPUnit\Framework\TestCase;

class FilenameParserTest extends TestCase
{
    private function parser(): FilenameParser
    {
        return new FilenameParser([new StandardDoujinPattern(), new FallbackPattern()]);
    }

    public function test_routes_standard_filename_to_standard_pattern(): void
    {
        $r = $this->parser()->parse('(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]', 'Z.A.P.');
        $this->assertSame('四畳半物語', $r->title);
        $this->assertSame('C89', $r->event);
        $this->assertSame(['DL版'], $r->flags);
    }

    public function test_routes_bracketless_filename_to_fallback(): void
    {
        $r = $this->parser()->parse('相姦マニュアル', 'SomeMangaka');
        $this->assertSame('相姦マニュアル', $r->title);
        $this->assertNull($r->event);
        $this->assertNull($r->circle);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=FilenameParserTest`
Expected: FAIL (class `App\Parsing\FilenameParser` not found).

- [ ] **Step 3: Write the FilenameParser**

`app/Parsing/FilenameParser.php`:
```php
<?php

namespace App\Parsing;

use App\Parsing\Patterns\FallbackPattern;

/**
 * Tries registered patterns in order; first match wins. / 登録パターンを順に試し最初の一致を採用。
 */
final class FilenameParser
{
    /** @param NamePattern[] $patterns ordered; the last should always match */
    public function __construct(private readonly array $patterns)
    {
    }

    public function parse(string $filename, string $mangaka): ParsedName
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($filename)) {
                return $pattern->parse($filename, $mangaka);
            }
        }

        // Defensive: registry should end with a catch-all. / 念のため：末尾は全一致であること。
        return (new FallbackPattern())->parse($filename, $mangaka);
    }
}
```

- [ ] **Step 4: Run unit test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=FilenameParserTest`
Expected: PASS.

- [ ] **Step 5: Create the config registry**

`config/parser.php`:
```php
<?php

use App\Parsing\Patterns\FallbackPattern;
use App\Parsing\Patterns\StandardDoujinPattern;

return [
    /*
     | Ordered filename-parsing patterns. First match wins; the LAST must be a
     | catch-all. Add a class here to support a new naming quirk — no rewrites.
     | ファイル名解析パターン（順序付き）。最初の一致を採用。末尾は必ず全一致。
     */
    'patterns' => [
        StandardDoujinPattern::class,
        FallbackPattern::class,
    ],
];
```

- [ ] **Step 6: Bind FilenameParser in AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, add these imports at the top (after the namespace):
```php
use App\Parsing\FilenameParser;
use App\Parsing\NamePattern;
```
Inside the `register()` method body, add:
```php
$this->app->singleton(FilenameParser::class, function ($app) {
    $patterns = array_map(
        fn (string $class): NamePattern => $app->make($class),
        config('parser.patterns', []),
    );

    return new FilenameParser($patterns);
});
```

- [ ] **Step 7: Write the failing feature test**

`tests/Feature/Parsing/FilenameParserResolutionTest.php`:
```php
<?php

namespace Tests\Feature\Parsing;

use App\Parsing\FilenameParser;
use Tests\TestCase;

class FilenameParserResolutionTest extends TestCase
{
    public function test_parser_resolves_from_config_registry_and_routes_correctly(): void
    {
        $parser = app(FilenameParser::class);
        $this->assertInstanceOf(FilenameParser::class, $parser);

        $standard = $parser->parse('(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]', 'Z.A.P.');
        $this->assertSame('四畳半物語', $standard->title);
        $this->assertSame('オリジナル', $standard->parody);

        $fallback = $parser->parse('相姦マニュアル', 'Z.A.P.');
        $this->assertSame('相姦マニュアル', $fallback->title);
        $this->assertNull($fallback->circle);
    }
}
```

- [ ] **Step 8: Run the feature test to verify it passes**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test --filter=FilenameParserResolutionTest`
Expected: PASS (the container builds the parser from `config('parser.patterns')`; StandardDoujinPattern is tried before FallbackPattern).

- [ ] **Step 9: Run the full suite**

Run: `PATH="/opt/homebrew/opt/php/bin:$PATH" php artisan test`
Expected: PASS (all prior Plan-1 tests + the new parser tests), output pristine.

- [ ] **Step 10: Commit**

```bash
git add app/Parsing/FilenameParser.php config/parser.php app/Providers/AppServiceProvider.php tests/Unit/Parsing/FilenameParserTest.php tests/Feature/Parsing/FilenameParserResolutionTest.php
git commit -m "$(cat <<'EOF'
feat: add FilenameParser service with config-driven pattern registry

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Notes

- **Spec §6 coverage:** result object `{event, circle, author, title, parody, language, flags[]}` → `ParsedName` (Task 1); ordered registry of pattern classes in `config/parser.php` with `matches()`/`parse()` → Tasks 2-4; standard pattern peeling (leading `(event)`, first `[circle (author)]`, trailing `(parody)`, repeatable trailing `[flags]`, remainder → title) → Task 3; variants (no event / `[circle]` no author / no parody / multiple flags) → covered by optional components in Task 3 and asserted in its tests; fallback (whole → title, others null) → Task 2; `sort_title` by stripping leading symbols/brackets → Task 1; mangaka from folder, never filename → `ParsedName` has no mangaka field, `$mangaka` is an unused input param. The three §6 worked examples are fixtures (full standard in Task 3; the two fallbacks in Task 2). §11 "parser unit tests, written first, exact field assertions" → every task is TDD with exact `assertSame`.
- **v1 deferrals (documented in Global Constraints):** `language` always null; ASCII brackets only (full-width is a future pattern); parody extracted but never used for grouping.
- **Type consistency:** `ParsedName::make(...)` signature and `NamePattern::parse(string, string): ParsedName` are used identically across Tasks 2-4; `peelTrailingGroup` is reused for parody, flags, and circle/author splitting.
- **Purity:** Tasks 1-3 tests extend `PHPUnit\Framework\TestCase` (no app boot, no DB); only Task 4's resolution test extends `Tests\TestCase` (needs the container/config).
