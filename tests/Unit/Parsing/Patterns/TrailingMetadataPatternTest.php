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
