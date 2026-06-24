<?php

namespace App\Http\Controllers;

use App\Models\Series;
use App\Models\Work;
use App\Support\SortKey;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Manual series management — group / add / ungroup / rename (F3c). / 手動シリーズ管理。
 *
 * DB-only (never touches /library). Every op sets series_locked=true (+ is_auto=false
 * on touched series) so SeriesDetector::detect() never undoes the manual decision.
 */
final class SeriesManagementController extends Controller
{
    /** Group works into a new manual series. / 新規シリーズに束ねる。 */
    public function group(Request $request)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Name is required.');
        $works = $this->sameMangakaWorks($data['work_ids']);
        $mangakaId = (int) $works->first()->mangaka_id;

        $series = Series::create([
            'mangaka_id' => $mangakaId,
            'name' => $name,
            'sort_name' => SortKey::derive($name),
            'is_auto' => false,
        ]);
        Work::whereIn('id', $works->pluck('id'))->update(['series_id' => $series->id, 'series_locked' => true]);
        $this->cleanEmptyAutoSeries($mangakaId);

        return response()->json(['series_id' => $series->id], 201);
    }

    /** Add works to an existing series. / 既存シリーズに追加。 */
    public function add(Request $request, Series $series)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
        ]);
        $works = $this->sameMangakaWorks($data['work_ids']);
        abort_if((int) $works->first()->mangaka_id !== (int) $series->mangaka_id, 422, 'Series belongs to another mangaka.');

        $series->update(['is_auto' => false]);
        Work::whereIn('id', $works->pluck('id'))->update(['series_id' => $series->id, 'series_locked' => true]);
        $this->cleanEmptyAutoSeries((int) $series->mangaka_id);

        return response()->json(['ok' => true]);
    }

    /** Remove works from their series (→ standalone). / シリーズから外す。 */
    public function ungroup(Request $request)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
        ]);
        $works = $this->sameMangakaWorks($data['work_ids']);
        $mangakaId = (int) $works->first()->mangaka_id;

        Work::whereIn('id', $works->pluck('id'))->update(['series_id' => null, 'series_locked' => true]);
        $this->cleanEmptyAutoSeries($mangakaId);

        return response()->json(['ok' => true]);
    }

    /** Rename a series. / シリーズ名を変更。 */
    public function rename(Request $request, Series $series)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Name is required.');

        $series->update([
            'name' => $name,
            'sort_name' => SortKey::derive($name),
            'is_auto' => false,
        ]);
        $series->works()->update(['series_locked' => true]);

        return response()->json(['ok' => true]);
    }

    /**
     * Load the works by id and ensure they all belong to one mangaka. / 同一マンガ家か検証。
     *
     * @param  int[]  $ids
     */
    private function sameMangakaWorks(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $works = Work::whereIn('id', $ids)->get(['id', 'mangaka_id']);
        abort_if($works->count() !== count($ids), 422, 'Unknown work in selection.');
        abort_if($works->pluck('mangaka_id')->unique()->count() !== 1, 422, 'Works span multiple mangaka.');

        return $works;
    }

    /** Delete now-empty auto series (mirrors the detector's self-cleaning). / 空の自動シリーズを削除。 */
    private function cleanEmptyAutoSeries(int $mangakaId): void
    {
        Series::where('mangaka_id', $mangakaId)
            ->where('is_auto', true)
            ->whereDoesntHave('works')
            ->delete();
    }
}
