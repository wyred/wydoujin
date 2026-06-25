<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\Work;

/** Attach a (type,value) tag to a work, locking it from re-derivation. / 作品にタグを付与しロック。 */
final class AttachWorkTag
{
    public function handle(Work $work, string $type, string $value): int
    {
        $value = trim($value);
        abort_if($value === '', 422, 'Value is required.');

        $canonicalId = Tag::canonicalIdFor($type, $value);
        $work->tags()->syncWithoutDetaching([$canonicalId]);
        $work->update(['tags_locked' => true]);

        return $canonicalId;
    }
}
