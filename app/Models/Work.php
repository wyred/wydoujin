<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Work extends Model
{
    use HasFactory;

    /** Relations every work-card needs (avoids N+1). / workカード描画に必要な関連。 */
    public const CARD_RELATIONS = ['readingProgress', 'tags'];

    protected $guarded = [];

    protected $casts = [
        'entries' => 'array',
        'is_missing' => 'boolean',
        'series_locked' => 'boolean',
        'tags_locked' => 'boolean',
        'last_seen_at' => 'datetime',
        'page_count' => 'integer',
        'file_size' => 'integer',
        'file_mtime' => 'integer',
    ];

    public function mangaka(): BelongsTo
    {
        return $this->belongsTo(Mangaka::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function readingProgress(): HasOne
    {
        return $this->hasOne(ReadingProgress::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'work_tag');
    }

    /** Present on disk (not swept missing). / ディスク上に存在（欠落していない）。 */
    public function scopePresent(Builder $query): void
    {
        $query->where('is_missing', false);
    }

    /** Swept as missing (file gone, row + progress kept). / 欠落（ファイル消失、行と進捗は保持）。 */
    public function scopeMissing(Builder $query): void
    {
        $query->where('is_missing', true);
    }
}
