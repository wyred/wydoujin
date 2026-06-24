<?php

namespace App\Models;

use App\Support\SortKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A normalized metadata value (type + value), linked to works via work_tag. / 正規化タグ。
 * A row with merged_into_id set is a tombstone alias pointing at the canonical
 * tag and holds no work_tag rows. / merged_into_id付きは正規タグを指す別名(墓石)。
 */
class Tag extends Model
{
    use HasFactory;

    /** Scalar metadata types — one value per work, scanner-derived. / スカラー型（作品毎1値）。 */
    public const SCALAR_TYPES = ['circle', 'parody', 'event', 'author'];

    /** Scanner-derived types: scalars + multi-valued flags. / スキャナ由来の型。 */
    public const AUTO_TYPES = [...self::SCALAR_TYPES, 'flag'];

    /** All tag types: auto (scanner) + manual-only theme. / 全タイプ（自動＋手動theme）。 */
    public const TYPES = [...self::AUTO_TYPES, 'theme'];

    protected $guarded = [];

    protected $casts = [
        // bigint FK; MySQL returns it as a string, SQLite as int — cast for consistency. / 型を統一。
        'merged_into_id' => 'integer',
    ];

    protected static function booted(): void
    {
        // Derive sort_value from value when not supplied. / 未指定ならvalueから導出。
        static::creating(function (Tag $tag): void {
            if (($tag->sort_value ?? '') === '') {
                $tag->sort_value = SortKey::derive((string) $tag->value);
            }
        });
    }

    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'work_tag');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** Inbound aliases (tombstones pointing here). / このタグを指す別名。 */
    public function aliases(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /** Canonical (non-alias) tags only. / 正規タグのみ。 */
    public function scopeCanonical(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }

    /**
     * firstOrCreate the (type,value) tag and resolve merge-alias tombstones to
     * the canonical tag id. The loop tolerates (and a visited-set breaks) chains
     * deeper than one hop, so an attach can never land on a tombstone.
     * (type,value)タグを取得/作成し、別名を辿って正規IDを返す（多段でも安全）。
     */
    public static function canonicalIdFor(string $type, string $value): int
    {
        // firstOrCreate routes through createOrFirst, which is already race-safe (it catches a
        // unique-violation from a concurrent insert and re-reads). / 競合は本体で安全に解決。
        $tag = self::firstOrCreate(['type' => $type, 'value' => $value]);

        // Follow the alias chain. The FK guarantees each target exists; the visited
        // set only guards against a (shouldn't-happen) cycle. / 別名連鎖を辿る。
        $seen = [];
        while ($tag->merged_into_id !== null && ! isset($seen[$tag->id])) {
            $seen[$tag->id] = true;
            $tag = self::findOrFail($tag->merged_into_id);
        }

        return (int) $tag->id;
    }

    /** Deep-link to /browse pre-filtered by this tag. / このタグで絞った/browseへのリンク。 */
    public function browseUrl(): string
    {
        return '/browse?'.http_build_query([$this->type => [$this->value]]);
    }
}
