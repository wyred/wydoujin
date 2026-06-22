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
