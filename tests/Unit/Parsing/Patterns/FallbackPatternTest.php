<?php

use App\Parsing\Patterns\FallbackPattern;

test('matches everything', function (): void {
    $p = new FallbackPattern();
    $this->assertTrue($p->matches('anything at all'));
    $this->assertTrue($p->matches(''));
});

test('parse uses whole filename as title', function (): void {
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
    $this->assertSame('Two Lovers EN', $r2->titleRaw);
    $this->assertSame('Two Lovers EN', $r2->sortTitle);
    $this->assertNull($r2->event);
    $this->assertNull($r2->circle);
    $this->assertNull($r2->author);
    $this->assertNull($r2->parody);
    $this->assertNull($r2->language);
    $this->assertSame([], $r2->flags);
});

test('parse trims title but keeps raw filename', function (): void {
    $r = (new FallbackPattern())->parse('  Spaced Title  ', 'SomeMangaka');

    $this->assertSame('Spaced Title', $r->title);
    $this->assertSame('  Spaced Title  ', $r->titleRaw);
    $this->assertSame('Spaced Title', $r->sortTitle);
});
