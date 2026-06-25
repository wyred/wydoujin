<?php

use App\Models\Mangaka;
use App\Models\Tag;
use App\Models\Work;

beforeEach(function (): void {
    config(['app.api_token' => 'secret']);
});

test('index returns paginated work resources with grouped tags', function (): void {
    $m = Mangaka::factory()->create(['name' => 'Z.A.P.', 'slug' => 'z-a-p']);
    $work = Work::factory()->for($m)->create(['title' => '四畳半物語', 'tags_locked' => true]);
    $tag = Tag::create(['type' => 'circle', 'value' => 'Z.A.P.']);
    $work->tags()->attach($tag->id);

    $res = $this->getJson('/api/v1/works', ['Authorization' => 'Bearer secret'])->assertOk();

    $res->assertJsonStructure([
        'data' => [[
            'id', 'content_hash', 'title', 'title_raw', 'page_count',
            'is_missing', 'tags_locked', 'series_locked',
            'mangaka' => ['id', 'name', 'slug'],
            'tags',
        ]],
        'meta' => ['current_page', 'per_page', 'total'],
    ]);
    $res->assertJsonPath('data.0.tags_locked', true);
    $res->assertJsonPath('data.0.tags.circle.0.value', 'Z.A.P.');
});

test('q filters works by title', function (): void {
    $m = Mangaka::factory()->create();
    Work::factory()->for($m)->create(['title' => 'Alpha', 'title_raw' => 'Alpha']);
    Work::factory()->for($m)->create(['title' => 'Beta', 'title_raw' => 'Beta']);

    $this->getJson('/api/v1/works?q=Alph', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Alpha');
});

test('per_page is clamped to 100', function (): void {
    $this->getJson('/api/v1/works?per_page=500', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});
