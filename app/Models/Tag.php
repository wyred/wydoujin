<?php

namespace App\Models;

use App\Parsing\ParsedName;
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

    /** All types. AUTO_TYPES are scanner-derived; others are manual-only. / 全タイプ。 */
    public const TYPES = ['circle', 'parody', 'event', 'author', 'flag', 'theme'];
    public const AUTO_TYPES = ['circle', 'parody', 'event', 'author', 'flag'];

    protected $guarded = [];

    protected static function booted(): void
    {
        // Derive sort_value from value when not supplied. / 未指定ならvalueから導出。
        static::creating(function (Tag $tag): void {
            if (($tag->sort_value ?? '') === '') {
                $tag->sort_value = ParsedName::deriveSortTitle((string) $tag->value);
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

    /** Deep-link to /browse pre-filtered by this tag. / このタグで絞った/browseへのリンク。 */
    public function browseUrl(): string
    {
        return '/browse?'.http_build_query([$this->type => [$this->value]]);
    }
}
