<?php

namespace App\Http\Controllers;

use App\Archive\ArchiveException;
use App\Archive\ZipPageReader;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Streams a work's page bytes straight from its zip. / zipからページ画像を直接配信。 */
final class PageController extends Controller
{
    /** Entry-extension → content-type. / 拡張子→Content-Type。 */
    private const MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

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
        } catch (ArchiveException) {
            abort(404);
        }

        $ext = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
        $response->setContent($bytes);
        $response->headers->set('Content-Type', self::MIME[$ext] ?? 'application/octet-stream');

        return $response;
    }
}
