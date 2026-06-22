<?php

namespace Tests\Unit\Parsing;

use App\Parsing\FilenameParser;
use App\Parsing\Patterns\FallbackPattern;
use App\Parsing\Patterns\StandardDoujinPattern;
use PHPUnit\Framework\TestCase;

class FilenameParserTest extends TestCase
{
    private function parser(): FilenameParser
    {
        return new FilenameParser([new StandardDoujinPattern(), new FallbackPattern()]);
    }

    public function test_routes_standard_filename_to_standard_pattern(): void
    {
        $r = $this->parser()->parse('(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]', 'Z.A.P.');
        $this->assertSame('四畳半物語', $r->title);
        $this->assertSame('C89', $r->event);
        $this->assertSame(['DL版'], $r->flags);
    }

    public function test_routes_bracketless_filename_to_fallback(): void
    {
        $r = $this->parser()->parse('相姦マニュアル', 'SomeMangaka');
        $this->assertSame('相姦マニュアル', $r->title);
        $this->assertNull($r->event);
        $this->assertNull($r->circle);
    }
}
