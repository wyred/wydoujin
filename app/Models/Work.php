<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Work extends Model
{
    use HasFactory;

    protected $guarded = [];

    // Store datetimes with microsecond precision so missing-sweep comparisons are exact.
    // / 欠落スイープの比較精度を保つためマイクロ秒で保存。
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'flags' => 'array',
        'entries' => 'array',
        'is_missing' => 'boolean',
        'series_locked' => 'boolean',
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
}
