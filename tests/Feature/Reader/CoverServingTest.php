<?php

namespace Tests\Feature\Reader;

use Tests\TestCase;

class CoverServingTest extends TestCase
{
    use ServesReadableWork;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReaderEnv();
    }

    protected function tearDown(): void
    {
        $this->tearDownReaderEnv();
        parent::tearDown();
    }

    public function test_serves_an_existing_cover_as_webp(): void
    {
        $hash = str_repeat('a', 64);
        $this->writeCover($hash, 'the-cover-bytes');

        $this->get("/covers/{$hash}.webp")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_missing_cover_is_404(): void
    {
        $this->get('/covers/'.str_repeat('b', 64).'.webp')->assertNotFound();
    }

    public function test_non_hash_path_does_not_match_the_route(): void
    {
        // The [0-9a-f]{64} constraint rejects traversal / non-hex names → no route → 404.
        $this->get('/covers/not-a-valid-hash.webp')->assertNotFound();
    }
}
