<?php

namespace App\Http\Controllers\Api;

use App\Browse\WorkSearch;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkResource;
use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read + organize endpoints for individual works. / 作品の取得・整理API。 */
final class WorkController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 60)));
        $page = max(1, (int) $request->integer('page', 1));

        $query = WorkSearch::fromRequest($request)->builder()
            ->with([...Work::CARD_RELATIONS, 'mangaka', 'series'])
            ->orderBy('sort_title');

        $this->applyOrganizeFilters($query, $request);

        return WorkResource::collection($query->paginate($perPage, ['*'], 'page', $page));
    }

    public function show(Work $work)
    {
        $work->load('mangaka', 'series', ...Work::CARD_RELATIONS);

        return new WorkResource($work);
    }

    /** Organize-oriented filters layered on top of search + facets. / 整理向けの追加絞り込み。 */
    private function applyOrganizeFilters(Builder $query, Request $request): void
    {
        if ($request->filled('mangaka')) {
            $value = (string) $request->query('mangaka');
            $id = ctype_digit($value) ? (int) $value : (int) (Mangaka::where('slug', $value)->value('id') ?? -1);
            $query->where('mangaka_id', $id);
        }

        if ($request->filled('series')) {
            $query->where('series_id', (int) $request->query('series'));
        }

        if ($request->boolean('untagged')) {
            $query->whereDoesntHave('tags');
        }

        if ($request->has('tags_locked')) {
            $query->where('tags_locked', $request->boolean('tags_locked'));
        }
    }
}
