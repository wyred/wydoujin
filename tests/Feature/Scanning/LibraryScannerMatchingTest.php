<?php

namespace Tests\Feature\Scanning;

use App\Models\ReadingProgress;
use App\Models\Work;
use App\Scanning\LibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryScannerMatchingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLibraryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLibrary();
    }

    protected function tearDown(): void
    {
        $this->cleanLibrary();
        parent::tearDown();
    }

    private function scanner(): LibraryScanner
    {
        return app(LibraryScanner::class);
    }

    public function test_fresh_scan_creates_works_with_parsed_metadata_and_cover(): void
    {
        $this->makeDoujin('Z.A.P.', '(C89) [Z.A.P. (ズッキーニ)] 四畳半物語 (オリジナル) [DL版]');

        $stats = $this->scanner()->scan();

        $this->assertSame(1, $stats['added']);
        $work = Work::firstOrFail();
        $this->assertSame('四畳半物語', $work->title);
        $this->assertSame('C89', $work->event);
        $this->assertSame('Z.A.P.', $work->circle);
        $this->assertSame('ズッキーニ', $work->author);
        $this->assertSame('オリジナル', $work->parody);
        $this->assertSame(['DL版'], $work->flags);
        $this->assertSame(2, $work->page_count);
        $this->assertSame(['001.jpg', '002.jpg'], $work->entries);
        $this->assertSame('Z.A.P.', $work->mangaka->name);
        $this->assertNotEmpty($work->mangaka->slug);
        $this->assertNotNull($work->cover_path);
        $this->assertFileExists($this->dataPath.'/'.$work->cover_path);
    }

    public function test_japanese_mangaka_folder_gets_nonempty_slug(): void
    {
        $this->makeDoujin('ズッキーニ', 'タイトル');

        $this->scanner()->scan();

        $work = Work::firstOrFail();
        $this->assertSame('ズッキーニ', $work->mangaka->name);
        $this->assertNotEmpty($work->mangaka->slug);
        $this->assertStringStartsWith('mangaka-', $work->mangaka->slug);
    }

    public function test_rescan_unchanged_file_is_skipped(): void
    {
        $this->makeDoujin('Circle', 'Title');
        $first = $this->scanner()->scan();
        $this->assertSame(1, $first['added']);

        $second = $this->scanner()->scan();
        $this->assertSame(0, $second['added']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(0, $second['moved']);
        $this->assertSame(1, Work::count());
    }

    public function test_moved_file_keeps_reading_progress(): void
    {
        $path = $this->makeDoujin('OldCircle', 'Title');
        $this->scanner()->scan();
        $work = Work::firstOrFail();
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 7]);

        // Move the zip to a different mangaka folder (same bytes → same content_hash).
        $newDir = $this->libraryPath.'/NewCircle';
        mkdir($newDir, 0775, true);
        rename($path, $newDir.'/Title.zip');

        $stats = $this->scanner()->scan();

        $this->assertSame(1, $stats['moved']);
        $this->assertSame(1, Work::count());
        $work->refresh();
        $this->assertSame('NewCircle/Title.zip', $work->relative_path);
        $this->assertSame('NewCircle', $work->mangaka->name);
        $this->assertSame(7, $work->readingProgress->current_page); // progress preserved
    }
}
