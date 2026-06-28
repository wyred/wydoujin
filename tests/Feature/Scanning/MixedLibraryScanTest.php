<?php

use App\Models\Work;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('a mixed library scans every layout correctly', function (): void {
    // Distinct entry lists → distinct content_hash, so all five stay separate works.
    $this->makeDoujin('華容道 (松果)', '羽川ちゃんは語りたい (化物語) [DL版]', ['001.jpg']);                  // normal, title-first
    $this->makeDoujin('Kakao/Specials', '純情ラブパンチ [DL版]', ['001.jpg', '002.jpg']);                   // nested under real mangaka
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語', ['a.jpg', 'b.jpg', 'c.jpg']); // _series bucket
    $this->makeDoujin('_small', '(同人誌) [三崎 (inoino)] 姉を売った…少年Mの手記 (オリジナル)', ['p1.jpg']);    // _small bucket
    $this->makeDoujin('_雑誌', '(成年コミック) [雑誌] COMIC saseco Vol. 3 [DL版]', ['x.jpg', 'y.jpg']);       // literal mangaka

    $scan = $this->runScan();

    $this->assertSame(5, $scan->stats['added']);
    $this->assertSame(5, Work::count());

    // Mangaka assignment per layout.
    $this->assertSame('華容道 (松果)', Work::where('relative_path', 'like', '華容道%')->first()->mangaka->name);
    $this->assertSame('Kakao', Work::where('relative_path', 'like', 'Kakao/%')->first()->mangaka->name);
    $this->assertSame('みまさかよろず', Work::where('relative_path', 'like', '_series/%')->first()->mangaka->name);
    $this->assertSame('inoino', Work::where('relative_path', 'like', '_small/%')->first()->mangaka->name);
    $this->assertSame('_雑誌', Work::where('relative_path', 'like', '_雑誌/%')->first()->mangaka->name);
});

test('folder + _series tags survive a rescan unchanged', function (): void {
    $this->makeDoujin('華容道 (松果)', '羽川ちゃんは語りたい (化物語) [DL版]');
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語');
    $this->runScan();

    $tagsByPath = fn () => Work::with('tags')->get()->mapWithKeys(fn ($w) => [
        $w->relative_path => $w->tags->map(fn ($t) => "{$t->type}:{$t->value}")->sort()->values()->all(),
    ]);

    $before = $tagsByPath();
    $this->runScan(); // a no-op rescan must not change any work's tag set
    $after = $tagsByPath();

    $this->assertEquals($before->all(), $after->all());
});
