<?php

namespace Tests\Feature\Scanning;

use App\Models\ReadingProgress;
use App\Models\Work;
use App\Scanning\LibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryScannerMissingTest extends TestCase
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

    public function test_removed_file_is_flagged_missing_and_keeps_progress(): void
    {
        $path = $this->makeDoujin('Circle', 'Title');
        app(LibraryScanner::class)->scan();
        $work = Work::firstOrFail();
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 3]);

        unlink($path); // file disappears
        $stats = app(LibraryScanner::class)->scan();

        $this->assertSame(1, $stats['missing']);
        $work->refresh();
        $this->assertTrue($work->is_missing);
        $this->assertSame(3, $work->readingProgress->current_page); // never deleted
    }

    public function test_reappeared_file_is_unflagged(): void
    {
        $this->makeDoujin('Circle', 'Title', ['001.jpg']);
        app(LibraryScanner::class)->scan();
        $work = Work::firstOrFail();
        $work->update(['is_missing' => true]); // simulate previously missing

        $stats = app(LibraryScanner::class)->scan();

        $work->refresh();
        $this->assertFalse($work->is_missing);
        $this->assertSame(0, $stats['missing']);
    }

    public function test_corrupt_zip_increments_failed_and_scan_continues(): void
    {
        $this->makeDoujin('Circle', 'Good', ['001.jpg']);
        // A .zip that is not a valid archive.
        file_put_contents($this->libraryPath.'/Circle/Bad.zip', 'not a zip');

        $stats = app(LibraryScanner::class)->scan();

        $this->assertSame(1, $stats['added']);  // the good one
        $this->assertSame(1, $stats['failed']); // the bad one
        $this->assertSame(1, Work::count());
    }

    public function test_content_replaced_at_same_path_adds_new_work_and_flags_old_missing(): void
    {
        $path = $this->makeDoujin('Circle', 'Title', ['001.jpg']);
        app(LibraryScanner::class)->scan();
        $old = Work::firstOrFail();
        $oldHash = $old->content_hash;

        // Replace the zip's CONTENT at the same path (different entry list → different content_hash).
        unlink($path);
        $this->makeDoujin('Circle', 'Title', ['001.jpg', '002.jpg', '003.jpg']);

        $stats = app(LibraryScanner::class)->scan();

        $this->assertSame(1, $stats['added']);   // new content = new work
        $this->assertSame(1, $stats['missing']); // old content gone → missing
        $this->assertSame(2, Work::count());
        $old->refresh();
        $this->assertTrue($old->is_missing);                          // old flagged missing
        $this->assertSame($oldHash, $old->content_hash);              // its identity unchanged
        $new = Work::where('is_missing', false)->firstOrFail();
        $this->assertNotSame($oldHash, $new->content_hash);           // genuinely new identity
        $this->assertSame($old->relative_path, $new->relative_path);  // share the path (benign; old is hidden)
    }
}
