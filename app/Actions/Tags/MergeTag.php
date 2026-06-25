<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Merge one tag into another: repoint works, tombstone the source, flatten any
 * inbound aliases so chains stay one hop. Guards (same type, target canonical,
 * from≠into) are the caller's responsibility. / タグ統合（原子的）。
 */
final class MergeTag
{
    public function handle(Tag $from, Tag $into): void
    {
        // Atomic: repoint $from's works to $into, tombstone $from, flatten chains. / 統合は原子的に。
        DB::transaction(function () use ($from, $into): void {
            $workIds = $from->works()->pluck('works.id')->all();
            $into->works()->syncWithoutDetaching($workIds);
            $from->works()->detach();
            $from->update(['merged_into_id' => $into->id]);
            Tag::where('merged_into_id', $from->id)->update(['merged_into_id' => $into->id]);
        });
    }
}
