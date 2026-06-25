<?php

namespace App\Http\Controllers;

use App\Actions\Series\AddWorksToSeries;
use App\Actions\Series\GroupWorks;
use App\Actions\Series\RenameSeries;
use App\Actions\Series\UngroupWorks;
use App\Models\Series;
use Illuminate\Http\Request;

/**
 * Manual series management — group / add / ungroup / rename (F3c). / 手動シリーズ管理。
 *
 * DB-only (never touches /library). Every op sets series_locked=true (+ is_auto=false
 * on touched series) so SeriesDetector::detect() never undoes the manual decision.
 * The actual work lives in app/Actions/Series so the API shares it. / 実体はActionへ。
 */
final class SeriesManagementController extends Controller
{
    public function group(Request $request, GroupWorks $action)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $series = $action->handle($data['work_ids'], $data['name']);

        return response()->json(['series_id' => $series->id], 201);
    }

    public function add(Request $request, Series $series, AddWorksToSeries $action)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
        ]);

        $action->handle($series, $data['work_ids']);

        return response()->json(['ok' => true]);
    }

    public function ungroup(Request $request, UngroupWorks $action)
    {
        $data = $request->validate([
            'work_ids' => ['required', 'array', 'min:1'],
            'work_ids.*' => ['integer'],
        ]);

        $action->handle($data['work_ids']);

        return response()->json(['ok' => true]);
    }

    public function rename(Request $request, Series $series, RenameSeries $action)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $action->handle($series, $data['name']);

        return response()->json(['ok' => true]);
    }
}
