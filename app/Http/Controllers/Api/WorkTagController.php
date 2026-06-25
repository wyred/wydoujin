<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tags\AttachWorkTag;
use App\Actions\Tags\DetachWorkTag;
use App\Actions\Tags\ResetWorkTags;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkResource;
use App\Models\Tag;
use App\Models\Work;
use Illuminate\Http\Request;

/** Per-work tag editing over the API. Every write returns the fresh work. / 作品タグ編集API。 */
final class WorkTagController extends Controller
{
    public function attach(Request $request, Work $work, AttachWorkTag $action)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', Tag::TYPES)],
            'value' => ['required', 'string', 'max:255'],
        ]);

        $action->handle($work, $data['type'], $data['value']);

        return $this->resource($work)->response()->setStatusCode(201);
    }

    /** Replace the work's entire tag set (empty array clears, still locks). / 全タグ置換。 */
    public function replace(Request $request, Work $work)
    {
        $data = $request->validate([
            'tags' => ['present', 'array'],
            'tags.*.type' => ['required', 'string', 'in:'.implode(',', Tag::TYPES)],
            'tags.*.value' => ['required', 'string', 'max:255'],
        ]);

        $ids = [];
        foreach ($data['tags'] as $tag) {
            $value = trim($tag['value']);
            abort_if($value === '', 422, 'Value is required.');
            $ids[] = Tag::canonicalIdFor($tag['type'], $value);
        }

        $work->tags()->sync(array_values(array_unique($ids)));
        $work->update(['tags_locked' => true]);

        return $this->resource($work);
    }

    public function detach(Request $request, Work $work, DetachWorkTag $action)
    {
        $data = $request->validate([
            'tag_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'in:'.implode(',', Tag::TYPES)],
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        $tagId = $data['tag_id'] ?? null;
        if ($tagId === null) {
            $value = trim((string) ($data['value'] ?? ''));
            abort_if(! isset($data['type']) || $value === '', 422, 'Provide tag_id or type and value.');
            $tagId = Tag::canonicalIdFor($data['type'], $value);
        }

        $action->handle($work, (int) $tagId);

        return $this->resource($work);
    }

    public function reset(Work $work, ResetWorkTags $action)
    {
        $action->handle($work);

        return $this->resource($work);
    }

    private function resource(Work $work): WorkResource
    {
        return new WorkResource($work->load('mangaka', 'series', ...Work::CARD_RELATIONS));
    }
}
