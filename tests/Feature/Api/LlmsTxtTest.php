<?php

test('llms.txt documents the api without leaking the token', function (): void {
    $path = public_path('llms.txt');
    expect($path)->toBeFile();

    $txt = file_get_contents($path);
    expect($txt)->toStartWith('# wydoujin API');

    foreach ([
        'Authorization: Bearer',
        '/api/v1',
        'GET /works',
        'POST /tags/attach',
        'POST /series',
    ] as $needle) {
        expect($txt)->toContain($needle);
    }

    // The configured token must never be baked into the public file.
    config(['app.api_token' => 'super-secret-token-value']);
    expect($txt)->not->toContain('super-secret-token-value');
});
