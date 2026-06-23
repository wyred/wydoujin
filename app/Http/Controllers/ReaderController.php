<?php

namespace App\Http\Controllers;

use App\Models\Work;
use Illuminate\Http\Request;

/** Immersive single-page reader (spec §9). / 没入型ビューア。 */
final class ReaderController extends Controller
{
    public function show(Request $request, Work $work)
    {
        $pages = (int) $work->page_count;

        // Resume at the saved page when in-progress; else page 1. / 続きから再開。
        $progress = $work->readingProgress;
        $resume = ($progress && $progress->current_page > 0 && ! $progress->is_completed)
            ? (int) $progress->current_page
            : 1;

        // ?page=N overrides; clamp to 1..pages (min 1 even for a 0-page work). / 範囲内に丸める。
        $initialPage = max(1, min((int) $request->query('page', $resume), max(1, $pages)));

        return view('reader.show', ['work' => $work, 'pages' => $pages, 'initialPage' => $initialPage]);
    }
}
