<?php

namespace App\Browse;

use App\Models\Work;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Title search + faceted filtering over works (F3a). / 作品の検索＋ファセット絞り込み。
 *
 * Facets: circle/parody/event. OR within a facet, AND across. Counts are dynamic —
 * each dimension is counted under the search + the OTHER facets (never its own),
 * so its remaining values stay selectable.
 */
final class WorkSearch
{
    public const DIMENSIONS = ['circle', 'parody', 'event'];

    /**
     * @param string[] $circle
     * @param string[] $parody
     * @param string[] $event
     */
    public function __construct(
        public readonly ?string $q = null,
        public readonly array $circle = [],
        public readonly array $parody = [],
        public readonly array $event = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        $clean = static fn ($v): array => array_values(array_filter(
            array_map('strval', (array) $v),
            static fn (string $s): bool => $s !== '',
        ));
        $q = trim((string) $request->query('q', ''));

        return new self(
            q: $q === '' ? null : $q,
            circle: $clean($request->query('circle', [])),
            parody: $clean($request->query('parody', [])),
            event: $clean($request->query('event', [])),
        );
    }

    /** Selected values for a dimension. / 次元の選択値。 */
    private function selected(string $dim): array
    {
        return $this->{$dim};
    }

    /** Base query: not-missing + optional title LIKE. / 基底: 欠落除外＋題名LIKE。 */
    private function base(): Builder
    {
        return Work::query()
            ->where('is_missing', false)
            ->when($this->q !== null, function (Builder $w): void {
                $term = '%'.$this->q.'%';
                $w->where(function (Builder $x) use ($term): void {
                    $x->where('title', 'like', $term)->orWhere('title_raw', 'like', $term);
                });
            });
    }

    /** Apply facet whereIns, optionally skipping one dimension. / ファセット適用（1次元除外可）。 */
    private function applyFacets(Builder $query, ?string $except = null): Builder
    {
        foreach (self::DIMENSIONS as $dim) {
            $values = $this->selected($dim);
            if ($dim !== $except && $values !== []) {
                $query->whereIn($dim, $values);
            }
        }

        return $query;
    }

    public function results(int $page = 1, int $perPage = 60): LengthAwarePaginator
    {
        return $this->applyFacets($this->base())
            ->with('readingProgress')
            ->orderBy('sort_title')
            ->paginate($perPage, ['*'], 'page', max(1, $page));
    }

    /**
     * Dynamic facet counts. / 動的ファセット件数。
     *
     * @return array<string, list<array{value:string,count:int}>>
     */
    public function facets(): array
    {
        $out = [];
        foreach (self::DIMENSIONS as $dim) {
            // Count under base + the OTHER facets (exclude this dim's own selection).
            $counts = $this->applyFacets($this->base(), except: $dim)
                ->whereNotNull($dim)
                ->pluck($dim)
                ->countBy()
                ->all(); // value => count

            // Keep selected-but-now-absent values visible so they can be unchecked.
            foreach ($this->selected($dim) as $sel) {
                $counts[$sel] ??= 0;
            }

            $rows = [];
            foreach ($counts as $value => $count) {
                $rows[] = ['value' => (string) $value, 'count' => (int) $count];
            }
            // count desc, then value asc.
            usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);

            $out[$dim] = $rows;
        }

        return $out;
    }
}
