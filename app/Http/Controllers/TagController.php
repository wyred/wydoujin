<?php

namespace App\Http\Controllers;

use App\Actions\Tags\MergeTag;
use App\Actions\Tags\RenameTag;
use App\Models\Tag;
use Illuminate\Http\Request;

/**
 * Global tag management — rename / merge (F4). Both write a merge-alias so the
 * scanner permanently normalizes the old raw value. / タグ管理（リネーム/統合）。
 */
final class TagController extends Controller
{
    public function index()
    {
        // Paginate (100/page) so libraries with thousands of tags load fast.
        // Ordering by type keeps each page mostly within one type group. / ページング。
        $tags = Tag::query()->canonical()
            ->whereHas('works') // hide orphan tags with no works (cleaned by the scan's pruneOrphans) / 孤立タグは隠す
            ->withCount('works')
            ->orderBy('type')->orderBy('sort_value')
            ->paginate(100)
            ->withQueryString();

        $tagsByType = $tags->getCollection()->groupBy('type');

        return view('tags.index', compact('tags', 'tagsByType'));
    }

    public function rename(Request $request, Tag $tag, RenameTag $action)
    {
        $data = $request->validate(['value' => ['required', 'string', 'max:255']]);
        $action->handle($tag, $data['value']);

        return response()->json(['ok' => true]);
    }

    public function merge(Request $request, Tag $tag, MergeTag $action)
    {
        $data = $request->validate(['into_id' => ['required', 'integer']]);
        $into = Tag::findOrFail($data['into_id']);
        abort_if($into->id === $tag->id, 422, 'Cannot merge a tag into itself.');
        abort_if($into->type !== $tag->type, 422, 'Tags are different types.');
        abort_if($into->merged_into_id !== null, 422, 'Target is an alias.');

        $action->handle($tag, $into);

        return response()->json(['ok' => true]);
    }
}
