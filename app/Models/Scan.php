<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    /** Lifecycle states; ACTIVE_STATUSES are the not-yet-finished ones. / 状態。 */
    public const STATUSES = ['queued', 'running', 'completed', 'failed'];
    public const ACTIVE_STATUSES = ['queued', 'running'];

    /** How a scan was triggered. / 起動経路。 */
    public const TRIGGERS = ['manual', 'scheduled'];

    protected $guarded = [];

    protected $casts = [
        'stats' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** Scans not yet finished (queued or running). / 未完了スキャン。 */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
