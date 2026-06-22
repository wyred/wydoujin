<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mangaka extends Model
{
    use HasFactory;

    protected $table = 'mangaka';
    protected $guarded = [];

    public function works(): HasMany
    {
        return $this->hasMany(Work::class);
    }

    public function series(): HasMany
    {
        return $this->hasMany(Series::class);
    }
}
