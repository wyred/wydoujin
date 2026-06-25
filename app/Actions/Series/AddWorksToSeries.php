<?php

namespace App\Actions\Series;

use App\Models\Series;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/** Add works to an existing series (locks them, flips it manual). / 既存シリーズに追加。 */
final class AddWorksToSeries
{
    /** @param  int[]  $workIds */
    public function handle(Series $series, array $workIds): void
    {
        $works = SameMangakaWorks::resolve($workIds);
        abort_if((int) $works->first()->mangaka_id !== (int) $series->mangaka_id, 422, 'Series belongs to another mangaka.');

        DB::transaction(function () use ($series, $works): void {
            $series->update(['is_auto' => false]);
            Work::whereIn('id', $works->pluck('id'))->update(['series_id' => $series->id, 'series_locked' => true]);
            Series::pruneEmptyAuto((int) $series->mangaka_id);
        });
    }
}
