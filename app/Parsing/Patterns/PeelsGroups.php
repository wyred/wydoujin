<?php

namespace App\Parsing\Patterns;

/**
 * Shared bracket-group peeling for filename patterns. / ファイル名の括弧群剥がし（共有）。
 */
trait PeelsGroups
{
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
     * Peel all trailing [flags], returned left-to-right as they appear. / 末尾の[フラグ]を全て剥がす。
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
     * Split a "[...]" inner block into circle + optional trailing (author). / サークルと(作者)に分割。
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
