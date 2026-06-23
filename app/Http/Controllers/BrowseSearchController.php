<?php

namespace App\Http\Controllers;

use App\Browse\WorkSearch;
use Illuminate\Http\Request;

/** Browse: live title search + faceted filtering (F3a). / 検索＋ファセット絞り込み。 */
final class BrowseSearchController extends Controller
{
    public function index(Request $request)
    {
        $search = WorkSearch::fromRequest($request);
        $page = max(1, (int) $request->query('page', 1));

        $works = $search->results($page, 60);
        $facets = $search->facets();

        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json([
                'total' => $works->total(),
                'page' => $works->currentPage(),
                'hasMore' => $works->hasMorePages(),
                'facets' => $facets,
                'html' => view('browse._cards', ['works' => $works->items()])->render(),
            ]);
        }

        return view('browse.index', [
            'works' => $works,
            'facets' => $facets,
            'total' => $works->total(),
            'hasMore' => $works->hasMorePages(),
            'search' => $search,
        ]);
    }
}
