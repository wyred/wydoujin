<?php

namespace App\Http\Controllers;

use App\Archive\ArchiveException;
use App\Archive\ZipPageReader;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Streams a work's page bytes straight from its zip. / zipからページ画像を直接配信。
 * Bytes are buffered in memory (no Range support), unlike CoverController's file
 * response — pages can't be a cheap file path. / メモリ配信（Range非対応）。
 */
final class PageController extends Controller
{
    public function show(Request $request, Work $work, int $n, ZipPageReader $reader): Response
    {
        $entries = $work->entries ?? [];
        if ($n < 1 || $n > count($entries)) {
            abort(404);
        }
        $entryName = $entries[$n - 1];

        // ETag from identity (content_hash) + page — immutable, so conditional GETs 304 cheaply.
        // 同一性(content_hash)+ページのETag。不変なので条件付きGETは304で安価に返す。
        $response = new Response();
        $response->setEtag($work->content_hash.'-'.$n);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        if ($response->isNotModified($request)) {
            return $response; // 304, body skipped, zip never opened
        }

        try {
            $bytes = $reader->read(config('scan.library_path').'/'.$work->relative_path, $entryName);
        } catch (ArchiveException $e) {
            report($e); // log corrupt/unreadable archives; client still gets 404 / 破損は記録し404
            abort(404);
        }

        $ext = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
        $response->setContent($bytes);
        $response->headers->set('Content-Type', config('scan.image_mime_types')[$ext] ?? 'application/octet-stream');

        return $response;
    }
}
