<?php

namespace Tests\Feature\Browse;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesAndWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_series_lists_works_in_sort_order(): void
    {
        $m = Mangaka::factory()->create();
        $series = Series::factory()->for($m)->create(['name' => 'TheSeries']);
        Work::factory()->for($m)->create(['title' => 'Bravo', 'series_id' => $series->id, 'sort_title' => 'Bravo']);
        Work::factory()->for($m)->create(['title' => 'Alpha', 'series_id' => $series->id, 'sort_title' => 'Alpha']);

        $res = $this->get('/series/'.$series->id)->assertOk()
            ->assertSee('TheSeries')->assertSee('Alpha')->assertSee('Bravo');
        $this->assertTrue(strpos($res->getContent(), 'Alpha') < strpos($res->getContent(), 'Bravo'));
    }

    public function test_work_detail_shows_metadata_badges_progress_and_read_cta(): void
    {
        $m = Mangaka::factory()->create(['name' => 'Z.A.P.']);
        $work = Work::factory()->for($m)->create([
            'title' => '四畳半物語', 'circle' => 'Z.A.P.', 'author' => 'ズッキーニ',
            'parody' => 'オリジナル', 'event' => 'C89', 'flags' => ['DL版'],
            'page_count' => 24, 'cover_path' => 'covers/h.webp',
        ]);
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 3]);

        $this->get('/work/'.$work->id)->assertOk()
            ->assertSee('四畳半物語')
            ->assertSee('ズッキーニ')
            ->assertSee('オリジナル')       // parody badge
            ->assertSee('C89')              // event badge
            ->assertSee('DL版')             // flag badge
            ->assertSee('24 pages')         // page count
            ->assertSee('3/24')             // progress
            ->assertSee('href="/work/'.$work->id.'/read"', false)   // Read CTA → reader
            ->assertSee('Continue');                                // dynamic label (in-progress)
    }
}
