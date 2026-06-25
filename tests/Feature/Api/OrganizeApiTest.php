<?php

use App\Models\Mangaka;
use App\Models\Series;
use App\Models\Tag;
use App\Models\Work;
use App\Series\SeriesDetectorContract;

beforeEach(function (): void {
    config(['app.api_token' => 'secret']);
    $this->h = ['Authorization' => 'Bearer secret'];
});

// ---- Global tags ----

test('rename a tag leaves a tombstone the scanner resolves', function (): void {
    $m = Mangaka::factory()->create();
    $work = Work::factory()->for($m)->create();
    $tag = Tag::create(['type' => 'parody', 'value' => 'FGO']);
    $work->tags()->attach($tag->id);

    $this->patchJson("/api/v1/tags/{$tag->id}", ['value' => 'Fate/Grand Order'], $this->h)->assertOk();

    expect($tag->fresh()->value)->toBe('Fate/Grand Order');
    // The old value is now a tombstone resolving to the renamed tag.
    expect(Tag::canonicalIdFor('parody', 'FGO'))->toBe($tag->id);
});

test('rename onto an existing canonical value merges', function (): void {
    $m = Mangaka::factory()->create();
    $w1 = Work::factory()->for($m)->create();
    $w2 = Work::factory()->for($m)->create();
    $from = Tag::create(['type' => 'circle', 'value' => 'Zap']);
    $into = Tag::create(['type' => 'circle', 'value' => 'Z.A.P.']);
    $w1->tags()->attach($from->id);
    $w2->tags()->attach($into->id);

    $this->patchJson("/api/v1/tags/{$from->id}", ['value' => 'Z.A.P.'], $this->h)->assertOk();

    expect($from->fresh()->merged_into_id)->toBe($into->id);
    expect($w1->fresh()->tags->pluck('id')->all())->toBe([$into->id]);
});

test('merge repoints works and tombstones the source', function (): void {
    $m = Mangaka::factory()->create();
    $w = Work::factory()->for($m)->create();
    $from = Tag::create(['type' => 'circle', 'value' => 'A']);
    $into = Tag::create(['type' => 'circle', 'value' => 'B']);
    $w->tags()->attach($from->id);

    $this->postJson("/api/v1/tags/{$from->id}/merge", ['into_id' => $into->id], $this->h)->assertOk();

    expect($from->fresh()->merged_into_id)->toBe($into->id);
    expect($w->fresh()->tags->pluck('id')->all())->toBe([$into->id]);
});

test('merge guards', function (): void {
    $a = Tag::create(['type' => 'circle', 'value' => 'A']);
    $diff = Tag::create(['type' => 'parody', 'value' => 'P']);

    $this->postJson("/api/v1/tags/{$a->id}/merge", ['into_id' => $a->id], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/tags/{$a->id}/merge", ['into_id' => $diff->id], $this->h)->assertStatus(422);
});

// ---- Series ----

function detect(): void
{
    app(SeriesDetectorContract::class)->detect();
}

test('group creates a locked manual series that survives redetect', function (): void {
    $m = Mangaka::factory()->create();
    $a = Work::factory()->for($m)->create(['title' => '四畳半物語', 'sort_title' => '1']);
    $b = Work::factory()->for($m)->create(['title' => '四畳半物語 二畳目', 'sort_title' => '2']);

    $res = $this->postJson('/api/v1/series', [
        'work_ids' => [$a->id, $b->id], 'name' => '四畳半物語',
    ], $this->h)->assertStatus(201);

    $seriesId = $res->json('data.id');
    expect($a->fresh()->series_id)->toBe($seriesId);
    expect($a->fresh()->series_locked)->toBeTrue();

    detect();
    expect(Series::find($seriesId))->not->toBeNull();
    expect($a->fresh()->series_id)->toBe($seriesId);
});

test('group rejects works spanning multiple mangaka', function (): void {
    $a = Work::factory()->for(Mangaka::factory())->create();
    $b = Work::factory()->for(Mangaka::factory())->create();

    $this->postJson('/api/v1/series', ['work_ids' => [$a->id, $b->id], 'name' => 'X'], $this->h)
        ->assertStatus(422);
});

test('add works to an existing series locks them', function (): void {
    $m = Mangaka::factory()->create();
    $series = Series::factory()->for($m)->create(['is_auto' => true]);
    $w = Work::factory()->for($m)->create();

    $this->postJson("/api/v1/series/{$series->id}/works", ['work_ids' => [$w->id]], $this->h)->assertOk();

    expect($w->fresh()->series_id)->toBe($series->id);
    expect($w->fresh()->series_locked)->toBeTrue();
    expect($series->fresh()->is_auto)->toBeFalse();
});

test('ungroup removes works from their series', function (): void {
    $m = Mangaka::factory()->create();
    $series = Series::factory()->for($m)->create();
    $w = Work::factory()->for($m)->create(['series_id' => $series->id]);

    $this->deleteJson('/api/v1/series/works', ['work_ids' => [$w->id]], $this->h)->assertOk();

    expect($w->fresh()->series_id)->toBeNull();
    expect($w->fresh()->series_locked)->toBeTrue();
});

test('rename a series', function (): void {
    $m = Mangaka::factory()->create();
    $series = Series::factory()->for($m)->create(['name' => 'Old', 'is_auto' => true]);
    Work::factory()->for($m)->create(['series_id' => $series->id]);

    $this->patchJson("/api/v1/series/{$series->id}", ['name' => 'New'], $this->h)
        ->assertOk()->assertJsonPath('data.name', 'New');

    expect($series->fresh()->is_auto)->toBeFalse();
});
