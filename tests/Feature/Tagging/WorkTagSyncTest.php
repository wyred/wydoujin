<?php

namespace Tests\Feature\Tagging;

use App\Models\Tag;
use App\Models\Work;
use App\Parsing\ParsedName;
use App\Tagging\WorkTagSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsTags;
use Tests\TestCase;

class WorkTagSyncTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTags;

    private function sync(): WorkTagSync
    {
        return app(WorkTagSync::class);
    }

    public function test_derives_one_tag_per_field_and_one_per_flag(): void
    {
        $parsed = ParsedName::make('四畳半物語', 'raw', event: 'C89', circle: 'Z.A.P.', author: 'ズッキーニ', parody: 'オリジナル', flags: ['DL版', 'pixiv']);

        $pairs = $this->sync()->derive($parsed);

        $this->assertEqualsCanonicalizing([
            ['circle', 'Z.A.P.'], ['parody', 'オリジナル'], ['event', 'C89'],
            ['author', 'ズッキーニ'], ['flag', 'DL版'], ['flag', 'pixiv'],
        ], $pairs);
    }

    public function test_sync_attaches_canonical_tags_and_dedupes(): void
    {
        $work = Work::factory()->create();
        $parsed = ParsedName::make('t', 'raw', circle: 'A', parody: 'P');

        $this->sync()->sync($work, $parsed);

        $this->assertEqualsCanonicalizing(
            [['circle', 'A'], ['parody', 'P']],
            $work->tags()->get()->map(fn (Tag $t) => [$t->type, $t->value])->all(),
        );
    }

    public function test_sync_resolves_merge_alias_to_canonical(): void
    {
        $work = Work::factory()->create();
        $canon = Tag::create(['type' => 'parody', 'value' => 'Fate/Grand Order']);
        Tag::create(['type' => 'parody', 'value' => 'FGO', 'merged_into_id' => $canon->id]);

        // The parser still produces the raw "FGO"; sync must attach the canonical.
        $this->sync()->sync($work, ParsedName::make('t', 'raw', parody: 'FGO'));

        $this->assertSame([$canon->id], $work->tags()->pluck('tags.id')->all());
    }

    public function test_sync_skips_locked_works(): void
    {
        $work = Work::factory()->create(['tags_locked' => true]);
        $this->attachTag($work, 'theme', 'manual-only');

        $this->sync()->sync($work, ParsedName::make('t', 'raw', circle: 'A'));

        $this->assertSame([['theme', 'manual-only']], $work->tags()->get()->map(fn (Tag $t) => [$t->type, $t->value])->all());
    }

    public function test_prune_orphans_removes_only_unused_non_alias_non_target(): void
    {
        $work = Work::factory()->create();
        $used = $this->attachTag($work, 'circle', 'used');
        $orphan = Tag::create(['type' => 'circle', 'value' => 'orphan']);
        $target = Tag::create(['type' => 'circle', 'value' => 'target']);
        Tag::create(['type' => 'circle', 'value' => 'tombstone', 'merged_into_id' => $target->id]);

        $deleted = $this->sync()->pruneOrphans();

        $this->assertSame(1, $deleted);
        $this->assertNotNull($used->fresh());
        $this->assertNull($orphan->fresh());      // unused canonical → pruned
        $this->assertNotNull($target->fresh());    // merge target → kept
    }
}
