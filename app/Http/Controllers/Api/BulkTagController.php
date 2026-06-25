<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tags\AttachWorkTag;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Bulk tagging: attach/detach one (type,value) across many works. / 一括タグ付与・除去。 */
final class BulkTagController extends Controller
{
    public function attach(Request $request, AttachWorkTag $action)
    {
        [$type, $value, $ids] = $this->validated($request);

        DB::transaction(function () use ($ids, $type, $value, $action): void {
            Work::whereIn('id', $ids)->get()->each(fn (Work $work) => $action->handle($work, $type, $value));
        });

        return response()->json(['ok' => true, 'count' => count($ids)]);
    }

    public function detach(Request $request)
    {
        [$type, $value, $ids] = $this->validated($request);
        $tagId = Tag::canonicalIdFor($type, $value);

        DB::transaction(function () use ($ids, $tagId): void {
            // Lenient: only touch (and lock) works that actually carry the tag. / 持つ作品のみ外す。
            Work::whereIn('id', $ids)->get()->each(function (Work $work) use ($tagId): void {
                if ($work->tags()->where('tags.id', $tagId)->exists()) {
                    $work->tags()->detach($tagId);
                    $work->update(['tags_locked' => true]);
                }
            });
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Validate the bulk payload; every work id must exist (all-or-nothing). / 一括入力検証。
     *
     * @return array{0:string,1:string,2:list<int>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', Tag::TYPES)],
            'value' => ['required', 'string', 'max:255'],
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
        ]);

        $value = trim($data['value']);
        abort_if($value === '', 422, 'Value is required.');

        $ids = array_values(array_unique(array_map('intval', $data['work_ids'])));
        abort_if(Work::whereIn('id', $ids)->count() !== count($ids), 422, 'Unknown work in selection.');

        return [$data['type'], $value, $ids];
    }
}
