<?php

namespace Tests\Unit\Parsing\Patterns;

use App\Parsing\Patterns\FallbackPattern;
use PHPUnit\Framework\TestCase;

class FallbackPatternTest extends TestCase
{
    public function test_matches_everything(): void
    {
        $p = new FallbackPattern();
        $this->assertTrue($p->matches('anything at all'));
        $this->assertTrue($p->matches(''));
    }

    public function test_parse_uses_whole_filename_as_title(): void
    {
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
    }

    public function test_parse_trims_title_but_keeps_raw_filename(): void
    {
        $r = (new FallbackPattern())->parse('  Spaced Title  ', 'SomeMangaka');

        $this->assertSame('Spaced Title', $r->title);
        $this->assertSame('  Spaced Title  ', $r->titleRaw);
        $this->assertSame('Spaced Title', $r->sortTitle);
    }
}
