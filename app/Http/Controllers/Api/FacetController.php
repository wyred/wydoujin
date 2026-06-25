<?php

namespace App\Http\Controllers\Api;

use App\Browse\WorkSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Dynamic facet counts across the 6 tag dimensions for a given filter. / 動的ファセット件数API。 */
final class FacetController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(WorkSearch::fromRequest($request)->facets());
    }
}
