<?php

use App\Models\Series;
use App\Models\Work;
use App\Parsing\ParsedName;
use App\Series\SeriesDetectorContract;
use Tests\Feature\Series\SeedsMangakaWorks;

uses(SeedsMangakaWorks::class);

function detectForSeriesManagement(): void
{
    app(SeriesDetectorContract::class)->detect();
}

test('group creates a locked manual series that survives redetect', function (): void {
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
    detectForSeriesManagement();
    $this->assertNotNull(Series::find($series->id));
    $this->assertSame($series->id, $a->refresh()->series_id);
    $this->assertSame($series->id, $b->refresh()->series_id);
});

test('add to existing series flips is auto and locks', function (): void {
    $m = $this->mangaka('Z.A.P.');
    $series = Series::create(['mangaka_id' => $m->id, 'name' => 'Stuff', 'is_auto' => true]);
    $w = $this->seedWork('Z.A.P.', 'ぽつん');

    $this->postJson('/series/'.$series->id.'/add', ['work_ids' => [$w->id]])->assertOk();

    $this->assertFalse($series->refresh()->is_auto);
    $this->assertSame($series->id, $w->refresh()->series_id);
    $this->assertTrue($w->refresh()->series_locked);

    // Lock contract: a re-detect leaves the manual add intact.
    detectForSeriesManagement();
    $this->assertFalse($series->refresh()->is_auto);
    $this->assertSame($series->id, $w->refresh()->series_id);
    $this->assertTrue($w->refresh()->series_locked);
});

test('ungroup clears series and locks', function (): void {
    $m = $this->mangaka('Z.A.P.');
    $series = Series::create(['mangaka_id' => $m->id, 'name' => 'S', 'is_auto' => false]);
    $w = $this->seedWork('Z.A.P.', 'x', ['series_id' => $series->id]);

    $this->postJson('/series/ungroup', ['work_ids' => [$w->id]])->assertOk();

    $this->assertNull($w->refresh()->series_id);
    $this->assertTrue($w->refresh()->series_locked);

    $this->assertNotNull(\App\Models\Series::find($series->id)); // is_auto=false series survives cleanup
    detectForSeriesManagement();
    $this->assertNull($w->refresh()->series_id);
    $this->assertTrue($w->refresh()->series_locked);
});

test('group deletes an emptied auto series', function (): void {
    $m = $this->mangaka('Z.A.P.');
    $auto = Series::create(['mangaka_id' => $m->id, 'name' => 'Auto', 'is_auto' => true]);
    $a = $this->seedWork('Z.A.P.', 'a', ['series_id' => $auto->id]);
    $b = $this->seedWork('Z.A.P.', 'b', ['series_id' => $auto->id]);

    $this->postJson('/series/group', ['work_ids' => [$a->id, $b->id], 'name' => 'New'])->assertStatus(201);

    $this->assertNull(Series::find($auto->id)); // emptied auto series cleaned
});

test('rename updates name sort and locks members', function (): void {
    $m = $this->mangaka('Z.A.P.');
    $series = Series::create(['mangaka_id' => $m->id, 'name' => 'Old', 'is_auto' => true]);
    $w = $this->seedWork('Z.A.P.', 'x', ['series_id' => $series->id]);

    $this->postJson('/series/'.$series->id.'/rename', ['name' => 'New Name'])->assertOk();

    $this->assertSame('New Name', $series->refresh()->name);
    $this->assertFalse($series->refresh()->is_auto);
    $this->assertTrue($w->refresh()->series_locked);

    $this->assertSame(ParsedName::deriveSortTitle('New Name'), $series->refresh()->sort_name);
    detectForSeriesManagement();
    $this->assertSame('New Name', $series->refresh()->name);
    $this->assertFalse($series->refresh()->is_auto);
    $this->assertTrue($w->refresh()->series_locked);
});

test('add deletes an emptied source auto series', function (): void {
    $m = $this->mangaka('Z.A.P.');
    $target = \App\Models\Series::create(['mangaka_id' => $m->id, 'name' => 'Target', 'is_auto' => false]);
    $auto = \App\Models\Series::create(['mangaka_id' => $m->id, 'name' => 'Auto', 'is_auto' => true]);
    $w = $this->seedWork('Z.A.P.', 'x', ['series_id' => $auto->id]);

    $this->postJson('/series/'.$target->id.'/add', ['work_ids' => [$w->id]])->assertOk();

    $this->assertNull(\App\Models\Series::find($auto->id)); // emptied source auto series cleaned
    $this->assertSame($target->id, $w->refresh()->series_id);
});

test('rejects cross mangaka work ids', function (): void {
    $a = $this->seedWork('Artist A', 'x');
    $b = $this->seedWork('Artist B', 'y');

    $this->postJson('/series/group', ['work_ids' => [$a->id, $b->id], 'name' => 'Mix'])
        ->assertStatus(422);
    $this->assertSame(0, Series::count());
});

test('rejects blank name', function (): void {
    $a = $this->seedWork('Z.A.P.', 'x');
    $this->postJson('/series/group', ['work_ids' => [$a->id], 'name' => '   '])->assertStatus(422);
});

test('add rejects series from another mangaka', function (): void {
    $other = $this->mangaka('Artist B');
    $series = Series::create(['mangaka_id' => $other->id, 'name' => 'B series', 'is_auto' => false]);
    $w = $this->seedWork('Artist A', 'x');

    $this->postJson('/series/'.$series->id.'/add', ['work_ids' => [$w->id]])->assertStatus(422);
});
