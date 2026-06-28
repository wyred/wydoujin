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
