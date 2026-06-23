<?php

namespace App\Http\Controllers;

use App\Models\ReadingProgress;
use App\Models\Work;

/** The home dashboard: Continue Reading + Recently Added. / ホーム。 */
final class BrowseController extends Controller
{
    public function home()
    {
        $continueReading = ReadingProgress::query()
            ->where('current_page', '>', 0)
            ->where('is_completed', false)
            ->whereHas('work', fn ($q) => $q->where('is_missing', false))
            ->with('work.mangaka', 'work.readingProgress', 'work.tags')
            ->orderByDesc('last_read_at')
            ->limit(12)
            ->get()
            ->map(fn (ReadingProgress $p) => $p->work);

        $recentlyAdded = Work::query()
            ->where('is_missing', false)
            ->with('mangaka', 'readingProgress', 'tags')
            ->latest()
            ->limit(12)
            ->get();

        $hasAnyWork = Work::where('is_missing', false)->exists();

        return view('home', compact('continueReading', 'recentlyAdded', 'hasAnyWork'));
    }
}
