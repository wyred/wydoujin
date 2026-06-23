<?php

namespace Tests\Feature\Tagging;

use App\Models\Tag;
use App\Models\Work;
use App\Tagging\LegacyScalarBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyScalarBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_tags_and_pivots_from_scalar_columns(): void
    {
        $work = Work::factory()->create();
        // Set the legacy columns directly (still present pre-drop). / 旧カラムを直接設定。
        DB::table('works')->where('id', $work->id)->update([
            'circle' => 'Z.A.P.', 'parody' => 'オリジナル', 'event' => 'C89',
            'author' => 'ズッキーニ', 'flags' => json_encode(['DL版', 'pixiv']),
        ]);

        (new LegacyScalarBackfill())->run();

        $this->assertEqualsCanonicalizing([
            ['circle', 'Z.A.P.'], ['parody', 'オリジナル'], ['event', 'C89'],
            ['author', 'ズッキーニ'], ['flag', 'DL版'], ['flag', 'pixiv'],
        ], $work->fresh()->tags->map(fn (Tag $t) => [$t->type, $t->value])->all());
    }

    public function test_is_idempotent(): void
    {
        $work = Work::factory()->create();
        DB::table('works')->where('id', $work->id)->update(['circle' => 'A']);

        (new LegacyScalarBackfill())->run();
        (new LegacyScalarBackfill())->run();

        $this->assertSame(1, Tag::count());
        $this->assertSame(1, $work->fresh()->tags()->count());
    }
}
