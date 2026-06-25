<?php

namespace App\Actions\Series;

use App\Models\Series;
use App\Models\Work;
use App\Support\SortKey;
use Illuminate\Support\Facades\DB;

/** Group works into a new manual (locked) series. / 新規シリーズに束ねる。 */
final class GroupWorks
{
    /** @param  int[]  $workIds */
    public function handle(array $workIds, string $name): Series
    {
        $name = trim($name);
        abort_if($name === '', 422, 'Name is required.');
        $works = SameMangakaWorks::resolve($workIds);
        $mangakaId = (int) $works->first()->mangaka_id;

        return DB::transaction(function () use ($name, $mangakaId, $works): Series {
            $series = Series::create([
                'mangaka_id' => $mangakaId,
                'name' => $name,
                'sort_name' => SortKey::derive($name),
                'is_auto' => false,
            ]);
            Work::whereIn('id', $works->pluck('id'))->update(['series_id' => $series->id, 'series_locked' => true]);
            Series::pruneEmptyAuto($mangakaId);

            return $series;
        });
    }
}
