<?php

namespace App\Http\Controllers;

use App\Jobs\ScanLibrary;
use App\Models\Scan;
use App\Models\Work;

/** Library maintenance: scan trigger + status/history + missing works (F3b). / ライブラリ保守。 */
final class MaintenanceController extends Controller
{
    private const HISTORY_LIMIT = 20;

    public function index()
    {
        $missing = Work::query()
            ->where('is_missing', true)
            ->with('mangaka')
            ->orderBy('mangaka_id')
            ->orderBy('sort_title')
            ->paginate(50);

        return view('maintenance.index', [
            'latest' => $this->serialize(Scan::latest()->first()),
            'history' => Scan::latest()->limit(self::HISTORY_LIMIT)->get()
                ->map(fn (Scan $s) => $this->serialize($s))->all(),
            'missing' => $missing,
            'missingCount' => Work::where('is_missing', true)->count(),
        ]);
    }

    public function scan()
    {
        // No second scan while one is queued/running. / 二重起動防止。
        $active = Scan::whereIn('status', ['queued', 'running'])->latest()->first();
        if ($active) {
            return response()->json(['scan' => $this->serialize($active)], 202);
        }

        $scan = Scan::create(['status' => 'queued', 'triggered_by' => 'manual']);
        ScanLibrary::dispatch('manual', $scan->id);

        return response()->json(['scan' => $this->serialize($scan)], 202);
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
