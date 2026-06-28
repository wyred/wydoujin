<?php

use App\Models\Mangaka;
use App\Models\Work;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('discovers nested zips under a real mangaka folder', function (): void {
    $this->makeDoujin('Kakao/Specials', '純情ラブパンチ [DL版]'); // depth-3 path

    $this->runScan();

    $work = Work::firstOrFail();
    $this->assertSame('Kakao/Specials/純情ラブパンチ [DL版].zip', $work->relative_path);
    $this->assertSame('Kakao', $work->mangaka->name); // subfolder ignored
    $this->assertSame('純情ラブパンチ', $work->title);
});

test('_series zip: discovered and filed under the filename artist (subfolder is the path)', function (): void {
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語');

    $this->runScan();

    $work = Work::firstOrFail();
    $this->assertSame('_series/化物語/(同人誌) [ns2k (みまさかよろず)] 虜物語.zip', $work->relative_path);
    $this->assertSame('みまさかよろず', $work->mangaka->name); // mangaka from the filename, not the bucket
});

test('one mangaka row per derived name even across many bucket files (no race)', function (): void {
    // Distinct image entries → distinct content_hash, so these stay two separate works.
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語', ['001.jpg', '002.jpg']);
    $this->makeDoujin('_series/化物語', '(同人誌) [ns2k (みまさかよろず)] 虜物語2', ['a.jpg', 'b.jpg', 'c.jpg']);

    $this->runScan();

    $this->assertSame(1, Mangaka::where('name', 'みまさかよろず')->count());
    $this->assertSame(2, Work::count());
});
