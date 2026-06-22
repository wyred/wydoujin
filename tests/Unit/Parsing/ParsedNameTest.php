<?php

namespace Tests\Unit\Parsing;

use App\Parsing\ParsedName;
use PHPUnit\Framework\TestCase;

class ParsedNameTest extends TestCase
{
    public function test_make_derives_sort_title_and_holds_fields(): void
    {
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
        $this->assertNull($r->language);
        $this->assertSame(['DL版'], $r->flags);
    }

    public function test_derive_sort_title_strips_leading_symbols_and_brackets(): void
    {
        $this->assertSame('Title', ParsedName::deriveSortTitle('★Title'));
        $this->assertSame('Title', ParsedName::deriveSortTitle('  「Title'));
        $this->assertSame('四畳半物語', ParsedName::deriveSortTitle('四畳半物語'));
        $this->assertSame('abc', ParsedName::deriveSortTitle('...abc'));
    }

    public function test_derive_sort_title_falls_back_to_trimmed_title_when_all_stripped(): void
    {
        $this->assertSame('!!!', ParsedName::deriveSortTitle('  !!!  '));
    }
}
