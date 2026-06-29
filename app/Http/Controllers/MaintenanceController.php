<?php

namespace App\Http\Controllers;

use App\Actions\Maintenance\TriggerScan;
use App\Models\Scan;
use App\Models\Work;

/** Library maintenance: scan trigger + status/history + missing works (F3b). / ライブラリ保守。 */
final class MaintenanceController extends Controller
{
    private const HISTORY_LIMIT = 20;

    public function index()
    {
        $missing = Work::query()
            ->missing()
            ->with('mangaka')
            ->orderBy('mangaka_id')
            ->orderBy('sort_title')
            ->paginate(50);

        return view('maintenance.index', [
            'latest' => $this->serialize(Scan::latest()->first()),
            'history' => Scan::latest()->limit(self::HISTORY_LIMIT)->get()
                ->map(fn (Scan $s) => $this->serialize($s))->all(),
            'missing' => $missing,
            'missingCount' => $missing->total(),
        ]);
    }

    public function scan(TriggerScan $action)
    {
        // No second scan while one is queued/running; the action dedupes. / 二重起動防止。
        return response()->json(['scan' => $this->serialize($action->handle('manual'))], 202);
    }

    public function fullScan(TriggerScan $action)
    {
        // Clean-slate wipe + forced re-derive; the action dedupes against an active scan. / 全消去後に再走査。
        return response()->json(['scan' => $this->serialize($action->handle('full', force: true))], 202);
    }

    public function status()
    {
        return response()->json(['scan' => $this->serialize(Scan::latest()->first())]);
    }

    /** @return array<string,mixed>|null */
    private function serialize(?Scan $scan): ?array
    {
        if ($scan === null) {
            return null;
        }

        return [
            'id' => $scan->id,
            'status' => $scan->status,
            'triggered_by' => $scan->triggered_by,
            'stats' => $scan->stats,
            'started_at' => $scan->started_at?->toIso8601String(),
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'created_at' => $scan->created_at?->toIso8601String(),
        ];
    }
}
