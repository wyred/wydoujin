<?php

namespace App\Http\Controllers;

use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Upserts the single per-work reading-progress row. / 作品ごとの読書進捗を更新（1行）。 */
final class ReadingProgressController extends Controller
{
    public function update(Request $request, Work $work): JsonResponse
    {
        $validated = $request->validate([
            'current_page' => ['required', 'integer', 'min:1', 'max:'.$work->page_count],
        ]);
        $page = (int) $validated['current_page'];

        $progress = ReadingProgress::firstOrNew(['work_id' => $work->id]);
        $progress->current_page = $page;
        $progress->started_at ??= now();           // set once, on first read / 初回のみ
        $progress->last_read_at = now();            // every save / 毎回
        $progress->is_completed = $page >= $work->page_count;
        $progress->completed_at = $progress->is_completed
            ? ($progress->completed_at ?? now())    // preserve first completion / 初完了を保持
            : null;
        $progress->save();

        return response()->json([
            'current_page' => $progress->current_page,
            'is_completed' => $progress->is_completed,
        ]);
    }
}
