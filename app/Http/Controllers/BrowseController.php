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
            ->whereHas('work', fn ($q) => $q->present())
            ->with('work.mangaka', 'work.readingProgress', 'work.tags')
            ->orderByDesc('last_read_at')
            ->limit(12)
            ->get()
            ->map(fn (ReadingProgress $p) => $p->work);

        $recentlyAdded = Work::query()
            ->present()
            ->with('mangaka', ...Work::CARD_RELATIONS)
            ->latest()
            ->limit(12)
            ->get();

        $randomPicks = Work::query()
            ->present()
            ->with('mangaka', ...Work::CARD_RELATIONS)
            ->inRandomOrder()
            ->limit(8)
            ->get();

        // Skip the existence query when recent works already loaded. / 取得済みなら存在確認を省略。
        $hasAnyWork = $recentlyAdded->isNotEmpty() ?: Work::present()->exists();

        return view('home', compact('continueReading', 'recentlyAdded', 'randomPicks', 'hasAnyWork'));
    }
}
