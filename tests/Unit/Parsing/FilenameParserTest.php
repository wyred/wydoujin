<?php

use App\Parsing\FilenameParser;
use App\Parsing\Patterns\FallbackPattern;
use App\Parsing\Patterns\StandardDoujinPattern;

function parserFilenameParser(): FilenameParser
{
    return new FilenameParser([new StandardDoujinPattern(), new FallbackPattern()]);
}

test('routes standard filename to standard pattern', function (): void {
    $r = parserFilenameParser()->parse('(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]', 'Z.A.P.');
    $this->assertSame('四畳半物語', $r->title);
    $this->assertSame('C89', $r->event);
    $this->assertSame(['DL版'], $r->flags);
});

test('routes bracketless filename to fallback', function (): void {
    $r = parserFilenameParser()->parse('相姦マニュアル', 'SomeMangaka');
    $this->assertSame('相姦マニュアル', $r->title);
    $this->assertNull($r->event);
    $this->assertNull($r->circle);
});
