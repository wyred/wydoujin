<?php

namespace App\Http\Controllers\Api;

use App\Actions\Maintenance\TriggerScan;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScanResource;
use App\Models\Scan;

/** Library scan trigger + status. / スキャン起動・状態API。 */
final class ScanController extends Controller
{
    public function store(TriggerScan $action)
    {
        return (new ScanResource($action->handle('manual')))->response()->setStatusCode(202);
    }

    public function show()
    {
        $scan = Scan::latest()->first();

        return $scan ? new ScanResource($scan) : response()->json(['data' => null]);
    }
}
