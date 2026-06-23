<?php

use App\Models\Mangaka;
use App\Models\Work;

uses(Tests\Feature\Reader\ServesReadableWork::class);

beforeEach(function (): void {
    $this->setUpReaderEnv();
});

afterEach(function (): void {
    $this->tearDownReaderEnv();
});

test('serves the correct page bytes and content type', function (): void {
    $work = $this->makeReadableWork(['001.jpg', '002.png']);

    $p1 = $this->get("/work/{$work->id}/page/1");
    $p1->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->assertSame($this->entryBytes('001.jpg'), $p1->getContent());

    $p2 = $this->get("/work/{$work->id}/page/2");
    $p2->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->assertSame($this->entryBytes('002.png'), $p2->getContent());
});

test('sets content hash etag and returns 304 on match', function (): void {
    $work = $this->makeReadableWork(['001.jpg']);
    $etag = '"'.$work->content_hash.'-1"';

    $this->get("/work/{$work->id}/page/1")->assertOk()->assertHeader('ETag', $etag);

    $this->withHeaders(['If-None-Match' => $etag])
        ->get("/work/{$work->id}/page/1")
        ->assertStatus(304);
});

test('out of range page is 404', function (): void {
    $work = $this->makeReadableWork(['001.jpg', '002.png']); // page_count 2

    $this->get("/work/{$work->id}/page/3")->assertNotFound();
    $this->get("/work/{$work->id}/page/0")->assertNotFound();
});

test('missing zip file is 404', function (): void {
    $mangaka = Mangaka::factory()->create();
    $work = Work::factory()->for($mangaka)->create([
        'relative_path' => 'gone/missing.zip', // never built on disk
        'entries' => ['001.jpg'],
        'page_count' => 1,
    ]);

    $this->get("/work/{$work->id}/page/1")->assertNotFound();
});
