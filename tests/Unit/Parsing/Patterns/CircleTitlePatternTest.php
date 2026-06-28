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
