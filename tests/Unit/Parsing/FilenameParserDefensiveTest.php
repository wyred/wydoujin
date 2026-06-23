<?php

use App\Parsing\FilenameParser;
use App\Parsing\ParsedName;
use App\Parsing\Patterns\FallbackPattern;
use App\Parsing\NamePattern;

// A pattern that never matches — forces the defensive fallback on line 26.
// / 常に不一致のパターン → 26行目の防御的フォールバックを強制。
final class NeverMatchingPattern implements NamePattern
{
    public function matches(string $filename): bool { return false; }
    public function parse(string $filename, string $mangaka): ParsedName
    {
        return (new FallbackPattern())->parse($filename, $mangaka);
    }
}

test('defensive fallback fires when no registered pattern matches', function (): void {
    // Pass only a never-matching pattern — no FallbackPattern at the end.
    // / 末尾にFallbackPatternを置かず、常に不一致のパターンのみ登録。
    $parser = new FilenameParser([new NeverMatchingPattern()]);

    $r = $parser->parse('相姦マニュアル', 'SomeMangaka');

    $this->assertSame('相姦マニュアル', $r->title);
    $this->assertNull($r->event);
    $this->assertNull($r->circle);
});
