<?php

namespace Tests\Feature\Browse;

use App\Models\Mangaka;
use App\Models\Series;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MangakaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_index_lists_mangaka_with_work_counts(): void
    {
        $m = Mangaka::factory()->create(['name' => 'Z.A.P.']);
        Work::factory()->for($m)->create();
        Work::factory()->for($m)->create();

        $this->get('/mangaka')->assertOk()
            ->assertSee('Z.A.P.')
            ->assertSee('href="/mangaka/'.$m->slug.'"', false);
    }

    public function test_index_empty_state(): void
    {
        $this->get('/mangaka')->assertOk()->assertSee('No mangaka');
    }

    public function test_show_separates_series_and_standalone_works(): void
    {
        $m = Mangaka::factory()->create(['name' => 'CircleA']);
        $series = Series::factory()->for($m)->create(['name' => 'MyShelf']);
        Work::factory()->for($m)->create(['title' => 'SeriesVol1', 'series_id' => $series->id, 'sort_title' => 'SeriesVol1']);
        Work::factory()->for($m)->create(['title' => 'LoneWork', 'series_id' => null, 'sort_title' => 'LoneWork']);

        $this->get('/mangaka/'.$m->slug)->assertOk()
            ->assertSee('MyShelf')
            ->assertSee('href="/series/'.$series->id.'"', false)
            ->assertSee('LoneWork')
            ->assertSee('href="/work/'.Work::where('title', 'LoneWork')->first()->id.'"', false);
    }
}
