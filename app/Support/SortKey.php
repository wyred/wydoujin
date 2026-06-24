<?php

namespace App\Support;

/**
 * Sort-key normalisation shared across works, series, and tags. / 並び替えキー。
 */
final class SortKey
{
    /**
     * Strip leading non-letter/non-digit chars (symbols, brackets, spaces) so
     * ordering ignores them; fall back to the trimmed input when all stripped.
     * 先頭の記号・括弧・空白を除去（全て除去された場合はトリム済み入力）。
     */
    public static function derive(string $value): string
    {
        $stripped = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $value) ?? '');

        return $stripped !== '' ? $stripped : trim($value);
    }
}
