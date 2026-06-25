<?php

namespace App\Actions\Tags;

use App\Models\Work;

/** Detach a tag the work actually has, locking it from re-derivation. / 紐付くタグのみ外しロック。 */
final class DetachWorkTag
{
    public function handle(Work $work, int $tagId): void
    {
        // Only detach a tag the work actually has — a foreign id must not flip the lock. / 紐付くタグのみ。
        abort_unless($work->tags()->where('tags.id', $tagId)->exists(), 422, 'Tag is not on this work.');

        $work->tags()->detach($tagId);
        $work->update(['tags_locked' => true]);
    }
}
