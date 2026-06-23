<?php

use App\Models\Tag;
use App\Models\Work;
use Tests\Concerns\SeedsTags;

uses(SeedsTags::class);

test('attach creates links and locks', function (): void {
    $work = Work::factory()->create();

    $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'theme', 'value' => 'netorare'])
        ->assertStatus(201);

    $tag = Tag::where('type', 'theme')->where('value', 'netorare')->firstOrFail();
    $this->assertTrue($work->fresh()->tags->contains($tag));
    $this->assertTrue($work->fresh()->tags_locked);
});

test('attach resolves alias to canonical', function (): void {
    $work = Work::factory()->create();
    $canon = Tag::create(['type' => 'parody', 'value' => 'Fate/Grand Order']);
    Tag::create(['type' => 'parody', 'value' => 'FGO', 'merged_into_id' => $canon->id]);

    $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'parody', 'value' => 'FGO'])->assertStatus(201);

    $this->assertSame([$canon->id], $work->fresh()->tags->pluck('id')->all());
});

test('attach validates type and value', function (): void {
    $work = Work::factory()->create();
    $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'bogus', 'value' => 'x'])->assertStatus(422);
    $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'circle', 'value' => '  '])->assertStatus(422);
});

test('detach removes and locks', function (): void {
    $work = Work::factory()->create();
    $tag = $this->attachTag($work, 'circle', 'A');

    $this->postJson('/work/'.$work->id.'/tags/detach', ['tag_id' => $tag->id])->assertOk();

    $this->assertFalse($work->fresh()->tags->contains($tag));
    $this->assertTrue($work->fresh()->tags_locked);
});

test('reset unlocks and rederives from filename', function (): void {
    // filename parses to circle "Z.A.P." + title; a stray manual tag is wiped on reset.
    $work = Work::factory()->create(['filename' => '[Z.A.P.] Title.zip', 'tags_locked' => true]);
    $this->attachTag($work, 'theme', 'stray');

    $this->postJson('/work/'.$work->id.'/tags/reset')->assertOk();

    $work->refresh();
    $this->assertFalse($work->tags_locked);
    $this->assertSame([['circle', 'Z.A.P.']], $work->tags->map(fn (Tag $t) => [$t->type, $t->value])->all());
});

test('suggest returns matching canonical values', function (): void {
    $w = Work::factory()->create();
    $this->attachTag($w, 'circle', 'Zucchini');
    $this->attachTag($w, 'circle', 'Zenith');
    $this->attachTag($w, 'parody', 'Other');

    $this->getJson('/tags/suggest?type=circle&q=Zu')->assertOk()->assertExactJson(['Zucchini']);
});

test('detach rejects a tag not on the work', function (): void {
    $work = Work::factory()->create();
    $this->attachTag($work, 'circle', 'A');
    $foreign = Tag::create(['type' => 'circle', 'value' => 'Foreign']); // not attached to $work

    $this->postJson('/work/'.$work->id.'/tags/detach', ['tag_id' => $foreign->id])->assertStatus(422);

    $this->assertFalse($work->fresh()->tags_locked); // a no-op detach must not flip the lock
});
