<?php

namespace Tests\Feature\Series;

use App\Models\Series;
use App\Models\Work;
use App\Series\SeriesDetectorContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesManagementTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMangakaWorks;

    private function detect(): void
    {
        app(SeriesDetectorContract::class)->detect();
    }

    public function test_group_creates_a_locked_manual_series_that_survives_redetect(): void
    {
        $a = $this->seedWork('Z.A.P.', '四畳半物語');
        $b = $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

        $this->postJson('/series/group', ['work_ids' => [$a->id, $b->id], 'name' => '四畳半物語'])
            ->assertStatus(201);

        $series = Series::firstOrFail();
        $this->assertFalse($series->is_auto);
        $this->assertSame('四畳半物語', $series->name);
        $this->assertSame($series->id, $a->refresh()->series_id);
        $this->assertTrue($a->refresh()->series_locked);
        $this->assertTrue($b->refresh()->series_locked);

        // Lock contract: a re-detect leaves the manual series + links intact.
        $this->detect();
        $this->assertNotNull(Series::find($series->id));
        $this->assertSame($series->id, $a->refresh()->series_id);
        $this->assertSame($series->id, $b->refresh()->series_id);
    }

    public function test_add_to_existing_series_flips_is_auto_and_locks(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $series = Series::create(['mangaka_id' => $m->id, 'name' => 'Stuff', 'is_auto' => true]);
        $w = $this->seedWork('Z.A.P.', 'ぽつん');

        $this->postJson('/series/'.$series->id.'/add', ['work_ids' => [$w->id]])->assertOk();

        $this->assertFalse($series->refresh()->is_auto);
        $this->assertSame($series->id, $w->refresh()->series_id);
        $this->assertTrue($w->refresh()->series_locked);
    }

    public function test_ungroup_clears_series_and_locks(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $series = Series::create(['mangaka_id' => $m->id, 'name' => 'S', 'is_auto' => false]);
        $w = $this->seedWork('Z.A.P.', 'x', ['series_id' => $series->id]);

        $this->postJson('/series/ungroup', ['work_ids' => [$w->id]])->assertOk();

        $this->assertNull($w->refresh()->series_id);
        $this->assertTrue($w->refresh()->series_locked);
    }

    public function test_group_deletes_an_emptied_auto_series(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $auto = Series::create(['mangaka_id' => $m->id, 'name' => 'Auto', 'is_auto' => true]);
        $a = $this->seedWork('Z.A.P.', 'a', ['series_id' => $auto->id]);
        $b = $this->seedWork('Z.A.P.', 'b', ['series_id' => $auto->id]);

        $this->postJson('/series/group', ['work_ids' => [$a->id, $b->id], 'name' => 'New'])->assertStatus(201);

        $this->assertNull(Series::find($auto->id)); // emptied auto series cleaned
    }

    public function test_rename_updates_name_sort_and_locks_members(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $series = Series::create(['mangaka_id' => $m->id, 'name' => 'Old', 'is_auto' => true]);
        $w = $this->seedWork('Z.A.P.', 'x', ['series_id' => $series->id]);

        $this->postJson('/series/'.$series->id.'/rename', ['name' => 'New Name'])->assertOk();

        $this->assertSame('New Name', $series->refresh()->name);
        $this->assertFalse($series->refresh()->is_auto);
        $this->assertTrue($w->refresh()->series_locked);
    }

    public function test_rejects_cross_mangaka_work_ids(): void
    {
        $a = $this->seedWork('Artist A', 'x');
        $b = $this->seedWork('Artist B', 'y');

        $this->postJson('/series/group', ['work_ids' => [$a->id, $b->id], 'name' => 'Mix'])
            ->assertStatus(422);
        $this->assertSame(0, Series::count());
    }

    public function test_rejects_blank_name(): void
    {
        $a = $this->seedWork('Z.A.P.', 'x');
        $this->postJson('/series/group', ['work_ids' => [$a->id], 'name' => '   '])->assertStatus(422);
    }

    public function test_add_rejects_series_from_another_mangaka(): void
    {
        $other = $this->mangaka('Artist B');
        $series = Series::create(['mangaka_id' => $other->id, 'name' => 'B series', 'is_auto' => false]);
        $w = $this->seedWork('Artist A', 'x');

        $this->postJson('/series/'.$series->id.'/add', ['work_ids' => [$w->id]])->assertStatus(422);
    }
}
