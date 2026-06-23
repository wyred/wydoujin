<?php

namespace Tests\Feature\Series;

use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesManageUiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMangakaWorks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_mangaka_page_embeds_manage_data_and_button(): void
    {
        $w = $this->seedWork('Z.A.P.', '四畳半物語');
        $slug = $w->mangaka->slug;

        $this->get('/mangaka/'.$slug)->assertOk()
            ->assertSee('四畳半物語')      // work title (normal view + embedded manage data)
            ->assertSee('Manage')          // manage toggle
            ->assertSee('seriesManager(', false); // Alpine manage component wired
    }

    public function test_series_page_has_inline_rename(): void
    {
        $m = $this->mangaka('Z.A.P.');
        $series = Series::create(['mangaka_id' => $m->id, 'name' => 'My Series', 'is_auto' => false]);
        $this->seedWork('Z.A.P.', 'x', ['series_id' => $series->id]);

        $this->get('/series/'.$series->id)->assertOk()
            ->assertSee('My Series')
            ->assertSee('seriesRename(', false); // rename component wired
    }

    public function test_empty_series_is_not_shown_on_the_mangaka_page(): void
    {
        $m = $this->mangaka('Z.A.P.');
        Series::create(['mangaka_id' => $m->id, 'name' => 'Empty Ghost', 'is_auto' => false]); // no works
        $withWork = Series::create(['mangaka_id' => $m->id, 'name' => 'Real Series', 'is_auto' => false]);
        $this->seedWork('Z.A.P.', 'a work', ['series_id' => $withWork->id]);

        $this->get('/mangaka/'.$m->slug)->assertOk()
            ->assertSee('Real Series')        // non-empty series renders
            ->assertDontSee('Empty Ghost');   // empty series is filtered out (no ghost "0 works" card)
    }
}
