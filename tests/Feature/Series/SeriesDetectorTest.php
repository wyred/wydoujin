<?php

namespace Tests\Feature\Series;

use App\Models\Series;
use App\Models\Tag;
use App\Models\Work;
use App\Series\SeriesDetectorContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesDetectorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMangakaWorks;

    private function detector(): SeriesDetectorContract
    {
        return app(SeriesDetectorContract::class);
    }

    public function test_groups_multi_volume_into_one_auto_series(): void
    {
        $a = $this->seedWork('Z.A.P.', '四畳半物語');
        $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

        $stats = $this->detector()->detect();

        $this->assertSame(1, $stats['series_created']);
        $this->assertSame(2, $stats['works_grouped']);
        $this->assertSame(1, Series::count());
        $series = Series::firstOrFail();
        $this->assertSame('四畳半物語', $series->name);
        $this->assertTrue($series->is_auto);
        $this->assertSame($series->id, $a->refresh()->series_id);
        $this->assertSame($series->id, $b->refresh()->series_id);
    }

    public function test_all_volumes_without_a_bare_stem_still_group(): void
    {
        $this->seedWork('Z.A.P.', '四畳半物語 二畳目');
        $this->seedWork('Z.A.P.', '四畳半物語 三畳目');

        $this->detector()->detect();

        $this->assertSame(1, Series::count());
        $this->assertSame('四畳半物語', Series::firstOrFail()->name);
    }

    public function test_prefix_at_boundary_groups_unknown_suffix(): void
    {
        // 黒猫編 is not in the normalizer vocab; the space boundary still merges. / 接頭辞境界で結合。
        $this->seedWork('Circle', 'ねこむすめ');
        $this->seedWork('Circle', 'ねこむすめ 黒猫編');

        $this->detector()->detect();

        $this->assertSame(1, Series::count());
        $this->assertSame('ねこむすめ', Series::firstOrFail()->name);
    }

    public function test_prefix_without_boundary_does_not_merge(): void
    {
        $this->seedWork('Circle', 'Love');
        $this->seedWork('Circle', 'Lovely');

        $this->detector()->detect();

        $this->assertSame(0, Series::count());
        $this->assertNull(Work::where('title', 'Love')->firstOrFail()->series_id);
    }

    public function test_same_parody_distinct_titles_stay_standalone(): void
    {
        // The Fate trap: shared parody tag, different titles → never a series. / パロディで結合しない。
        $parody = Tag::firstOrCreate(['type' => 'parody', 'value' => 'Fate/Grand Order']);
        $a = $this->seedWork('FateCircle', 'カルデアの日常');
        $b = $this->seedWork('FateCircle', '謁見のあとで');
        $c = $this->seedWork('FateCircle', 'ぐだ子とマシュ');
        $a->tags()->attach($parody);
        $b->tags()->attach($parody);
        $c->tags()->attach($parody);

        $stats = $this->detector()->detect();

        $this->assertSame(0, Series::count());
        $this->assertSame(0, $stats['works_grouped']);
        $this->assertSame(0, Work::whereNotNull('series_id')->count());
    }

    public function test_single_work_makes_no_series(): void
    {
        $this->seedWork('Solo', 'ひとりぼっち');

        $this->detector()->detect();

        $this->assertSame(0, Series::count());
    }

    public function test_series_never_cross_mangaka(): void
    {
        $this->seedWork('CircleA', '同じ題');
        $this->seedWork('CircleB', '同じ題');

        $this->detector()->detect();

        $this->assertSame(0, Series::count());
    }

    public function test_locked_work_is_excluded_from_autodetection(): void
    {
        $a = $this->seedWork('Z.A.P.', '四畳半物語');
        $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目', ['series_locked' => true]);

        $this->detector()->detect();

        // Only one non-locked work shares the stem → no series; locked work untouched.
        // 非ロックは1作のみ→シリーズ化せず。ロック作品は不変。
        $this->assertSame(0, Series::count());
        $this->assertNull($a->refresh()->series_id);
        $this->assertNull($b->refresh()->series_id);
        $this->assertTrue($b->refresh()->series_locked);
    }

    public function test_manual_series_and_locked_links_are_never_undone(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $manual = Series::create(['mangaka_id' => $m->id, 'name' => '私家版', 'is_auto' => false]);
        // Two locked works sharing a normalized stem — without the lock filter they would auto-cluster.
        // ロックがなければ同じステムで自動グループ化される2作品。
        $x = $this->seedWork('Z.A.P.', '四畳半物語', ['series_id' => $manual->id, 'series_locked' => true]);
        $y = $this->seedWork('Z.A.P.', '四畳半物語 二畳目', ['series_id' => $manual->id, 'series_locked' => true]);
        $standalone = $this->seedWork('Z.A.P.', 'ぽつん');

        $this->detector()->detect();

        $this->assertNotNull(Series::find($manual->id));         // manual series preserved
        $this->assertSame($manual->id, $x->refresh()->series_id); // locked links intact
        $this->assertSame($manual->id, $y->refresh()->series_id);
        $this->assertNull($standalone->refresh()->series_id);     // non-locked standalone cleared
    }

    public function test_detect_is_idempotent(): void
    {
        $this->seedWork('Z.A.P.', '四畳半物語');
        $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

        $first = $this->detector()->detect();
        $seriesId = Series::firstOrFail()->id;
        $second = $this->detector()->detect();

        $this->assertSame(1, $first['series_created']);
        $this->assertSame(0, $second['series_created']);   // already exists
        $this->assertSame(2, $second['works_grouped']);    // still grouped
        $this->assertSame(1, Series::count());             // no duplicate
        $this->assertSame($seriesId, Series::firstOrFail()->id);
    }

    public function test_rerun_clears_series_and_deletes_it_when_sibling_disappears(): void
    {
        $a = $this->seedWork('Z.A.P.', '四畳半物語');
        $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目');
        $this->detector()->detect();
        $this->assertSame(1, Series::count());

        $b->delete(); // the second volume goes missing from the library / 片方が消える
        $this->detector()->detect();

        $this->assertNull($a->refresh()->series_id);  // now standalone
        $this->assertSame(0, Series::count());         // empty auto series removed
    }
}
