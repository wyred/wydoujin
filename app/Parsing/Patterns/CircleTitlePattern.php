<?php

namespace App\Parsing\Patterns;

use App\Parsing\NamePattern;
use App\Parsing\ParsedName;

/**
 * 'CIRCLE - TITLE' names with no bracket block. Used ONLY for bucket paths (_series/_small),
 * where the artist must be recovered from the filename. The resolver gates this so a normal
 * title that happens to contain ' - ' is never split. / バケット専用の「サークル - タイトル」形式。
 */
final class CircleTitlePattern implements NamePattern
{
    use PeelsGroups;

    public function matches(string $filename): bool
    {
        return ! preg_match('/^\s*[(\[]/u', $filename) && str_contains($filename, ' - ');
    }

    public function parse(string $filename, string $mangaka): ParsedName
    {
        $rest = trim($filename);
        $flags = $this->peelTrailingFlags($rest);
        $parody = $this->peelTrailingGroup($rest, '(', ')');

        [$circle, $title] = array_pad(explode(' - ', $rest, 2), 2, '');
        $circle = trim($circle);
        $title = trim($title);

        return ParsedName::make(
            title: $title !== '' ? $title : $rest,
            titleRaw: $filename,
            circle: $circle !== '' ? $circle : null,
            parody: $parody,
            flags: $flags,
        );
    }
}
