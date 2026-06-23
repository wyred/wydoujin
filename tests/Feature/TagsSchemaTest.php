<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SeedsTags;
use Tests\TestCase;

class TagsSchemaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTags;

    public function test_tables_and_column_exist(): void
    {
        $this->assertTrue(Schema::hasTable('tags'));
        $this->assertTrue(Schema::hasTable('work_tag'));
        $this->assertTrue(Schema::hasColumns('tags', ['type', 'value', 'sort_value', 'merged_into_id']));
        $this->assertTrue(Schema::hasColumn('works', 'tags_locked'));
    }

    public function test_creating_a_tag_autofills_sort_value(): void
    {
        $tag = Tag::create(['type' => 'circle', 'value' => '【Z.A.P.】']);
        $this->assertSame('Z.A.P.】', $tag->sort_value); // leading bracket stripped
    }

    public function test_work_tags_relation_and_canonical_scope(): void
    {
        $work = Work::factory()->create();
        $canon = $this->attachTag($work, 'circle', 'Z.A.P.');
        $alias = Tag::create(['type' => 'circle', 'value' => 'ZAP', 'merged_into_id' => $canon->id]);

        $this->assertTrue($work->tags->contains($canon));
        $this->assertSame([$canon->id], Tag::canonical()->pluck('id')->all());
        $this->assertSame($canon->id, $alias->mergedInto->id);
        $this->assertTrue($canon->aliases->contains($alias));
    }

    public function test_browse_url_encodes_type_and_value(): void
    {
        $tag = Tag::create(['type' => 'parody', 'value' => 'Fate/Grand Order']);
        $this->assertSame('/browse?'.http_build_query(['parody' => ['Fate/Grand Order']]), $tag->browseUrl());
    }
}
