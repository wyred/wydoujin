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

    // Per-job timeout (seconds) for the ScanLibrary job. A full scan is O(library size)
    // — it opens each new/changed zip's central directory and writes per-work rows — so it
    // routinely outlives the queue's default 60s. Cover decoding is offloaded, but a huge
    // first scan still needs a generous budget. Keep DB_QUEUE_RETRY_AFTER above this.
    // スキャンは規模依存で長い。既定60sでは足りないため専用の長いタイムアウト。
    'scan_timeout' => (int) env('SCAN_TIMEOUT', 3600),

    'image_mime_types' => $imageMimeTypes,
    'image_extensions' => array_keys($imageMimeTypes), // lowercase; indexed by the scanner / 索引対象（小文字）。

    'cover' => [
        'width' => (int) env('SCAN_COVER_WIDTH', 400),
        'quality' => (int) env('SCAN_COVER_QUALITY', 80),
    ],

    // Hardening caps against malicious archives/images (untrusted file content). / 悪意あるzip/画像への上限。
    'limits' => [
        'max_entries' => (int) env('SCAN_MAX_ENTRIES', 10000),             // entries per zip / zip内エントリ数
        'max_entry_bytes' => (int) env('SCAN_MAX_ENTRY_BYTES', 52428800),  // 50 MB decompressed / 展開後サイズ
        'max_image_pixels' => (int) env('SCAN_MAX_IMAGE_PIXELS', 40000000), // 40 MP cover decode / 表紙デコード画素数
    ],
];
