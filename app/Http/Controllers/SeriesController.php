<?php

namespace App\Http\Controllers;

use App\Models\Series;
use App\Models\Work;

/** Series detail — works in reading order. / シリーズ詳細。 */
final class SeriesController extends Controller
{
    public function show(Series $series)
    {
        $series->load('mangaka');
        $works = $series->works()
            ->present()
            ->with(Work::CARD_RELATIONS)
            ->orderBy('sort_title')
            ->get();

        return view('series.show', compact('series', 'works'));
    }
}
