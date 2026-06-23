<?php

namespace Tests\Concerns;

use App\Models\Tag;
use App\Models\Work;

trait SeedsTags
{
    /** Attach a (type,value) tag to a work, creating the tag if needed. / タグ付与。 */
    protected function attachTag(Work $work, string $type, string $value): Tag
    {
        $tag = Tag::firstOrCreate(['type' => $type, 'value' => $value]);
        $work->tags()->syncWithoutDetaching([$tag->id]);

        return $tag;
    }
}
