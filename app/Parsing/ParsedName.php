<?php

namespace App\Parsing;

use App\Support\SortKey;

/**
 * Parsed result of a doujin filename. / 同人ファイル名の解析結果。
 * Immutable. No mangaka here — it comes from the folder. / mangakaはフォルダ由来のため含めない。
 */
final class ParsedName
{
    /**
     * @param string[] $flags
     * @param list<array{0:string,1:string}> $extraTags  folder/subfolder-derived [type,value] tags
     */
    public function __construct(
        public readonly string $title,
        public readonly string $titleRaw,
        public readonly string $sortTitle,
        public readonly ?string $event = null,
        public readonly ?string $circle = null,
        public readonly ?string $author = null,
        public readonly ?string $parody = null,
        public readonly array $flags = [],
        public readonly array $extraTags = [],
    ) {
    }

    /**
     * Build a result, deriving sortTitle from the title. / タイトルからsortTitleを導出して生成。
     *
     * @param string[] $flags
     * @param list<array{0:string,1:string}> $extraTags
     */
    public static function make(
        string $title,
        string $titleRaw,
        ?string $event = null,
        ?string $circle = null,
        ?string $author = null,
        ?string $parody = null,
        array $flags = [],
        array $extraTags = [],
    ): self {
        return new self(
            title: $title,
            titleRaw: $titleRaw,
            sortTitle: SortKey::derive($title),
            event: $event,
            circle: $circle,
            author: $author,
            parody: $parody,
            flags: $flags,
            extraTags: $extraTags,
        );
    }

    /**
     * Copy with extra [type,value] tags appended (folder/subfolder enrichment). / 追加タグを付与した複製。
     *
     * @param list<array{0:string,1:string}> $extraTags
     */
    public function withExtraTags(array $extraTags): self
    {
        return new self(
            title: $this->title,
            titleRaw: $this->titleRaw,
            sortTitle: $this->sortTitle,
            event: $this->event,
            circle: $this->circle,
            author: $this->author,
            parody: $this->parody,
            flags: $this->flags,
            extraTags: [...$this->extraTags, ...$extraTags],
        );
    }
}
