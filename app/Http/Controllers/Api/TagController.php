<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tags\MergeTag;
use App\Actions\Tags\RenameTag;
use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Request;

/** Read + organize endpoints for the canonical tag vocabulary. / タグ取得・整理API。 */
final class TagController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', Tag::TYPES)],
        ]);

        $q = trim((string) $request->query('q', ''));

        $tags = Tag::query()->canonical()
            ->whereHas('works')
            ->withCount('works')
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->query('type')))
            ->when($q !== '', function ($query) use ($q): void {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->whereRaw("value LIKE ? ESCAPE '!'", [$term]);
            })
            ->orderBy('type')->orderBy('sort_value')
            ->paginate(100);

        return TagResource::collection($tags);
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
