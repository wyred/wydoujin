<?php

namespace App\Http\Controllers;

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Http\Request;

/** Mangaka index + detail. / マンガ家一覧と詳細。 */
final class MangakaController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        // Representative cover via a correlated scalar subquery (portable; NOT a
        // limited eager-load, which would cap works across ALL rows).
        // 代表表紙は相関サブクエリで取得（移植性: limited eager-loadの罠を回避）。
        $mangaka = Mangaka::query()
            ->withCount(['works' => fn ($w) => $w->present()])
            ->addSelect(['rep_cover' => Work::select('cover_path')
                ->whereColumn('mangaka_id', 'mangaka.id')
                ->present()
                ->whereNotNull('cover_path')
                ->orderBy('sort_title')
                ->limit(1)])
            ->when($q !== '', function ($query) use ($q): void {
                // ESCAPE '!' (not backslash) keeps literal % / _ matching identical on
                // SQLite and MySQL — same convention as WorkSearch. / WorkSearchと同じ'!'エスケープ。
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->whereRaw("name LIKE ? ESCAPE '!'", [$term]);
            })
            ->orderBy('name')
            ->paginate(24)
            ->appends($q !== '' ? ['q' => $q] : []);

        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json([
                'total' => $mangaka->total(),
                'html' => view('mangaka._cards', ['mangaka' => $mangaka->items()])->render(),
                'pagination' => view('mangaka._pagination', ['paginator' => $mangaka])->render(),
            ]);
        }

        return view('mangaka.index', compact('mangaka', 'q'));
    }

    public function show(Mangaka $mangaka)
    {
        $series = $mangaka->series()
            ->whereHas('works', fn ($q) => $q->present())
            ->with(['works' => fn ($q) => $q->present()->orderBy('sort_title')])
            ->orderBy('name')
            ->get();

        $standalone = $mangaka->works()
            ->present()
            ->whereNull('series_id')
            ->with(Work::CARD_RELATIONS)
            ->orderBy('sort_title')
            ->get();

        // Flat list for Manage mode: every non-missing work + its current series + a
        // stem suggestion for the default new-series name. / 管理モード用の平坦リスト。
        $normalizer = new \App\Series\TitleNormalizer();
        $manageWorks = $mangaka->works()
            ->present()
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
