<?php

namespace App\Tagging;

use App\Support\SortKey;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill of the legacy scalar metadata columns into the tag tables.
 * Query-builder only (model-independent, portable). Idempotent. / 旧カラム→タグ移行。
 */
final class LegacyScalarBackfill
{
    public function run(): void
    {
        DB::table('works')->orderBy('id')->each(function (object $work): void {
            $pairs = [];
            // Mirrors Tag::SCALAR_TYPES; kept inline so this migration helper stays
            // model-independent. / Tag::SCALAR_TYPESと同一（移行用のため意図的にインライン）。
            foreach (['circle', 'parody', 'event', 'author'] as $type) {
                $value = $work->{$type} ?? null;
                if ($value !== null && $value !== '') {
                    $pairs[] = [$type, (string) $value];
                }
            }
            foreach ((array) json_decode($work->flags ?? '[]', true) as $flag) {
                if ($flag !== '' && $flag !== null) {
                    $pairs[] = ['flag', (string) $flag];
                }
            }
            foreach ($pairs as [$type, $value]) {
                $tagId = $this->tagId($type, $value);
                DB::table('work_tag')->insertOrIgnore(['work_id' => $work->id, 'tag_id' => $tagId]);
            }
        });
    }

    /** Select-or-insert a canonical tag, returning its id. / 正規タグをselect-or-insert。 */
    private function tagId(string $type, string $value): int
    {
        $existing = DB::table('tags')->where('type', $type)->where('value', $value)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }
        $now = now();

        return (int) DB::table('tags')->insertGetId([
            'type' => $type,
            'value' => $value,
            'sort_value' => SortKey::derive($value),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
