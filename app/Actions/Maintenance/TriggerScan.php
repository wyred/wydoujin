<?php

namespace App\Actions\Maintenance;

use App\Jobs\ScanLibrary;
use App\Models\Scan;

/**
 * Trigger a full library scan, deduping against an already-active one. Single-user,
 * so the check-then-create race is tolerated (a redundant scan reconciles). / スキャン起動。
 */
final class TriggerScan
{
    public function handle(string $triggeredBy = 'manual'): Scan
    {
        $active = Scan::active()->latest()->first();
        if ($active) {
            return $active;
        }

        $scan = Scan::create(['status' => 'queued', 'triggered_by' => $triggeredBy]);
        ScanLibrary::dispatch($triggeredBy, $scan->id);

        return $scan;
    }
}
