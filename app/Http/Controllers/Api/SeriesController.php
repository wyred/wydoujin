<?php

namespace App\Http\Controllers\Api;

use App\Actions\Series\AddWorksToSeries;
use App\Actions\Series\GroupWorks;
use App\Actions\Series\RenameSeries;
use App\Actions\Series\UngroupWorks;
use App\Http\Controllers\Controller;
use App\Http\Resources\SeriesResource;
use App\Models\Series;
use App\Models\Work;
use Illuminate\Http\Request;

/** Read + organize endpoints for series (per-mangaka grouping). / シリーズ取得・整理API。 */
final class SeriesController extends Controller
{
    public function show(Series $series)
    {
        $series->load([
            'works' => fn ($q) => $q->with('mangaka', 'series', ...Work::CARD_RELATIONS)->orderBy('sort_title'),
        ]);

        return new SeriesResource($series);
    }

    public function store(Request $request, GroupWorks $action)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $series = $action->handle($data['work_ids'], $data['name']);

        return (new SeriesResource($series->loadCount('works')))->response()->setStatusCode(201);
    }

    public function addWorks(Request $request, Series $series, AddWorksToSeries $action)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
        ]);

        $action->handle($series, $data['work_ids']);

        return new SeriesResource($series->fresh()->loadCount('works'));
    }

    public function ungroup(Request $request, UngroupWorks $action)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
        ]);

        $action->handle($data['work_ids']);

        return response()->json(['ok' => true]);
    }

    public function rename(Request $request, Series $series, RenameSeries $action)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $action->handle($series, $data['name']);

        return new SeriesResource($series->fresh()->loadCount('works'));
    }
}
