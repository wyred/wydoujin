<?php

namespace App\Tagging;

use App\Models\Tag;
use App\Models\Work;
use App\Parsing\ParsedName;
use App\Parsing\PathMetadataResolver;

/**
 * Derives a work's auto tags from the parsed filename and syncs the work_tag
 * pivot. Skips tags_locked works; resolves merge-aliases to canonical tags.
 * 解析結果から自動タグを導出し同期。ロック作品はスキップ。別名は正規へ解決。
 */
final class WorkTagSync
{
    /** Per-run memo of (type,value) → canonical tag id; this instance lives one scan. / スキャン内メモ。 */
    private array $canonicalCache = [];

    public function __construct(private readonly PathMetadataResolver $resolver)
    {
    }

    /** Sync one work's auto tags. No-op when tags_locked. / ロック時は何もしない。 */
    public function sync(Work $work, ?ParsedName $parsed = null): void
    {
        if ($work->tags_locked) {
            return;
        }
        // Parsed fields aren't stored post-migration. Re-derive from the stored relative_path so
        // folder/subfolder tags survive a rescan. / 保存パスから再導出（フォルダ/サブフォルダ由来タグも保持）。
        $parsed ??= $this->resolver->resolve($work->relative_path)->parsed;

        $ids = [];
        foreach ($this->derive($parsed) as [$type, $value]) {
            $ids[] = $this->canonicalId($type, $value);
        }
        $work->tags()->sync(array_values(array_unique($ids)));
    }

    /**
     * Auto tag set for a parse: one per non-empty scalar + one per flag + folder/subfolder
     * extras. / 自動タグ集合（スカラー＋フラグ＋フォルダ由来）。
     *
     * @return list<array{0:string,1:string}> [type, value] pairs
     */
    public function derive(ParsedName $parsed): array
    {
        $pairs = [];
        foreach (Tag::SCALAR_TYPES as $type) {
            $value = $parsed->{$type}; // property names mirror SCALAR_TYPES / プロパティ名はSCALAR_TYPESと一致
            if ($value !== null && $value !== '') {
                $pairs[] = [$type, $value];
            }
        }
        foreach ($parsed->flags as $flag) {
            if ($flag !== '') {
                $pairs[] = ['flag', $flag];
            }
        }
        // Folder-derived circle/author and the _series parody. The pivot sync de-dupes identical
        // (type,value) ids, so a folder value matching the filename collapses to one tag.
        foreach ($parsed->extraTags as [$type, $value]) {
            if ($value !== null && $value !== '') {
                $pairs[] = [$type, $value];
            }
        }

        return $pairs;
    }

    /** Resolve (type,value) to its canonical tag id, memoised per run. / 正規タグIDへ解決（メモ化）。 */
    private function canonicalId(string $type, string $value): int
    {
        return $this->canonicalCache[$type."\0".$value] ??= Tag::canonicalIdFor($type, $value);
    }

    /** Delete canonical tags with no works that aren't a merge target. / 孤立タグ削除。 */
    public function pruneOrphans(): int
    {
        // Two-step (select ids, then delete by id). MySQL rejects a DELETE that references the
        // deleted table in a subquery (error 1093) — the aliases check does exactly that. SQLite
        // allows it, but this keeps it portable. / MySQLの1093回避のため2段階で削除。
        $ids = Tag::query()
            ->whereNull('merged_into_id')
            ->whereDoesntHave('works')
            ->whereDoesntHave('aliases')
            ->pluck('id');

        return $ids->isEmpty() ? 0 : Tag::whereKey($ids)->delete();
    }
}
