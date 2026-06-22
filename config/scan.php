<?php

return [
    // Where the library lives (mounted read-only in prod). / ライブラリの場所。
    'library_path' => env('LIBRARY_PATH', '/library'),

    // Writable data root; covers go in <data_path>/covers. / 書き込み可能データ領域。
    'data_path' => env('DATA_PATH', '/data'),

    // Indexed image extensions (lowercase). / 索引対象の画像拡張子。
    'image_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'],

    'cover' => [
        'width' => (int) env('SCAN_COVER_WIDTH', 400),
        'quality' => (int) env('SCAN_COVER_QUALITY', 80),
    ],
];
