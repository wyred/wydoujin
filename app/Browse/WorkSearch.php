<?php

namespace App\Browse;

use App\Models\Work;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Title search + faceted filtering over works (F3a). / 作品の検索＋ファセット絞り込み。
 *
 * Facets: 6 dimensions over the work_tag pivot. OR within a dimension, AND across.
 * Counts are dynamic — each dimension is counted under the search + the OTHER facets
 * (never its own), so remaining values stay selectable. / 6次元タグによるファセット。
 */
final class WorkSearch
{
    public const DIMENSIONS = ['circle', 'parody', 'event', 'author', 'flag', 'theme'];

    /**
     * @param string[] $circle
     * @param string[] $parody
     * @param string[] $event
     * @param string[] $author
     * @param string[] $flag
     * @param string[] $theme
     */
    public function __construct(
        public readonly ?string $q = null,
        public readonly array $circle = [],
        public readonly array $parody = [],
        public readonly array $event = [],
        public readonly array $author = [],
        public readonly array $flag = [],
        public readonly array $theme = [],
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
            author: $clean($request->query('author', [])),
            flag: $clean($request->query('flag', [])),
            theme: $clean($request->query('theme', [])),
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
            ->present()
            ->when($this->q !== null, function (Builder $w): void {
                // ESCAPE '!' (not backslash): backslash literal handling diverges between SQLite and MySQL,
                // so '!' keeps literal % / _ matching identically on both engines. / バックスラッシュはSQLite・MySQL間で挙動が異なるため '!' を使用。
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $this->q).'%';
                $w->where(function (Builder $x) use ($term): void {
                    $x->whereRaw("title LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("title_raw LIKE ? ESCAPE '!'", [$term]);
                });
            });
    }

    /** Apply facet filters via the pivot, optionally skipping one dimension. / ファセット適用。 */
    private function applyFacets(Builder $query, ?string $except = null): Builder
    {
        foreach (self::DIMENSIONS as $dim) {
            $values = $this->selected($dim);
            if ($dim !== $except && $values !== []) {
                $query->whereHas('tags', fn (Builder $t) => $t->where('type', $dim)->whereIn('value', $values));
            }
        }

        return $query;
    }

    public function results(int $page = 1, int $perPage = 60): LengthAwarePaginator
    {
        return $this->applyFacets($this->base())
            ->with(Work::CARD_RELATIONS)
            ->orderBy('sort_title')
            ->paginate($perPage, ['*'], 'page', max(1, $page));
    }

    /**
     * Works matching base + the OTHER facets (excluding $except's own selection),
     * as an id subquery so the DB never ships the whole id list to PHP and back.
     * 基底＋他次元に一致するidの副問合せ（PHPへid列を取り出さない）。
     */
    private function matchingWorksQuery(?string $except): Builder
    {
        return $this->applyFacets($this->base(), except: $except)->select('id');
    }

    /**
     * Dynamic facet counts from the pivot. / 動的ファセット件数。
     *
     * @return array<string, list<array{value:string,count:int}>>
     */
    public function facets(): array
    {
        $out = [];
        foreach (self::DIMENSIONS as $dim) {
            $counts = DB::table('work_tag')
                ->join('tags', 'tags.id', '=', 'work_tag.tag_id')
                ->whereIn('work_tag.work_id', $this->matchingWorksQuery($dim))
                ->where('tags.type', $dim)
                ->whereNull('tags.merged_into_id')
                ->groupBy('tags.value')
                ->selectRaw('tags.value as value, COUNT(DISTINCT work_tag.work_id) as count')
                ->pluck('count', 'value')
                ->all();

            foreach ($this->selected($dim) as $sel) {
                $counts[$sel] ??= 0;
            }
            $rows = [];
            foreach ($counts as $value => $count) {
                $rows[] = ['value' => (string) $value, 'count' => (int) $count];
            }
            usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);
            $out[$dim] = $rows;
        }

        return $out;
    }
}
