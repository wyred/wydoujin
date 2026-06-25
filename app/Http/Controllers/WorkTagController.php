<?php

namespace App\Http\Controllers;

use App\Actions\Tags\AttachWorkTag;
use App\Actions\Tags\DetachWorkTag;
use App\Actions\Tags\ResetWorkTags;
use App\Models\Tag;
use App\Models\Work;
use Illuminate\Http\Request;

/**
 * Per-work manual tag editing (F4). Every edit sets tags_locked so the scanner
 * won't re-derive the work; reset clears it and re-derives. / 作品別タグ編集。
 */
final class WorkTagController extends Controller
{
    public function attach(Request $request, Work $work, AttachWorkTag $action)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', Tag::TYPES)],
            'value' => ['required', 'string', 'max:255'],
        ]);

        $canonicalId = $action->handle($work, $data['type'], $data['value']);

        return response()->json(['ok' => true, 'tag_id' => $canonicalId], 201);
    }

    public function detach(Request $request, Work $work, DetachWorkTag $action)
    {
        $data = $request->validate(['tag_id' => ['required', 'integer']]);
        $action->handle($work, (int) $data['tag_id']);

        return response()->json(['ok' => true]);
    }

    public function reset(Work $work, ResetWorkTags $action)
    {
        $action->handle($work);
        $work->load('tags'); // re-derived from the filename / ファイル名から再導出

        return response()->json(['ok' => true]);
    }

    public function suggest(Request $request)
    {
        $type = (string) $request->query('type', '');
        abort_unless(in_array($type, Tag::TYPES, true), 422);
        $q = trim((string) $request->query('q', ''));

        $values = Tag::query()->canonical()->where('type', $type)
            ->when($q !== '', function ($query) use ($q): void {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->whereRaw("value LIKE ? ESCAPE '!'", [$term]);
            })
            ->orderBy('sort_value')->limit(10)->pluck('value');

        return response()->json($values);
    }
}
