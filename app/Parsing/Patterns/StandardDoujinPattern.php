<?php

namespace App\Parsing\Patterns;

use App\Parsing\NamePattern;
use App\Parsing\ParsedName;

/**
 * Standard doujin convention: (EVENT) [CIRCLE (AUTHOR)] TITLE (PARODY) [FLAGS...].
 * 標準同人形式。先頭/末尾の括弧群を剥がしてタイトルを得る。
 * Every bracket group is optional, so this also covers no-event / no-parody /
 * multi-flag / circle-without-author variants. / 各括弧は任意なので各種バリアントも処理。
 */
final class StandardDoujinPattern implements NamePattern
{
    use PeelsGroups;

    public function matches(string $filename): bool
    {
        // Only when a leading (event) or [circle] group is present. / 先頭に (…) か […] がある場合のみ。
        return (bool) preg_match('/^\s*[\(\[]/u', $filename);
    }

    public function parse(string $filename, string $mangaka): ParsedName
    {
        $rest = trim($filename);

        $event = $this->peelLeadingGroup($rest, '(', ')');
        $circleBlock = $this->peelLeadingGroup($rest, '[', ']');
        [$circle, $author] = $this->splitCircleAuthor($circleBlock);

        $flags = $this->peelTrailingFlags($rest);
        $parody = $this->peelTrailingGroup($rest, '(', ')');

        $title = trim($rest);

        return ParsedName::make(
            title: $title !== '' ? $title : trim($filename),
            titleRaw: $filename,
            event: $event,
            circle: $circle,
            author: $author,
            parody: $parody,
            flags: $flags,
        );
    }
}
