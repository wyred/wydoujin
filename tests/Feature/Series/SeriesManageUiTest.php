<?php

use App\Models\Series;
use Tests\Feature\Series\SeedsMangakaWorks;

uses(SeedsMangakaWorks::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('mangaka page embeds manage data and button', function (): void {
    $w = $this->seedWork('Z.A.P.', '四畳半物語');
    $slug = $w->mangaka->slug;

    $this->get('/mangaka/'.$slug)->assertOk()
        ->assertSee('四畳半物語')      // work title (normal view + embedded manage data)
        ->assertSee('Manage')          // manage toggle
        ->assertSee('seriesManager(', false); // Alpine manage component wired
});

test('series page has inline rename', function (): void {
    $m = $this->mangaka('Z.A.P.');
    $series = Series::create(['mangaka_id' => $m->id, 'name' => 'My Series', 'is_auto' => false]);
    $this->seedWork('Z.A.P.', 'x', ['series_id' => $series->id]);

    $this->get('/series/'.$series->id)->assertOk()
        ->assertSee('My Series')
        ->assertSee('seriesRename(', false); // rename component wired
});

test('empty series is not shown on the mangaka page', function (): void {
    $m = $this->mangaka('Z.A.P.');
    Series::create(['mangaka_id' => $m->id, 'name' => 'Empty Ghost', 'is_auto' => false]); // no works
    $withWork = Series::create(['mangaka_id' => $m->id, 'name' => 'Real Series', 'is_auto' => false]);
    $this->seedWork('Z.A.P.', 'a work', ['series_id' => $withWork->id]);

    $this->get('/mangaka/'.$m->slug)->assertOk()
        ->assertSee('Real Series')        // non-empty series renders
        ->assertDontSee('Empty Ghost');   // empty series is filtered out (no ghost "0 works" card)
});
