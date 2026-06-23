<?php

namespace App\Tagging;

use App\Models\Tag;
use App\Models\Work;
use App\Parsing\FilenameParser;
use App\Parsing\ParsedName;

/**
 * Derives a work's auto tags from the parsed filename and syncs the work_tag
 * pivot. Skips tags_locked works; resolves merge-aliases to canonical tags.
 * 解析結果から自動タグを導出し同期。ロック作品はスキップ。別名は正規へ解決。
 */
final class WorkTagSync
{
    public function __construct(private readonly FilenameParser $parser)
    {
    }

    /** Sync one work's auto tags. No-op when tags_locked. / ロック時は何もしない。 */
    public function sync(Work $work, ?ParsedName $parsed = null): void
    {
        if ($work->tags_locked) {
            return;
        }
        // The parsed fields aren't stored post-migration: re-parse the filename. / 解析値は保存しないため再解析。
        $parsed ??= $this->parser->parse(pathinfo($work->filename, PATHINFO_FILENAME), $work->mangaka->name);

        $ids = [];
        foreach ($this->derive($parsed) as [$type, $value]) {
            $ids[] = $this->canonicalId($type, $value);
        }
        $work->tags()->sync(array_values(array_unique($ids)));
    }

    /**
     * Auto tag set for a parse: one per non-empty scalar + one per flag. / 自動タグ集合。
     *
     * @return list<array{0:string,1:string}> [type, value] pairs
     */
    public function derive(ParsedName $parsed): array
    {
        $pairs = [];
        $scalars = ['circle' => $parsed->circle, 'parody' => $parsed->parody, 'event' => $parsed->event, 'author' => $parsed->author];
        foreach ($scalars as $type => $value) {
            if ($value !== null && $value !== '') {
                $pairs[] = [$type, $value];
            }
        }
        foreach ($parsed->flags as $flag) {
            if ($flag !== '') {
                $pairs[] = ['flag', $flag];
            }
        }

        return $pairs;
    }

    /** firstOrCreate the (type,value) tag, resolved through any merge-alias. / 別名解決付き。 */
    private function canonicalId(string $type, string $value): int
    {
        $tag = Tag::firstOrCreate(['type' => $type, 'value' => $value]);

        return (int) ($tag->merged_into_id ?? $tag->id);
    }

    /** Delete canonical tags with no works that aren't a merge target. / 孤立タグ削除。 */
    public function pruneOrphans(): int
    {
        return Tag::query()
            ->whereNull('merged_into_id')
            ->whereDoesntHave('works')
            ->whereDoesntHave('aliases')
            ->delete();
    }
}
