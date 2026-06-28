<?php

namespace App\Parsing;

/**
 * Derives circle/author tags from a top-folder (mangaka) name. The folder string stays the
 * mangaka's display name; this only produces tags for faceting. / フォルダ名からサークル/作者タグを導出。
 *
 * - "Circle (Author)"           → [['circle', Circle], ['author', Author]]
 * - "Romaji - Japanese" / plain → [['author', whole folder]]
 */
final class MangakaFolder
{
    /** @return list<array{0:string,1:string}> */
    public static function tags(string $folder): array
    {
        $folder = trim($folder);
        if ($folder === '') {
            return [];
        }

        if (preg_match('/^(.*?)\s*\(([^()]+)\)\s*$/u', $folder, $m) && trim($m[2]) !== '') {
            $tags = [];
            if (trim($m[1]) !== '') {
                $tags[] = ['circle', trim($m[1])];
            }
            $tags[] = ['author', trim($m[2])];

            return $tags;
        }

        return [['author', $folder]];
    }
}
