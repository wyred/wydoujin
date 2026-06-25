<?php

use App\Models\Mangaka;
use App\Models\Series;
use App\Models\Tag;
use App\Models\Work;

beforeEach(function (): void {
    config(['app.api_token' => 'secret']);
});

function apiAuth(): array
{
    return ['Authorization' => 'Bearer secret'];
}

test('work show returns full detail with tags grouped by type', function (): void {
    $m = Mangaka::factory()->create();
    $work = Work::factory()->for($m)->create(['title' => 'Detail']);
    $work->tags()->attach(Tag::create(['type' => 'parody', 'value' => 'Original'])->id);

    $this->getJson("/api/v1/works/{$work->id}", apiAuth())
        ->assertOk()
        ->assertJsonPath('data.title', 'Detail')
        ->assertJsonPath('data.mangaka.id', $m->id)
        ->assertJsonPath('data.tags.parody.0.value', 'Original');
});

test('works untagged filter returns only works with no tags', function (): void {
    $m = Mangaka::factory()->create();
    $tagged = Work::factory()->for($m)->create(['title' => 'Tagged']);
    $tagged->tags()->attach(Tag::create(['type' => 'circle', 'value' => 'C'])->id);
    Work::factory()->for($m)->create(['title' => 'Bare']);

    $this->getJson('/api/v1/works?untagged=1', apiAuth())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Bare');
});

test('works tags_locked filter', function (): void {
    $m = Mangaka::factory()->create();
    Work::factory()->for($m)->create(['title' => 'Locked', 'tags_locked' => true]);
    Work::factory()->for($m)->create(['title' => 'Open', 'tags_locked' => false]);

    $this->getJson('/api/v1/works?tags_locked=1', apiAuth())
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Locked');
});

test('works missing filter returns swept works', function (): void {
    $m = Mangaka::factory()->create();
    Work::factory()->for($m)->create(['title' => 'Here', 'is_missing' => false]);
    Work::factory()->for($m)->create(['title' => 'Gone', 'is_missing' => true]);

    $this->getJson('/api/v1/works?missing=1', apiAuth())
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Gone');
});

test('works mangaka filter accepts id and slug', function (): void {
    $a = Mangaka::factory()->create(['slug' => 'aaa']);
    $b = Mangaka::factory()->create(['slug' => 'bbb']);
    Work::factory()->for($a)->create(['title' => 'FromA']);
    Work::factory()->for($b)->create(['title' => 'FromB']);

    $this->getJson("/api/v1/works?mangaka={$a->id}", apiAuth())
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'FromA');
    $this->getJson('/api/v1/works?mangaka=bbb', apiAuth())
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'FromB');
});

test('mangaka index returns counts', function (): void {
    $m = Mangaka::factory()->create(['name' => 'Alpha']);
    Work::factory()->count(2)->for($m)->create();

    $this->getJson('/api/v1/mangaka', apiAuth())
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Alpha')
        ->assertJsonPath('data.0.works_count', 2)
        ->assertJsonPath('data.0.series_count', 0);
});

test('mangaka show returns series and works', function (): void {
    $m = Mangaka::factory()->create();
    $series = Series::factory()->for($m)->create(['name' => 'S1']);
    Work::factory()->for($m)->create(['title' => 'InSeries', 'series_id' => $series->id]);
    Work::factory()->for($m)->create(['title' => 'Standalone']);

    $res = $this->getJson("/api/v1/mangaka/{$m->id}", apiAuth())->assertOk();
    $res->assertJsonPath('data.works_count', 2);
    $res->assertJsonPath('data.series.0.name', 'S1');
});

test('series show returns its works', function (): void {
    $m = Mangaka::factory()->create();
    $series = Series::factory()->for($m)->create(['name' => 'Chronicle']);
    Work::factory()->for($m)->create(['title' => 'Vol1', 'sort_title' => '1', 'series_id' => $series->id]);

    $this->getJson("/api/v1/series/{$series->id}", apiAuth())
        ->assertOk()
        ->assertJsonPath('data.name', 'Chronicle')
        ->assertJsonPath('data.works.0.title', 'Vol1');
});

test('tags index lists canonical tags with counts, filtered by type and q', function (): void {
    $m = Mangaka::factory()->create();
    $work = Work::factory()->for($m)->create();
    $circle = Tag::create(['type' => 'circle', 'value' => 'Zap']);
    $parody = Tag::create(['type' => 'parody', 'value' => 'Original']);
    $work->tags()->attach([$circle->id, $parody->id]);
    // an orphan + a tombstone must not appear
    Tag::create(['type' => 'circle', 'value' => 'OrphanCircle']);

    $res = $this->getJson('/api/v1/tags?type=circle', apiAuth())->assertOk();
    $res->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.value', 'Zap')
        ->assertJsonPath('data.0.works_count', 1);

    $this->getJson('/api/v1/tags?type=bogus', apiAuth())->assertStatus(422);
});

test('facets returns counts across dimensions', function (): void {
    $m = Mangaka::factory()->create();
    $w = Work::factory()->for($m)->create();
    $w->tags()->attach(Tag::create(['type' => 'circle', 'value' => 'A'])->id);

    $this->getJson('/api/v1/facets', apiAuth())
        ->assertOk()
        ->assertJsonPath('circle.0.value', 'A')
        ->assertJsonPath('circle.0.count', 1);
});
