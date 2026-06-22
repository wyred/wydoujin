<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves a cached cover webp from the data root by content-hash. / data領域の表紙webpを配信。 */
final class CoverController extends Controller
{
    public function show(string $hash): BinaryFileResponse
    {
        $path = config('scan.data_path').'/covers/'.$hash.'.webp';
        if (! is_file($path)) {
            abort(404);
        }

        // Explicit content-type (don't sniff bytes); covers are immutable per content_hash.
        // Content-Typeは明示（内容推測しない）。表紙はcontent_hash毎に不変。
        return response()->file($path, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
