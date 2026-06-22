<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingProgress extends Model
{
    protected $table = 'reading_progress';
    protected $guarded = [];

    protected $casts = [
        'is_completed' => 'boolean',
        'current_page' => 'integer',
        'started_at' => 'datetime',
        'last_read_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }
}
