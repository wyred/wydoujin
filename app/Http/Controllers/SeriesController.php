<?php

namespace App\Http\Controllers;

use App\Models\Series;

/** Series detail — works in reading order. / シリーズ詳細。 */
final class SeriesController extends Controller
{
    public function show(Series $series)
    {
        $series->load('mangaka');
        $works = $series->works()
            ->where('is_missing', false)
            ->with('readingProgress')
            ->orderBy('sort_title')
            ->get();

        return view('series.show', compact('series', 'works'));
    }
}
