<?php

namespace App\Parsing;

/**
 * One filename-parsing strategy. / ファイル名解析の1ストラテジ。
 * Patterns are tried in registry order; the first whose matches() is true wins.
 * パターンは登録順に試行し、matches()が最初にtrueのものを採用。
 */
interface NamePattern
{
    /** Does this pattern apply to the filename? / このパターンが適用可能か。 */
    public function matches(string $filename): bool;

    /** Parse it. $mangaka is the folder name (reserved for future patterns). / 解析する。$mangakaはフォルダ名。 */
    public function parse(string $filename, string $mangaka): ParsedName;
}
