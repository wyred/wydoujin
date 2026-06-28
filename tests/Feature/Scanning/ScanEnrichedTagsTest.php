<?php

use App\Models\Work;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('title-first file in a normal folder gets folder author + filename parody/flags', function (): void {
    $this->makeDoujin('華容道 (松果)', '羽川ちゃんは語りたい (化物語) [DL版]');

    $this->runScan();

    $work = Work::firstOrFail();
    $this->assertSame('羽川ちゃんは語りたい', $work->title);
    $pairs = $work->tags()->get()->map(fn ($t) => [$t->type, $t->value])->all();
    $this->assertContains(['circle', '華容道'], $pairs);  // from the folder
    $this->assertContains(['author', '松果'], $pairs);    // from the folder
    $this->assertContains(['parody', '化物語'], $pairs);  // from the filename
    $this->assertContains(['flag', 'DL版'], $pairs);      // from the filename
});

test('a full scan of a _series work carries folder parody + filename artist', function (): void {
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語');

    $this->runScan();

    $work = Work::firstOrFail();
    $this->assertSame('みまさかよろず', $work->mangaka->name);
    $pairs = $work->tags()->get()->map(fn ($t) => [$t->type, $t->value])->all();
    $this->assertContains(['circle', 'ns2k'], $pairs);
    $this->assertContains(['parody', '化物語'], $pairs);
});
