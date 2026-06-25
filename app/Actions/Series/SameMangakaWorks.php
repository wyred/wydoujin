<?php

namespace App\Actions\Series;

use App\Models\Work;
use Illuminate\Support\Collection;

/** Resolve work ids and assert they all belong to one mangaka. / 同一マンガ家か検証。 */
final class SameMangakaWorks
{
    /**
     * @param  int[]  $ids
     * @return Collection<int, Work>
     */
    public static function resolve(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $works = Work::whereIn('id', $ids)->get(['id', 'mangaka_id']);
        abort_if($works->count() !== count($ids), 422, 'Unknown work in selection.');
        abort_if($works->pluck('mangaka_id')->unique()->count() !== 1, 422, 'Works span multiple mangaka.');

        return $works;
    }
}
