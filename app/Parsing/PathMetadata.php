<?php

namespace App\Parsing;

/**
 * The full metadata for one library path: which mangaka it belongs to and the (enriched)
 * filename parse. / 1パスのメタ情報（所属マンガ家＋強化済み解析）。
 */
final class PathMetadata
{
    public function __construct(
        public readonly string $mangakaName,
        public readonly ParsedName $parsed,
    ) {
    }
}
