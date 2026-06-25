<?php

namespace App\Http\Controllers\Api;

use App\Browse\WorkSearch;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkResource;
use Illuminate\Http\Request;

/** Read + organize endpoints for individual works. / 作品の取得・整理API。 */
final class WorkController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 60)));
        $page = max(1, (int) $request->integer('page', 1));

        $paginator = WorkSearch::fromRequest($request)->results($page, $perPage);
        // results() eager-loads CARD_RELATIONS (tags, readingProgress); add mangaka/series for the resource. / 関連を補充。
        $paginator->getCollection()->loadMissing('mangaka', 'series');

        return WorkResource::collection($paginator);
    }
}
