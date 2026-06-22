<?php

namespace App\Parsing\Patterns;

use App\Parsing\NamePattern;
use App\Parsing\ParsedName;

/**
 * Always-matching last resort: the whole filename becomes the title. / 最終手段：全体をタイトルに。
 */
final class FallbackPattern implements NamePattern
{
    public function matches(string $filename): bool
    {
        return true;
    }

    public function parse(string $filename, string $mangaka): ParsedName
    {
        return ParsedName::make(title: trim($filename), titleRaw: $filename);
    }
}
