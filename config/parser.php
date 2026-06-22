<?php

use App\Parsing\Patterns\FallbackPattern;
use App\Parsing\Patterns\StandardDoujinPattern;

return [
    /*
     | Ordered filename-parsing patterns. First match wins; the LAST must be a
     | catch-all. Add a class here to support a new naming quirk — no rewrites.
     | ファイル名解析パターン（順序付き）。最初の一致を採用。末尾は必ず全一致。
     */
    'patterns' => [
        StandardDoujinPattern::class,
        FallbackPattern::class,
    ],
];
