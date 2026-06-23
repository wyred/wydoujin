<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\Work;
use App\Tagging\WorkTagSync;
use Illuminate\Http\Request;

/**
 * Per-work manual tag editing (F4). Every edit sets tags_locked so the scanner
 * won't re-derive the work; reset clears it and re-derives. / 作品別タグ編集。
 */
final class WorkTagController extends Controller
{
    public function attach(Request $request, Work $work)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', Tag::TYPES)],
            'value' => ['required', 'string', 'max:255'],
        ]);
        $value = trim($data['value']);
        abort_if($value === '', 422, 'Value is required.');

        $tag = Tag::firstOrCreate(['type' => $data['type'], 'value' => $value]);
        $canonicalId = (int) ($tag->merged_into_id ?? $tag->id);
        $work->tags()->syncWithoutDetaching([$canonicalId]);
        $work->update(['tags_locked' => true]);

        return response()->json(['ok' => true, 'tag_id' => $canonicalId], 201);
    }

    public function detach(Request $request, Work $work)
    {
        $data = $request->validate(['tag_id' => ['required', 'integer']]);
        // Only detach a tag the work actually has — a foreign id must not flip the lock. / 紐付くタグのみ。
        abort_unless($work->tags()->where('tags.id', $data['tag_id'])->exists(), 422, 'Tag is not on this work.');
        $work->tags()->detach($data['tag_id']);
        $work->update(['tags_locked' => true]);

        return response()->json(['ok' => true]);
    }

    public function reset(Work $work, WorkTagSync $sync)
    {
        $work->update(['tags_locked' => false]);
        $sync->sync($work); // re-derive from the filename / ファイル名から再導出
        $work->load('tags');

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
