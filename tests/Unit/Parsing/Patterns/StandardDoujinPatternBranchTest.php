<?php

use App\Parsing\Patterns\StandardDoujinPattern;

// Uncovered branches in StandardDoujinPattern:
// Line 55: peelLeadingGroup returns null when closing bracket is absent.
// Line 73: peelTrailingGroup returns null when opening bracket is absent.
// Line 106: splitCircleAuthor returns [null, null] for empty-string block.
// / 未カバー分岐：閉じ括弧なし・開き括弧なし・空ブロック。

test('unclosed leading bracket yields null event and circle', function (): void {
    // "(EVENT" has no closing ")" — peelLeadingGroup hits line 55 and returns null.
    // / 閉じ括弧なし → peelLeadingGroupが55行目でnullを返す。
    $r = (new StandardDoujinPattern())->parse('(EVENT タイトル', 'Circle');

    $this->assertNull($r->event);
    $this->assertNull($r->circle);
    $this->assertSame('(EVENT タイトル', $r->title); // whole filename becomes title
});

test('trailing group with no open bracket yields null parody', function (): void {
    // "タイトル オリジナル)" ends with ")" but has no "(" — peelTrailingGroup hits line 73.
    // / 開き括弧なし → peelTrailingGroupが73行目でnullを返す。
    $r = (new StandardDoujinPattern())->parse('(C89) [Circle] タイトル オリジナル)', 'Circle');

    $this->assertNull($r->parody);
    $this->assertSame('タイトル オリジナル)', $r->title);
});

test('circle block empty string produces null circle and null author', function (): void {
    // "[]" peels to an empty inner block — splitCircleAuthor hits line 106.
    // / 空の"[]" → splitCircleAuthorが106行目で[null,null]を返す。
    $r = (new StandardDoujinPattern())->parse('[] タイトル', 'Circle');

    $this->assertNull($r->circle);
    $this->assertNull($r->author);
    $this->assertSame('タイトル', $r->title);
});
