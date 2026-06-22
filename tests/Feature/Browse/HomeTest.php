<?php

namespace Tests\Feature\Browse;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_empty_library_shows_scan_prompt(): void
    {
        $this->get('/')->assertOk()->assertSee('wydoujin:scan');
    }

    public function test_continue_reading_shows_only_in_progress_newest_first(): void
    {
        $m = Mangaka::factory()->create();
        $inProgressOld = Work::factory()->for($m)->create(['title' => 'OldProgress', 'page_count' => 10]);
        $inProgressNew = Work::factory()->for($m)->create(['title' => 'NewProgress', 'page_count' => 10]);
        $completed = Work::factory()->for($m)->create(['title' => 'DoneWork', 'page_count' => 10]);
        $notStarted = Work::factory()->for($m)->create(['title' => 'FreshWork', 'page_count' => 10]);

        ReadingProgress::create(['work_id' => $inProgressOld->id, 'current_page' => 3, 'last_read_at' => now()->subDay()]);
        ReadingProgress::create(['work_id' => $inProgressNew->id, 'current_page' => 4, 'last_read_at' => now()]);
        ReadingProgress::create(['work_id' => $completed->id, 'current_page' => 10, 'is_completed' => true, 'last_read_at' => now()]);

        $content = $this->get('/')->assertOk()->assertSee('Continue Reading')->getContent();

        // Scope to the Continue Reading section (it precedes Recently Added in the HTML).
        // completed/never-started works legitimately appear in Recently Added, so a
        // whole-page assertDontSee would be wrong — assert against the section only.
        $start = strpos($content, 'Continue Reading');
        $cr = substr($content, $start, strpos($content, 'Recently Added') - $start);

        $this->assertStringContainsString('NewProgress', $cr);
        $this->assertStringContainsString('OldProgress', $cr);
        $this->assertStringNotContainsString('DoneWork', $cr);   // completed excluded
        $this->assertStringNotContainsString('FreshWork', $cr);  // never-started excluded
        $this->assertTrue(strpos($cr, 'NewProgress') < strpos($cr, 'OldProgress')); // newest first
    }

    public function test_recently_added_lists_works_and_hides_missing(): void
    {
        $m = Mangaka::factory()->create();
        Work::factory()->for($m)->create(['title' => 'ShownWork']);
        Work::factory()->for($m)->create(['title' => 'GhostWork', 'is_missing' => true]);

        $this->get('/')->assertOk()
            ->assertSee('Recently Added')
            ->assertSee('ShownWork')
            ->assertDontSee('GhostWork');
    }
}
