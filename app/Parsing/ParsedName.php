<?php

namespace App\Parsing;

/**
 * Parsed result of a doujin filename. / 同人ファイル名の解析結果。
 * Immutable. No mangaka here — it comes from the folder. / mangakaはフォルダ由来のため含めない。
 */
final class ParsedName
{
    /** @param string[] $flags */
    public function __construct(
        public readonly string $title,
        public readonly string $titleRaw,
        public readonly string $sortTitle,
        public readonly ?string $event = null,
        public readonly ?string $circle = null,
        public readonly ?string $author = null,
        public readonly ?string $parody = null,
        public readonly ?string $language = null,
        public readonly array $flags = [],
    ) {
    }

    /**
     * Build a result, deriving sortTitle from the title. / タイトルからsortTitleを導出して生成。
     *
     * @param string[] $flags
     */
    public static function make(
        string $title,
        string $titleRaw,
        ?string $event = null,
        ?string $circle = null,
        ?string $author = null,
        ?string $parody = null,
        ?string $language = null,
        array $flags = [],
    ): self {
        return new self(
            title: $title,
            titleRaw: $titleRaw,
            sortTitle: self::deriveSortTitle($title),
            event: $event,
            circle: $circle,
            author: $author,
            parody: $parody,
            language: $language,
            flags: $flags,
        );
    }

    /**
     * Strip leading non-letter/non-digit chars (symbols, brackets, spaces) for ordering.
     * 並び替え用に先頭の記号・括弧・空白（英数字・CJK以外）を除去。
     */
    public static function deriveSortTitle(string $title): string
    {
        $stripped = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $title) ?? '');

        return $stripped !== '' ? $stripped : trim($title);
    }
}
