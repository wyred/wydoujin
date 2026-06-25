<?php

namespace App\Actions\Series;

use App\Models\Series;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/** Remove works from their series (→ standalone), locking the decision. / シリーズから外す。 */
final class UngroupWorks
{
    /** @param  int[]  $workIds */
    public function handle(array $workIds): void
    {
        $works = SameMangakaWorks::resolve($workIds);
        $mangakaId = (int) $works->first()->mangaka_id;

        DB::transaction(function () use ($works, $mangakaId): void {
            Work::whereIn('id', $works->pluck('id'))->update(['series_id' => null, 'series_locked' => true]);
            Series::pruneEmptyAuto($mangakaId);
        });
    }
}
