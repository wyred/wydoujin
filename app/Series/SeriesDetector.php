<?php

namespace App\Series;

use App\Models\Mangaka;
use App\Models\Series;
use App\Models\Work;
use App\Parsing\ParsedName;
use Illuminate\Support\Collection;

/**
 * Per-mangaka auto series detection (spec §8). Groups works by normalized title
 * stem; never crosses folders, never groups by parody.
 * マンガ家単位のシリーズ自動検出。フォルダを跨がず、パロディで結合しない。
 */
final class SeriesDetector implements SeriesDetectorContract
{
    public function __construct(private readonly TitleNormalizer $normalizer)
    {
    }

    public function detect(): array
    {
        $created = 0;
        $grouped = 0;

        foreach (Mangaka::all() as $mangaka) {
            // Filter only locked works — NOT is_missing. Missing works (§7: never deleted, progress kept) stay
            // grouped so a transiently-missing volume doesn't fragment its series. / 欠落作品も故意にシリーズ維持。
            $works = Work::where('mangaka_id', $mangaka->id)
                ->where('series_locked', false)
                ->orderBy('id')
                ->get(['id', 'title']);

            $groupedIds = [];
            foreach ($this->cluster($works) as $stem => $workIds) {
                if (count($workIds) < 2) {
                    continue; // singletons stay standalone / 単独作品はシリーズ化しない
                }

                $series = Series::firstOrCreate(
                    ['mangaka_id' => $mangaka->id, 'name' => $stem, 'is_auto' => true],
                    ['sort_name' => ParsedName::deriveSortTitle($stem)],
                );
                $created += $series->wasRecentlyCreated ? 1 : 0;

                Work::whereIn('id', $workIds)->update(['series_id' => $series->id]);
                $grouped += count($workIds);
                $groupedIds = array_merge($groupedIds, $workIds);
            }

            // Non-locked works that no longer cluster: drop stale links. / 単独化した作品のリンク解除。
            Work::where('mangaka_id', $mangaka->id)
                ->where('series_locked', false)
                ->whereNotIn('id', $groupedIds ?: [0])
                ->whereNotNull('series_id')
                ->update(['series_id' => null]);

            // Delete auto series emptied by this run; manual series are preserved. / 空の自動シリーズ削除。
            Series::where('mangaka_id', $mangaka->id)
                ->where('is_auto', true)
                ->whereDoesntHave('works')
                ->delete();
        }

        return ['series_created' => $created, 'works_grouped' => $grouped];
    }

    /**
     * Cluster works by shared stem. Two stems share a cluster when equal, or one
     * is a prefix of the other at a separator boundary. Cluster key = shortest stem.
     * stemでクラスタ化。等しい/区切り境界の接頭辞で同一とみなす。代表キーは最短stem。
     *
     * @param  Collection<int,Work>  $works
     * @return array<string,int[]>  stem => work ids
     */
    private function cluster(Collection $works): array
    {
        $stems = []; // [workId => stem]
        foreach ($works as $work) {
            $stems[$work->id] = $this->normalizer->stem($work->title);
        }

        // Shortest first so the common-prefix stem becomes the cluster key; byte
        // length matches the byte-based str_starts_with check below. / 最短を代表キーに。
        $distinct = array_values(array_unique(array_values($stems)));
        usort($distinct, fn (string $a, string $b): int => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        $canon = []; // [stem => canonical key]
        foreach ($distinct as $stem) {
            $key = $stem;
            foreach ($distinct as $candidate) {
                if ($candidate === $stem) {
                    break; // only shorter/earlier candidates can be a prefix / 以降は対象外
                }
                if ($this->isPrefixAtBoundary($candidate, $stem)) {
                    $key = $canon[$candidate] ?? $candidate;
                    break;
                }
            }
            $canon[$stem] = $key;
        }

        $clusters = [];
        foreach ($stems as $workId => $stem) {
            $clusters[$canon[$stem]][] = $workId;
        }

        return $clusters;
    }

    /** True if $prefix is a byte-prefix of $full and the next char is space/punct. / 区切り境界での接頭辞判定。 */
    private function isPrefixAtBoundary(string $prefix, string $full): bool
    {
        if ($prefix === '' || $prefix === $full || ! str_starts_with($full, $prefix)) {
            return false;
        }
        $remainder = substr($full, strlen($prefix));

        return (bool) preg_match('/^[\s\p{Z}\p{P}]/u', $remainder);
    }
}
