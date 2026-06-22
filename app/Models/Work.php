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
