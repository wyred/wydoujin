<?php

namespace Tests\Feature\Reader;

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageServingTest extends TestCase
{
    use RefreshDatabase;
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

    public function test_serves_the_correct_page_bytes_and_content_type(): void
    {
        $work = $this->makeReadableWork(['001.jpg', '002.png']);

        $p1 = $this->get("/work/{$work->id}/page/1");
        $p1->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($this->entryBytes('001.jpg'), $p1->getContent());

        $p2 = $this->get("/work/{$work->id}/page/2");
        $p2->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($this->entryBytes('002.png'), $p2->getContent());
    }

    public function test_sets_content_hash_etag_and_returns_304_on_match(): void
    {
        $work = $this->makeReadableWork(['001.jpg']);
        $etag = '"'.$work->content_hash.'-1"';

        $this->get("/work/{$work->id}/page/1")->assertOk()->assertHeader('ETag', $etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get("/work/{$work->id}/page/1")
            ->assertStatus(304);
    }

    public function test_out_of_range_page_is_404(): void
    {
        $work = $this->makeReadableWork(['001.jpg', '002.png']); // page_count 2

        $this->get("/work/{$work->id}/page/3")->assertNotFound();
        $this->get("/work/{$work->id}/page/0")->assertNotFound();
    }

    public function test_missing_zip_file_is_404(): void
    {
        $mangaka = Mangaka::factory()->create();
        $work = Work::factory()->for($mangaka)->create([
            'relative_path' => 'gone/missing.zip', // never built on disk
            'entries' => ['001.jpg'],
            'page_count' => 1,
        ]);

        $this->get("/work/{$work->id}/page/1")->assertNotFound();
    }
}
