<?php

use App\Models\Series;
use App\Models\Tag;
use App\Models\Work;
use App\Series\SeriesDetectorContract;
use Tests\Feature\Series\SeedsMangakaWorks;

uses(SeedsMangakaWorks::class);

function detectorForSeriesDetector(): SeriesDetectorContract
{
    return app(SeriesDetectorContract::class);
}

test('groups multi volume into one auto series', function (): void {
    $a = $this->seedWork('Z.A.P.', '四畳半物語');
    $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

    $stats = detectorForSeriesDetector()->detect();

    $this->assertSame(1, $stats['series_created']);
    $this->assertSame(2, $stats['works_grouped']);
    $this->assertSame(1, Series::count());
    $series = Series::firstOrFail();
    $this->assertSame('四畳半物語', $series->name);
    $this->assertTrue($series->is_auto);
    $this->assertSame($series->id, $a->refresh()->series_id);
    $this->assertSame($series->id, $b->refresh()->series_id);
});

test('all volumes without a bare stem still group', function (): void {
    $this->seedWork('Z.A.P.', '四畳半物語 二畳目');
    $this->seedWork('Z.A.P.', '四畳半物語 三畳目');

    detectorForSeriesDetector()->detect();

    $this->assertSame(1, Series::count());
    $this->assertSame('四畳半物語', Series::firstOrFail()->name);
});

test('prefix at boundary groups unknown suffix', function (): void {
    // 黒猫編 is not in the normalizer vocab; the space boundary still merges. / 接頭辞境界で結合。
    $this->seedWork('Circle', 'ねこむすめ');
    $this->seedWork('Circle', 'ねこむすめ 黒猫編');

    detectorForSeriesDetector()->detect();

    $this->assertSame(1, Series::count());
    $this->assertSame('ねこむすめ', Series::firstOrFail()->name);
});

test('prefix without boundary does not merge', function (): void {
    $this->seedWork('Circle', 'Love');
    $this->seedWork('Circle', 'Lovely');

    detectorForSeriesDetector()->detect();

    $this->assertSame(0, Series::count());
    $this->assertNull(Work::where('title', 'Love')->firstOrFail()->series_id);
});

test('same parody distinct titles stay standalone', function (): void {
    // The Fate trap: shared parody tag, different titles → never a series. / パロディで結合しない。
    $parody = Tag::firstOrCreate(['type' => 'parody', 'value' => 'Fate/Grand Order']);
    $a = $this->seedWork('FateCircle', 'カルデアの日常');
    $b = $this->seedWork('FateCircle', '謁見のあとで');
    $c = $this->seedWork('FateCircle', 'ぐだ子とマシュ');
    $a->tags()->attach($parody);
    $b->tags()->attach($parody);
    $c->tags()->attach($parody);

    $stats = detectorForSeriesDetector()->detect();

    $this->assertSame(0, Series::count());
    $this->assertSame(0, $stats['works_grouped']);
    $this->assertSame(0, Work::whereNotNull('series_id')->count());
});

test('single work makes no series', function (): void {
    $this->seedWork('Solo', 'ひとりぼっち');

    detectorForSeriesDetector()->detect();

    $this->assertSame(0, Series::count());
});

test('series never cross mangaka', function (): void {
    $this->seedWork('CircleA', '同じ題');
    $this->seedWork('CircleB', '同じ題');

    detectorForSeriesDetector()->detect();

    $this->assertSame(0, Series::count());
});

test('locked work is excluded from autodetection', function (): void {
    $a = $this->seedWork('Z.A.P.', '四畳半物語');
    $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目', ['series_locked' => true]);

    detectorForSeriesDetector()->detect();

    // Only one non-locked work shares the stem → no series; locked work untouched.
    // 非ロックは1作のみ→シリーズ化せず。ロック作品は不変。
    $this->assertSame(0, Series::count());
    $this->assertNull($a->refresh()->series_id);
    $this->assertNull($b->refresh()->series_id);
    $this->assertTrue($b->refresh()->series_locked);
});

test('manual series and locked links are never undone', function (): void {
    $m = $this->mangaka('Z.A.P.');
    $manual = Series::create(['mangaka_id' => $m->id, 'name' => '私家版', 'is_auto' => false]);
    // Two locked works sharing a normalized stem — without the lock filter they would auto-cluster.
    // ロックがなければ同じステムで自動グループ化される2作品。
    $x = $this->seedWork('Z.A.P.', '四畳半物語', ['series_id' => $manual->id, 'series_locked' => true]);
    $y = $this->seedWork('Z.A.P.', '四畳半物語 二畳目', ['series_id' => $manual->id, 'series_locked' => true]);
    $standalone = $this->seedWork('Z.A.P.', 'ぽつん');

    detectorForSeriesDetector()->detect();

    $this->assertNotNull(Series::find($manual->id));         // manual series preserved
    $this->assertSame($manual->id, $x->refresh()->series_id); // locked links intact
    $this->assertSame($manual->id, $y->refresh()->series_id);
    $this->assertNull($standalone->refresh()->series_id);     // non-locked standalone cleared
});

test('detect is idempotent', function (): void {
    $this->seedWork('Z.A.P.', '四畳半物語');
    $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

    $first = detectorForSeriesDetector()->detect();
    $seriesId = Series::firstOrFail()->id;
    $second = detectorForSeriesDetector()->detect();

    $this->assertSame(1, $first['series_created']);
    $this->assertSame(0, $second['series_created']);   // already exists
    $this->assertSame(2, $second['works_grouped']);    // still grouped
    $this->assertSame(1, Series::count());             // no duplicate
    $this->assertSame($seriesId, Series::firstOrFail()->id);
});

test('rerun clears series and deletes it when sibling disappears', function (): void {
    $a = $this->seedWork('Z.A.P.', '四畳半物語');
    $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目');
    detectorForSeriesDetector()->detect();
    $this->assertSame(1, Series::count());

    $b->delete(); // the second volume goes missing from the library / 片方が消える
    detectorForSeriesDetector()->detect();

    $this->assertNull($a->refresh()->series_id);  // now standalone
    $this->assertSame(0, Series::count());         // empty auto series removed
});
