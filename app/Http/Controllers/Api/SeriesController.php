<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SeriesResource;
use App\Models\Series;
use App\Models\Work;

/** Read endpoint for a series. / シリーズの取得API。 */
final class SeriesController extends Controller
{
    public function show(Series $series)
    {
        $series->load([
            'works' => fn ($q) => $q->with('mangaka', 'series', ...Work::CARD_RELATIONS)->orderBy('sort_title'),
        ]);

        return new SeriesResource($series);
    }
}
