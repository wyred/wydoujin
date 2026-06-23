<?php

use App\Parsing\Patterns\StandardDoujinPattern;

test('matches only when leading bracket present', function (): void {
    $p = new StandardDoujinPattern();
    $this->assertTrue($p->matches('(C89) [Z.A.P.] 四畳半物語'));
    $this->assertTrue($p->matches('[サークル] タイトル'));
    $this->assertFalse($p->matches('相姦マニュアル'));
    $this->assertFalse($p->matches('Two Lovers EN'));
});

test('full standard filename', function (): void {
    $r = (new StandardDoujinPattern())
        ->parse('(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]', 'Z.A.P.');

    $this->assertSame('C89', $r->event);
    $this->assertSame('Z.A.P.', $r->circle);
    $this->assertSame('ズッキーニ', $r->author);
    $this->assertSame('四畳半物語', $r->title);
    $this->assertSame('オリジナル', $r->parody);
    $this->assertSame(['DL版'], $r->flags);
    $this->assertNull($r->language);
});

test('no event', function (): void {
    $r = (new StandardDoujinPattern())->parse('[Z.A.P. (ズッキーニ)] 四畳半物語 二畳目', 'Z.A.P.');

    $this->assertNull($r->event);
    $this->assertSame('Z.A.P.', $r->circle);
    $this->assertSame('ズッキーニ', $r->author);
    $this->assertSame('四畳半物語 二畳目', $r->title);
    $this->assertNull($r->parody);
    $this->assertSame([], $r->flags);
});

test('circle without author', function (): void {
    $r = (new StandardDoujinPattern())->parse('[サークル] タイトル', 'サークル');

    $this->assertSame('サークル', $r->circle);
    $this->assertNull($r->author);
    $this->assertSame('タイトル', $r->title);
});

test('multiple flags and parody', function (): void {
    $r = (new StandardDoujinPattern())
        ->parse('(C99) [Circle (Author)] Some Title (Fate/Grand Order) [English] [DL版]', 'Circle');

    $this->assertSame('C99', $r->event);
    $this->assertSame('Circle', $r->circle);
    $this->assertSame('Author', $r->author);
    $this->assertSame('Some Title', $r->title);
    $this->assertSame('Fate/Grand Order', $r->parody);
    $this->assertSame(['English', 'DL版'], $r->flags);
});

test('flags without parody', function (): void {
    $r = (new StandardDoujinPattern())->parse('[Circle (Author)] Title Here [DL版]', 'Circle');

    $this->assertSame('Circle', $r->circle);
    $this->assertSame('Author', $r->author);
    $this->assertSame('Title Here', $r->title);
    $this->assertNull($r->parody);
    $this->assertSame(['DL版'], $r->flags);
});
