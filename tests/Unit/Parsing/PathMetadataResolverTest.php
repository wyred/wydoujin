<?php

use App\Parsing\FilenameParser;
use App\Parsing\PathMetadataResolver;
use App\Parsing\Patterns\CircleTitlePattern;
use App\Parsing\Patterns\FallbackPattern;
use App\Parsing\Patterns\StandardDoujinPattern;
use App\Parsing\Patterns\TrailingMetadataPattern;

function resolverUnderTest(): PathMetadataResolver
{
    $parser = new FilenameParser([
        new StandardDoujinPattern(),
        new TrailingMetadataPattern(),
        new FallbackPattern(),
    ]);

    return new PathMetadataResolver($parser, new CircleTitlePattern());
}

/** @return list<array{0:string,1:string}> sorted [type,value] pairs derived for a path */
function tagPairsFor(string $relativePath): array
{
    $p = resolverUnderTest()->resolve($relativePath)->parsed;
    $pairs = [];
    foreach (['circle', 'parody', 'event', 'author'] as $t) {
        if ($p->{$t} !== null && $p->{$t} !== '') {
            $pairs[] = [$t, $p->{$t}];
        }
    }
    foreach ($p->flags as $f) {
        $pairs[] = ['flag', $f];
    }
    foreach ($p->extraTags as $pair) {
        $pairs[] = $pair;
    }
    sort($pairs);

    return $pairs;
}

test('normal folder: mangaka is the folder, author derived from it', function (): void {
    $path = '華容道 (松果)/羽川ちゃんは語りたい (化物語) [DL版].zip';
    $meta = resolverUnderTest()->resolve($path);
    $this->assertSame('華容道 (松果)', $meta->mangakaName);
    $this->assertSame('羽川ちゃんは語りたい', $meta->parsed->title);
    $pairs = tagPairsFor($path);
    $this->assertContains(['circle', '華容道'], $pairs);
    $this->assertContains(['author', '松果'], $pairs);
    $this->assertContains(['parody', '化物語'], $pairs);
    $this->assertContains(['flag', 'DL版'], $pairs);
});

test('nested file under a real mangaka: subfolder is ignored', function (): void {
    $meta = resolverUnderTest()->resolve('Kakao/Specials/純情ラブパンチ [DL版].zip');
    $this->assertSame('Kakao', $meta->mangakaName);
    $this->assertSame('純情ラブパンチ', $meta->parsed->title);
});

test('_series: mangaka from filename, subfolder becomes a parody tag', function (): void {
    $path = '_series/化物語/(同人誌) [ns2k (みまさかよろず)] 虜物語.zip';
    $meta = resolverUnderTest()->resolve($path);
    $this->assertSame('みまさかよろず', $meta->mangakaName); // author preferred
    $pairs = tagPairsFor($path);
    $this->assertContains(['circle', 'ns2k'], $pairs);
    $this->assertContains(['author', 'みまさかよろず'], $pairs);
    $this->assertContains(['parody', '化物語'], $pairs);
});

test('_series parody from folder de-dupes with the filename parody', function (): void {
    // filename already carries (化物語); folder is also 化物語 → only one parody value
    $path = '_series/化物語/(同人誌) [ns2k (みまさかよろず)] 虜物語 (化物語).zip';
    $parodies = array_values(array_filter(tagPairsFor($path), fn ($p) => $p[0] === 'parody'));
    $this->assertSame([['parody', '化物語']], array_values(array_unique($parodies, SORT_REGULAR)));
});

test('_series bracketless circle - title resolves an artist', function (): void {
    $path = '_series/-Saki-/from SCRATCH - のどかなペンギン.zip';
    $meta = resolverUnderTest()->resolve($path);
    $this->assertSame('from SCRATCH', $meta->mangakaName); // circle (no author) → circle
    $this->assertSame('のどかなペンギン', $meta->parsed->title);
    $this->assertContains(['parody', '-Saki-'], tagPairsFor($path));
});

test('_small: mangaka from the filename bracket block', function (): void {
    $meta = resolverUnderTest()->resolve('_small/(同人誌) [三崎 (inoino)] 姉を売った…少年Mの手記 (オリジナル).zip');
    $this->assertSame('inoino', $meta->mangakaName);
});

test('_small with no derivable artist falls back to Unknown', function (): void {
    $meta = resolverUnderTest()->resolve('_small/時間を止めて　セクハラ天国.zip');
    $this->assertSame('Unknown', $meta->mangakaName);
});

test('_雑誌 stays a literal mangaka (not a bucket)', function (): void {
    $meta = resolverUnderTest()->resolve('_雑誌/(成年コミック) [雑誌] COMIC saseco Vol. 3 [DL版].zip');
    $this->assertSame('_雑誌', $meta->mangakaName);
});

test('normal title containing a dash is NOT split into circle', function (): void {
    $meta = resolverUnderTest()->resolve('しでん晶/A - B というタイトル.zip');
    $this->assertSame('しでん晶', $meta->mangakaName);
    $this->assertSame('A - B というタイトル', $meta->parsed->title);
    $this->assertNull($meta->parsed->circle);
});
