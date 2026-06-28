<?php

namespace App\Parsing;

use App\Parsing\Patterns\CircleTitlePattern;

/**
 * Turns a library-relative path into {mangakaName, enriched ParsedName}. Pure: depends only on
 * the path string, so the scan path and the rescan re-derive path agree. / パス→メタ情報（純粋関数）。
 *
 * - Normal / other "_" folder: mangaka = top folder; add folder-derived circle/author.
 * - "_series"/"_small" bucket: mangaka = filename author→circle→"Unknown"; "_series" adds the
 *   middle subfolder as a parody tag.
 */
final class PathMetadataResolver
{
    /** Top folders that are organisational buckets, not artists. / バケット（作者ではない）。 */
    private const BUCKETS = ['_series', '_small'];

    private const UNKNOWN = 'Unknown';

    public function __construct(
        private readonly FilenameParser $parser,
        private readonly CircleTitlePattern $circleTitle,
    ) {
    }

    public function resolve(string $relativePath): PathMetadata
    {
        $segments = explode('/', $relativePath);
        $top = $segments[0];
        $basename = pathinfo($segments[array_key_last($segments)], PATHINFO_FILENAME);

        if (in_array($top, self::BUCKETS, true)) {
            return $this->resolveBucket($top, $segments, $basename);
        }

        // Normal or other "_" folder: the top folder is the mangaka. / トップフォルダ＝マンガ家。
        $parsed = $this->parser->parse($basename, $top)
            ->withExtraTags(MangakaFolder::tags($top));

        return new PathMetadata($top, $parsed);
    }

    /**
     * @param  list<string>  $segments
     */
    private function resolveBucket(string $top, array $segments, string $basename): PathMetadata
    {
        // Bucket files carry the artist in the filename. Honour 'circle - title' here only. / 作者はファイル名側。
        $parsed = $this->circleTitle->matches($basename)
            ? $this->circleTitle->parse($basename, '')
            : $this->parser->parse($basename, '');

        $extra = [];
        if ($top === '_series' && count($segments) >= 3) {
            $extra[] = ['parody', $segments[1]]; // the franchise subfolder / フランチャイズ名
        }
        $parsed = $parsed->withExtraTags($extra);

        $mangaka = $parsed->author ?? $parsed->circle ?? self::UNKNOWN;

        return new PathMetadata($mangaka, $parsed);
    }
}
