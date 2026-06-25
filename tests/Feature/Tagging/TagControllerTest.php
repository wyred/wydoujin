<?php

use App\Models\Tag;
use App\Models\Work;
use App\Parsing\ParsedName;
use App\Tagging\WorkTagSync;
use Tests\Concerns\SeedsTags;

uses(SeedsTags::class);

test('index lists canonical tags with counts', function (): void {
    $w = Work::factory()->create();
    $this->attachTag($w, 'circle', 'Z.A.P.');

    $this->get('/tags')->assertOk()->assertSee('Z.A.P.');
});

test('index paginates at 100 tags per page', function (): void {
    $w = Work::factory()->create();
    foreach (range(0, 149) as $i) {
        $this->attachTag($w, 'circle', sprintf('circle-%03d', $i));
    }

    $this->get('/tags')
        ->assertOk()
        ->assertSee('circle-099')   // last tag on page 1
        ->assertDontSee('circle-100') // spills to page 2
        ->assertSee('Next ›', false);

    $this->get('/tags?page=2')
        ->assertOk()
        ->assertSee('circle-149')
        ->assertDontSee('circle-099');
});

test('index hides orphan tags with no works', function (): void {
    $w = Work::factory()->create();
    $this->attachTag($w, 'circle', 'Used');
    Tag::create(['type' => 'circle', 'value' => 'Orphan']); // canonical but linked to no works

    $this->get('/tags')->assertOk()->assertSee('Used')->assertDontSee('Orphan');
});

test('rename in place creates tombstone that scanner resolves', function (): void {
    $w = Work::factory()->create();
    $tag = $this->attachTag($w, 'parody', 'FGO');

    $this->postJson('/tags/'.$tag->id.'/rename', ['value' => 'Fate/Grand Order'])->assertOk();

    $tag->refresh();
    $this->assertSame('Fate/Grand Order', $tag->value);
    // A tombstone for the old value now points at the renamed tag.
    $tombstone = Tag::where('type', 'parody')->where('value', 'FGO')->firstOrFail();
    $this->assertSame($tag->id, $tombstone->merged_into_id);

    // The scanner re-deriving the raw "FGO" resolves to the canonical tag.
    $other = Work::factory()->create();
    app(WorkTagSync::class)->sync($other, ParsedName::make('t', 'raw', parody: 'FGO'));
    $this->assertSame([$tag->id], $other->tags()->pluck('tags.id')->all());
});

test('rename onto existing value merges', function (): void {
    $w1 = Work::factory()->create(); $a = $this->attachTag($w1, 'circle', 'A');
    $w2 = Work::factory()->create(); $b = $this->attachTag($w2, 'circle', 'B');

    $this->postJson('/tags/'.$a->id.'/rename', ['value' => 'B'])->assertOk();

    $this->assertSame($b->id, $a->fresh()->merged_into_id); // A becomes an alias of B
    $this->assertTrue($w1->fresh()->tags->contains($b));
});

test('merge repoints dedupes and flattens', function (): void {
    $shared = Work::factory()->create();
    $onlyA = Work::factory()->create();
    $a = $this->attachTag($shared, 'circle', 'A'); $this->attachTag($onlyA, 'circle', 'A');
    $b = $this->attachTag($shared, 'circle', 'B'); // shared already has B → dedupe
    $c = Tag::create(['type' => 'circle', 'value' => 'C', 'merged_into_id' => $a->id]); // chain into A

    $this->postJson('/tags/'.$a->id.'/merge', ['into_id' => $b->id])->assertOk();

    $this->assertSame($b->id, $a->fresh()->merged_into_id);
    $this->assertSame($b->id, $c->fresh()->merged_into_id);        // chain flattened A→B
    $this->assertSame(0, $a->fresh()->works()->count());           // pivots moved off A
    $this->assertTrue($onlyA->fresh()->tags->contains($b));        // repointed
    $this->assertSame(1, $shared->fresh()->tags()->where('tags.id', $b->id)->count()); // deduped
});

// Line 35: renaming to the same value returns ok immediately, no tombstone created.
// / 35行目：同じ値へのリネームは即座にokを返し、トゥームストーンは作成されない。
test('rename to same value is a no-op', function (): void {
    $w = Work::factory()->create();
    $tag = $this->attachTag($w, 'circle', 'Z.A.P.');

    $this->postJson('/tags/'.$tag->id.'/rename', ['value' => 'Z.A.P.'])->assertOk();

    $tag->refresh();
    $this->assertSame('Z.A.P.', $tag->value); // unchanged
    $this->assertNull($tag->merged_into_id);
    // No tombstone created for "Z.A.P." → "Z.A.P.". / トゥームストーンは作成されない。
    $this->assertSame(0, Tag::where('type', 'circle')->where('merged_into_id', $tag->id)->count());
});

test('merge validates', function (): void {
    $w = Work::factory()->create();
    $a = $this->attachTag($w, 'circle', 'A');
    $p = $this->attachTag($w, 'parody', 'A');

    $this->postJson('/tags/'.$a->id.'/merge', ['into_id' => $a->id])->assertStatus(422); // into self
    $this->postJson('/tags/'.$a->id.'/merge', ['into_id' => $p->id])->assertStatus(422); // cross type

    // Merging INTO an alias (tombstone) must also be rejected.
    // エイリアス（トゥームストーン）タグへのマージも拒否されること。
    $aliasTag = Tag::create(['type' => 'circle', 'value' => 'AliasTarget', 'merged_into_id' => $a->id]);
    $this->postJson('/tags/'.$a->id.'/merge', ['into_id' => $aliasTag->id])->assertStatus(422); // into an alias
});
