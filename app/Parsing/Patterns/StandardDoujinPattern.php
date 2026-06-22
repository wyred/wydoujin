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

    /** Peel a leading $open...$close group; trims $rest; null if absent. / 先頭の括弧群を剥がす。 */
    private function peelLeadingGroup(string &$rest, string $open, string $close): ?string
    {
        $rest = ltrim($rest);
        if ($rest === '' || $rest[0] !== $open) {
            return null;
        }
        $closePos = strpos($rest, $close);
        if ($closePos === false) {
            return null;
        }
        $inner = substr($rest, 1, $closePos - 1);
        $rest = ltrim(substr($rest, $closePos + 1));

        return trim($inner);
    }

    /** Peel a trailing $open...$close group; trims $rest; null if absent. / 末尾の括弧群を剥がす。 */
    private function peelTrailingGroup(string &$rest, string $open, string $close): ?string
    {
        $rest = rtrim($rest);
        $len = strlen($rest);
        if ($len === 0 || $rest[$len - 1] !== $close) {
            return null;
        }
        $openPos = strrpos($rest, $open);
        if ($openPos === false) {
            return null;
        }
        $inner = substr($rest, $openPos + 1, $len - $openPos - 2);
        $rest = rtrim(substr($rest, 0, $openPos));

        return trim($inner);
    }

    /**
     * Peel all trailing [flags], returned left-to-right as they appear.
     * 末尾の[フラグ]を全て剥がし、出現順（左→右）で返す。
     *
     * @return string[]
     */
    private function peelTrailingFlags(string &$rest): array
    {
        $flags = [];
        while (($flag = $this->peelTrailingGroup($rest, '[', ']')) !== null) {
            array_unshift($flags, $flag);
        }

        return $flags;
    }

    /**
     * Split a "[...]" inner block into circle + optional trailing (author).
     * 「[...]」の中身をサークルと末尾の(作者)に分割。
     *
     * @return array{0: ?string, 1: ?string} [circle, author]
     */
    private function splitCircleAuthor(?string $block): array
    {
        if ($block === null || $block === '') {
            return [null, null];
        }
        $rest = $block;
        $author = $this->peelTrailingGroup($rest, '(', ')');
        $circle = trim($rest);

        return [$circle !== '' ? $circle : null, $author];
    }
}
