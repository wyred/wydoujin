<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Support\SortKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Global tag management — rename / merge (F4). Both write a merge-alias so the
 * scanner permanently normalizes the old raw value. / タグ管理（リネーム/統合）。
 */
final class TagController extends Controller
{
    public function index()
    {
        $tagsByType = Tag::query()->canonical()
            ->whereHas('works') // hide orphan tags with no works (cleaned by the scan's pruneOrphans) / 孤立タグは隠す
            ->withCount('works')
            ->orderBy('type')->orderBy('sort_value')
            ->get()
            ->groupBy('type');

        return view('tags.index', compact('tagsByType'));
    }

    public function rename(Request $request, Tag $tag)
    {
        abort_if($tag->merged_into_id !== null, 422, 'Tag is an alias.');
        $data = $request->validate(['value' => ['required', 'string', 'max:255']]);
        $value = trim($data['value']);
        abort_if($value === '', 422, 'Value is required.');
        if ($value === $tag->value) {
            return response()->json(['ok' => true]);
        }

        $existing = Tag::query()->canonical()->where('type', $tag->type)->where('value', $value)->first();
        if ($existing !== null) {
            return $this->mergeInto($tag, $existing);
        }

        $old = $tag->value;
        $tag->update(['value' => $value, 'sort_value' => SortKey::derive($value)]);
        // Tombstone the old value so re-derivation normalizes to the renamed tag. / 旧値を別名化。
        Tag::create(['type' => $tag->type, 'value' => $old, 'merged_into_id' => $tag->id]);

        return response()->json(['ok' => true]);
    }

    public function merge(Request $request, Tag $tag)
    {
        $data = $request->validate(['into_id' => ['required', 'integer']]);
        $into = Tag::findOrFail($data['into_id']);
        abort_if($into->id === $tag->id, 422, 'Cannot merge a tag into itself.');
        abort_if($into->type !== $tag->type, 422, 'Tags are different types.');
        abort_if($into->merged_into_id !== null, 422, 'Target is an alias.');

        return $this->mergeInto($tag, $into);
    }

    /** Repoint $from's works to $into, tombstone $from, flatten chains. / 統合本体。 */
    private function mergeInto(Tag $from, Tag $into)
    {
        // Atomic: repoint $from's works to $into, tombstone $from, flatten chains. / 統合は原子的に。
        DB::transaction(function () use ($from, $into): void {
            $workIds = $from->works()->pluck('works.id')->all();
            $into->works()->syncWithoutDetaching($workIds);
            $from->works()->detach();
            $from->update(['merged_into_id' => $into->id]);
            Tag::where('merged_into_id', $from->id)->update(['merged_into_id' => $into->id]);
        });

        return response()->json(['ok' => true]);
    }
}
