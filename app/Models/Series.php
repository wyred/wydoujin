<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Series extends Model
{
    use HasFactory;

    protected $table = 'series';
    protected $guarded = [];
    protected $casts = ['is_auto' => 'boolean'];

    public function mangaka(): BelongsTo
    {
        return $this->belongsTo(Mangaka::class);
    }

    public function works(): HasMany
    {
        return $this->hasMany(Work::class);
    }

    /** Delete a mangaka's auto series that now have no works. / 空の自動シリーズを削除。 */
    public static function pruneEmptyAuto(int $mangakaId): int
    {
        return self::where('mangaka_id', $mangakaId)
            ->where('is_auto', true)
            ->whereDoesntHave('works')
            ->delete();
    }
}
