<?php

namespace App\Http\Controllers;

use App\Models\Mangaka;
use App\Models\Work;

/** Mangaka index + detail. / マンガ家一覧と詳細。 */
final class MangakaController extends Controller
{
    public function index()
    {
        // Representative cover via a correlated scalar subquery (portable; NOT a
        // limited eager-load, which would cap works across ALL rows).
        // 代表表紙は相関サブクエリで取得（移植性: limited eager-loadの罠を回避）。
        $mangaka = Mangaka::query()
            ->withCount(['works' => fn ($q) => $q->where('is_missing', false)])
            ->addSelect(['rep_cover' => Work::select('cover_path')
                ->whereColumn('mangaka_id', 'mangaka.id')
                ->where('is_missing', false)
                ->whereNotNull('cover_path')
                ->orderBy('sort_title')
                ->limit(1)])
            ->orderBy('name')
            ->paginate(24);

        return view('mangaka.index', compact('mangaka'));
    }

    public function show(Mangaka $mangaka)
    {
        $series = $mangaka->series()
            ->whereHas('works', fn ($q) => $q->where('is_missing', false))
            ->with(['works' => fn ($q) => $q->where('is_missing', false)->orderBy('sort_title')])
            ->orderBy('name')
            ->get();

        $standalone = $mangaka->works()
            ->where('is_missing', false)
            ->whereNull('series_id')
            ->with('readingProgress')
            ->orderBy('sort_title')
            ->get();

        // Flat list for Manage mode: every non-missing work + its current series + a
        // stem suggestion for the default new-series name. / 管理モード用の平坦リスト。
        $normalizer = new \App\Series\TitleNormalizer();
        $manageWorks = $mangaka->works()
            ->where('is_missing', false)
            ->with('series:id,name')
            ->orderBy('sort_title')
            ->get(['id', 'title', 'series_id', 'mangaka_id'])
            ->map(fn (\App\Models\Work $w) => [
                'id' => $w->id,
                'title' => $w->title,
                'series' => $w->series?->name,
                'stem' => $normalizer->stem($w->title),
            ])->all();
        $manageSeries = $series->map(fn (\App\Models\Series $s) => ['id' => $s->id, 'name' => $s->name])->values()->all();

        return view('mangaka.show', compact('mangaka', 'series', 'standalone', 'manageWorks', 'manageSeries'));
    }
}
