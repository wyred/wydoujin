<?php

// Image extension → served Content-Type. Single source for both the scanner's
// indexed-extension list and PageController's MIME lookup. / 画像拡張子→MIME（単一ソース）。
$imageMimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
];

return [
    // Where the library lives (mounted read-only in prod). / ライブラリの場所。
    'library_path' => env('LIBRARY_PATH', '/library'),

    // Writable data root; covers go in <data_path>/covers. / 書き込み可能データ領域。
    'data_path' => env('DATA_PATH', '/data'),

    'image_mime_types' => $imageMimeTypes,
    'image_extensions' => array_keys($imageMimeTypes), // lowercase; indexed by the scanner / 索引対象（小文字）。

    'cover' => [
        'width' => (int) env('SCAN_COVER_WIDTH', 400),
        'quality' => (int) env('SCAN_COVER_QUALITY', 80),
    ],
];
