<?php

use App\Models\Mangaka;
use App\Models\Tag;
use App\Models\Work;
use App\Tagging\WorkTagSync;

beforeEach(function (): void {
    config(['app.api_token' => 'secret']);
    $this->h = ['Authorization' => 'Bearer secret'];
});

test('attach creates link, locks, and returns the work', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create();

    $this->postJson("/api/v1/works/{$work->id}/tags", ['type' => 'theme', 'value' => 'netorare'], $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.tags.theme.0.value', 'netorare')
        ->assertJsonPath('data.tags_locked', true);

    expect($work->fresh()->tags_locked)->toBeTrue();
});

test('attach resolves alias to canonical', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create();
    $canon = Tag::create(['type' => 'parody', 'value' => 'Fate/Grand Order']);
    Tag::create(['type' => 'parody', 'value' => 'FGO', 'merged_into_id' => $canon->id]);

    $this->postJson("/api/v1/works/{$work->id}/tags", ['type' => 'parody', 'value' => 'FGO'], $this->h)
        ->assertStatus(201);

    expect($work->fresh()->tags->pluck('id')->all())->toBe([$canon->id]);
});

test('attach rejects an invalid type', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create();
    $this->postJson("/api/v1/works/{$work->id}/tags", ['type' => 'bogus', 'value' => 'x'], $this->h)
        ->assertStatus(422);
});

test('replace syncs the exact tag set and locks', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create();
    $work->tags()->attach(Tag::create(['type' => 'flag', 'value' => 'old'])->id);

    $this->putJson("/api/v1/works/{$work->id}/tags", [
        'tags' => [
            ['type' => 'circle', 'value' => 'Zap'],
            ['type' => 'flag', 'value' => 'DL版'],
        ],
    ], $this->h)->assertOk();

    $work->refresh();
    expect($work->tags->pluck('value')->sort()->values()->all())->toBe(['DL版', 'Zap']);
    expect($work->tags_locked)->toBeTrue();
});

test('replace with empty array clears tags but still locks', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create();
    $work->tags()->attach(Tag::create(['type' => 'flag', 'value' => 'x'])->id);

    $this->putJson("/api/v1/works/{$work->id}/tags", ['tags' => []], $this->h)->assertOk();

    $work->refresh();
    expect($work->tags)->toHaveCount(0);
    expect($work->tags_locked)->toBeTrue();
});

test('detach by tag_id removes the link and locks', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create();
    $tag = Tag::create(['type' => 'circle', 'value' => 'C']);
    $work->tags()->attach($tag->id);

    $this->deleteJson("/api/v1/works/{$work->id}/tags", ['tag_id' => $tag->id], $this->h)->assertOk();

    expect($work->fresh()->tags)->toHaveCount(0);
    expect($work->fresh()->tags_locked)->toBeTrue();
});

test('detach by type and value', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create();
    $tag = Tag::create(['type' => 'circle', 'value' => 'C']);
    $work->tags()->attach($tag->id);

    $this->deleteJson("/api/v1/works/{$work->id}/tags", ['type' => 'circle', 'value' => 'C'], $this->h)->assertOk();

    expect($work->fresh()->tags)->toHaveCount(0);
});

test('detach of a tag the work lacks returns 422', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create();
    $tag = Tag::create(['type' => 'circle', 'value' => 'C']);

    $this->deleteJson("/api/v1/works/{$work->id}/tags", ['tag_id' => $tag->id], $this->h)->assertStatus(422);
});

test('detach without tag_id or type+value returns 422', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create();
    $this->deleteJson("/api/v1/works/{$work->id}/tags", [], $this->h)->assertStatus(422);
});

test('reset unlocks and re-derives from the filename', function (): void {
    $m = Mangaka::factory()->create(['name' => 'Z.A.P.']);
    $work = Work::factory()->for($m)->create([
        'filename' => '(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版].zip',
        'tags_locked' => true,
    ]);
    $work->tags()->attach(Tag::create(['type' => 'theme', 'value' => 'manual'])->id);

    $this->postJson("/api/v1/works/{$work->id}/tags/reset", [], $this->h)->assertOk();

    $work->refresh();
    expect($work->tags_locked)->toBeFalse();
    expect($work->tags->where('type', 'circle')->pluck('value')->all())->toContain('Z.A.P.');
    expect($work->tags->where('type', 'theme')->pluck('value')->all())->not->toContain('manual');
});

test('api tag edits survive a rescan (locked work skipped)', function (): void {
    $m = Mangaka::factory()->create(['name' => 'Z.A.P.']);
    $work = Work::factory()->for($m)->create([
        'filename' => '(C89) [Z.A.P.] Title.zip',
    ]);

    $this->postJson("/api/v1/works/{$work->id}/tags", ['type' => 'theme', 'value' => 'keepme'], $this->h)
        ->assertStatus(201);

    app(WorkTagSync::class)->sync($work->fresh()); // a rescan must not touch a locked work

    expect($work->fresh()->tags->where('type', 'theme')->pluck('value')->all())->toContain('keepme');
});

test('bulk attach tags many works transactionally and locks each', function (): void {
    $m = Mangaka::factory()->create();
    $a = Work::factory()->for($m)->create();
    $b = Work::factory()->for($m)->create();

    $this->postJson('/api/v1/tags/attach', [
        'type' => 'theme', 'value' => 'shared', 'work_ids' => [$a->id, $b->id],
    ], $this->h)->assertOk()->assertJsonPath('count', 2);

    foreach ([$a, $b] as $w) {
        expect($w->fresh()->tags->pluck('value')->all())->toContain('shared');
        expect($w->fresh()->tags_locked)->toBeTrue();
    }
});

test('bulk attach with an unknown work id aborts whole batch (422)', function (): void {
    $m = Mangaka::factory()->create();
    $a = Work::factory()->for($m)->create();

    $this->postJson('/api/v1/tags/attach', [
        'type' => 'theme', 'value' => 'x', 'work_ids' => [$a->id, 999999],
    ], $this->h)->assertStatus(422);

    expect($a->fresh()->tags)->toHaveCount(0); // nothing applied
});

test('bulk detach is lenient and only touches works that carry the tag', function (): void {
    $m = Mangaka::factory()->create();
    $tag = Tag::create(['type' => 'theme', 'value' => 'shared']);
    $has = Work::factory()->for($m)->create();
    $has->tags()->attach($tag->id);
    $lacks = Work::factory()->for($m)->create();

    $this->postJson('/api/v1/tags/detach', [
        'type' => 'theme', 'value' => 'shared', 'work_ids' => [$has->id, $lacks->id],
    ], $this->h)->assertOk();

    expect($has->fresh()->tags)->toHaveCount(0);
    expect($has->fresh()->tags_locked)->toBeTrue();
    expect($lacks->fresh()->tags_locked)->toBeFalse(); // untouched
});
