<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MangakaResource;
use App\Models\Mangaka;
use App\Models\Work;

/** Read endpoints for mangaka (top-level folders). / マンガ家の取得API。 */
final class MangakaController extends Controller
{
    public function index()
    {
        $mangaka = Mangaka::query()
            ->withCount(['works', 'series'])
            ->orderBy('name')
            ->paginate(100);

        return MangakaResource::collection($mangaka);
    }

    public function show(Mangaka $mangaka)
    {
        $mangaka->loadCount(['works', 'series']);
        $mangaka->load([
            'series.works' => fn ($q) => $q->with('mangaka', ...Work::CARD_RELATIONS),
            'works' => fn ($q) => $q->with('mangaka', 'series', ...Work::CARD_RELATIONS),
        ]);

        return new MangakaResource($mangaka);
    }
}
