<?php

namespace App\Http\Controllers;

use App\Jobs\RescanWork;
use App\Models\Work;

/** Work detail — cover, metadata, progress, Read CTA. / 作品詳細。 */
final class WorkController extends Controller
{
    public function show(Work $work)
    {
        $work->load('mangaka', 'series', ...Work::CARD_RELATIONS);

        return view('work.show', compact('work'));
    }

    /** Queue a single-work rescan (repair missing cover/page info). / 1作品の再走査を投入。 */
    public function rescan(Work $work)
    {
        RescanWork::dispatch($work->id);

        return response()->json(['ok' => true], 202);
    }
}
