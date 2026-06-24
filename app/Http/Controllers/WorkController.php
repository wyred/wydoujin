<?php

namespace App\Http\Controllers;

use App\Models\Work;

/** Work detail — cover, metadata, progress, Read CTA. / 作品詳細。 */
final class WorkController extends Controller
{
    public function show(Work $work)
    {
        $work->load('mangaka', 'series', ...Work::CARD_RELATIONS);

        return view('work.show', compact('work'));
    }
}
