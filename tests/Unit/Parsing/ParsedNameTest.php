<?php

use App\Parsing\ParsedName;

test('make derives sort title and holds fields', function (): void {
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
    $this->assertSame(['DL版'], $r->flags);
});
