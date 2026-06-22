<?php

namespace App\Archive;

/** Result of reading a zip's central directory. / zip中央ディレクトリ読み取り結果。 */
final class ArchiveInspection
{
    /** @param string[] $imageEntries ordered, natural-sorted in-zip image paths */
    public function __construct(
        public readonly string $contentHash,
        public readonly array $imageEntries,
        public readonly int $pageCount,
    ) {
    }
}
