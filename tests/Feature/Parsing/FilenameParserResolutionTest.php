<?php

use App\Parsing\FilenameParser;

test('parser resolves from config registry and routes correctly', function (): void {
    $parser = app(FilenameParser::class);
    $this->assertInstanceOf(FilenameParser::class, $parser);

    $standard = $parser->parse('(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]', 'Z.A.P.');
    $this->assertSame('四畳半物語', $standard->title);
    $this->assertSame('オリジナル', $standard->parody);

    $fallback = $parser->parse('相姦マニュアル', 'Z.A.P.');
    $this->assertSame('相姦マニュアル', $fallback->title);
    $this->assertNull($fallback->circle);
});
