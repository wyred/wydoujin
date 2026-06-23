<?php

uses(Tests\Feature\Reader\ServesReadableWork::class);

beforeEach(function (): void {
    $this->setUpReaderEnv();
});

afterEach(function (): void {
    $this->tearDownReaderEnv();
});

test('serves an existing cover as webp', function (): void {
    $hash = str_repeat('a', 64);
    $this->writeCover($hash, 'the-cover-bytes');

    $this->get("/covers/{$hash}.webp")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
});

test('missing cover is 404', function (): void {
    $this->get('/covers/'.str_repeat('b', 64).'.webp')->assertNotFound();
});

test('non hash path does not match the route', function (): void {
    // The [0-9a-f]{64} constraint rejects traversal / non-hex names → no route → 404.
    $this->get('/covers/not-a-valid-hash.webp')->assertNotFound();
});
