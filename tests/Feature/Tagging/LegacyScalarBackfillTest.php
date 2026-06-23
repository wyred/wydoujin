<?php

use App\Models\Mangaka;
use App\Models\Work;
use App\Tagging\LegacyScalarBackfill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Add the legacy scalar columns before any rows are created.
// RefreshDatabase + SQLite: DDL inside a transaction works fine when run before INSERT.
// / RefreshDatabase + SQLite：INSERT前ならトランザクション内DDLは問題なし。
beforeEach(function (): void {
    Schema::table('works', function ($t): void {
        $t->string('circle')->nullable();
        $t->string('parody')->nullable();
        $t->string('event')->nullable();
        $t->string('author')->nullable();
        $t->string('language')->nullable();
        $t->json('flags')->nullable();
    });
});

test('backfill creates tags from all legacy scalar columns and flags', function (): void {
    $work = Work::factory()->create();
    DB::table('works')->where('id', $work->id)->update([
        'circle'   => 'Z.A.P.',
        'parody'   => 'オリジナル',
        'event'    => 'C89',
        'author'   => 'ズッキーニ',
        'language' => 'ja',                           // excluded — no language tag expected
        'flags'    => json_encode(['DL版', 'pixiv']),
    ]);

    (new LegacyScalarBackfill())->run();

    $tags = $work->tags()->get()->map(fn ($t) => [$t->type, $t->value])->all();
    // language is intentionally excluded from backfill / languageはバックフィル対象外。
    $this->assertEqualsCanonicalizing([
        ['circle', 'Z.A.P.'],
        ['parody', 'オリジナル'],
        ['event', 'C89'],
        ['author', 'ズッキーニ'],
        ['flag', 'DL版'],
        ['flag', 'pixiv'],
    ], $tags);
});

test('backfill is idempotent — running twice yields no duplicate tags or pivots', function (): void {
    $work = Work::factory()->create();
    DB::table('works')->where('id', $work->id)->update([
        'circle' => 'MyCircle',
        'flags'  => json_encode(['DL版']),
    ]);

    (new LegacyScalarBackfill())->run();
    (new LegacyScalarBackfill())->run();

    $tagCount = $work->tags()->count();
    $this->assertSame(2, $tagCount); // circle + flag, no duplicates / サークル+フラグ、重複なし
    $pivotCount = DB::table('work_tag')->where('work_id', $work->id)->count();
    $this->assertSame(2, $pivotCount);
});

test('backfill skips empty and null scalar values', function (): void {
    $work = Work::factory()->create();
    DB::table('works')->where('id', $work->id)->update([
        'circle' => '',     // empty → skip
        'parody' => null,   // null → skip
        'flags'  => json_encode([]),
    ]);

    (new LegacyScalarBackfill())->run();

    $this->assertSame(0, $work->tags()->count());
});
