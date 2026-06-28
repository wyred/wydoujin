<?php

namespace App\Parsing\Patterns;

use App\Parsing\NamePattern;
use App\Parsing\ParsedName;

/**
 * Title-first names: TITLE (PARODY) [FLAGS...] with NO leading bracket. Peels the trailing
 * groups so the parody + flags survive instead of being swallowed into the title.
 * 先頭括弧なしのタイトル先頭形式。末尾の(パロディ)と[フラグ]を剥がす。
 */
final class TrailingMetadataPattern implements NamePattern
{
    use PeelsGroups;

    public function matches(string $filename): bool
    {
        // No leading (event)/[circle], but there is a trailing group worth peeling. / 末尾に剥がす括弧がある場合。
        return ! preg_match('/^\s*[(\[]/u', $filename)
            && (bool) preg_match('/[)\]]\s*$/u', $filename);
    }

    public function parse(string $filename, string $mangaka): ParsedName
    {
        $rest = trim($filename);
        $flags = $this->peelTrailingFlags($rest);
        $parody = $this->peelTrailingGroup($rest, '(', ')');
        $title = trim($rest);

        return ParsedName::make(
            title: $title !== '' ? $title : trim($filename),
            titleRaw: $filename,
            parody: $parody,
            flags: $flags,
        );
    }
}
