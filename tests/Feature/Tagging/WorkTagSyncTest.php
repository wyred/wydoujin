<?php

use App\Models\Tag;
use App\Models\Work;
use App\Parsing\ParsedName;
use App\Tagging\WorkTagSync;
use Tests\Concerns\SeedsTags;

uses(SeedsTags::class);

// File-local helper — uniquely named to avoid redeclare when the full suite runs.
// ファイルローカルヘルパー — スイート全体での再定義エラーを防ぐため一意な名前を使用。
function syncForWorkTagSync(): WorkTagSync
{
    return app(WorkTagSync::class);
}

test('derives one tag per field and one per flag', function (): void {
    $parsed = ParsedName::make('四畳半物語', 'raw', event: 'C89', circle: 'Z.A.P.', author: 'ズッキーニ', parody: 'オリジナル', flags: ['DL版', 'pixiv']);

    $pairs = syncForWorkTagSync()->derive($parsed);

    $this->assertEqualsCanonicalizing([
        ['circle', 'Z.A.P.'], ['parody', 'オリジナル'], ['event', 'C89'],
        ['author', 'ズッキーニ'], ['flag', 'DL版'], ['flag', 'pixiv'],
    ], $pairs);
});

test('sync attaches canonical tags and dedupes', function (): void {
    $work = Work::factory()->create();
    $parsed = ParsedName::make('t', 'raw', circle: 'A', parody: 'P');

    syncForWorkTagSync()->sync($work, $parsed);

    $this->assertEqualsCanonicalizing(
        [['circle', 'A'], ['parody', 'P']],
        $work->tags()->get()->map(fn (Tag $t) => [$t->type, $t->value])->all(),
    );
});

test('sync resolves merge alias to canonical', function (): void {
    $work = Work::factory()->create();
    $canon = Tag::create(['type' => 'parody', 'value' => 'Fate/Grand Order']);
    Tag::create(['type' => 'parody', 'value' => 'FGO', 'merged_into_id' => $canon->id]);

    // The parser still produces the raw "FGO"; sync must attach the canonical.
    syncForWorkTagSync()->sync($work, ParsedName::make('t', 'raw', parody: 'FGO'));

    $this->assertSame([$canon->id], $work->tags()->pluck('tags.id')->all());
});

test('sync skips locked works', function (): void {
    $work = Work::factory()->create(['tags_locked' => true]);
    $this->attachTag($work, 'theme', 'manual-only');

    syncForWorkTagSync()->sync($work, ParsedName::make('t', 'raw', circle: 'A'));

    $this->assertSame([['theme', 'manual-only']], $work->tags()->get()->map(fn (Tag $t) => [$t->type, $t->value])->all());
});

test('prune orphans removes only unused non alias non target', function (): void {
    $work = Work::factory()->create();
    $used = $this->attachTag($work, 'circle', 'used');
    $orphan = Tag::create(['type' => 'circle', 'value' => 'orphan']);
    $target = Tag::create(['type' => 'circle', 'value' => 'target']);
    Tag::create(['type' => 'circle', 'value' => 'tombstone', 'merged_into_id' => $target->id]);

    $deleted = syncForWorkTagSync()->pruneOrphans();

    $this->assertSame(1, $deleted);
    $this->assertNotNull($used->fresh());
    $this->assertNull($orphan->fresh());      // unused canonical → pruned
    $this->assertNotNull($target->fresh());    // merge target → kept
});
