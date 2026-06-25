<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Support\SortKey;

/**
 * Rename a canonical tag, leaving a merge-alias tombstone for the old value so
 * the scanner permanently normalizes it. Renaming onto an existing canonical
 * value reduces to a merge. / タグ改名（旧値は別名化、既存なら統合）。
 */
final class RenameTag
{
    public function __construct(private readonly MergeTag $merge) {}

    public function handle(Tag $tag, string $value): void
    {
        abort_if($tag->merged_into_id !== null, 422, 'Tag is an alias.');
        $value = trim($value);
        abort_if($value === '', 422, 'Value is required.');
        if ($value === $tag->value) {
            return;
        }

        $existing = Tag::query()->canonical()->where('type', $tag->type)->where('value', $value)->first();
        if ($existing !== null) {
            $this->merge->handle($tag, $existing);

            return;
        }

        $old = $tag->value;
        $tag->update(['value' => $value, 'sort_value' => SortKey::derive($value)]);
        // Tombstone the old value so re-derivation normalizes to the renamed tag. / 旧値を別名化。
        Tag::create(['type' => $tag->type, 'value' => $old, 'merged_into_id' => $tag->id]);
    }
}
