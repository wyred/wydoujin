<?php

namespace App\Parsing;

use App\Parsing\Patterns\FallbackPattern;

/**
 * Tries registered patterns in order; first match wins. / 登録パターンを順に試し最初の一致を採用。
 */
final class FilenameParser
{
    /** @param NamePattern[] $patterns ordered; the last should always match */
    public function __construct(private readonly array $patterns)
    {
    }

    public function parse(string $filename, string $mangaka): ParsedName
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($filename)) {
                return $pattern->parse($filename, $mangaka);
            }
        }

        // Defensive: registry should end with a catch-all. / 念のため：末尾は全一致であること。
        return (new FallbackPattern())->parse($filename, $mangaka);
    }
}
