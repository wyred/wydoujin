<?php

use App\Models\Mangaka;
use App\Models\Series;
use App\Models\Work;

beforeEach(function () {
    $this->withoutVite();
});

test('index lists mangaka with work counts', function (): void {
    $m = Mangaka::factory()->create(['name' => 'Z.A.P.']);
    Work::factory()->for($m)->create();
    Work::factory()->for($m)->create();

    $this->get('/mangaka')->assertOk()
        ->assertSee('Z.A.P.')
        ->assertSee('href="/mangaka/'.$m->slug.'"', false);
});

test('index empty state', function (): void {
    $this->get('/mangaka')->assertOk()->assertSee('No mangaka');
});

test('index shows numbered pagination with jump links', function (): void {
    // 24 per page → 50 mangaka spans 3 pages. / 1ページ24件。
    foreach (range(1, 50) as $i) {
        Mangaka::factory()->create(['name' => sprintf('Artist %02d', $i)]);
    }

    $this->get('/mangaka?page=2')->assertOk()
        ->assertSee('aria-current="page"', false)   // current page marker
        ->assertSee('href="http://localhost/mangaka?page=1"', false)  // jump back
        ->assertSee('href="http://localhost/mangaka?page=3"', false)  // jump forward
        ->assertDontSee('Page 2 of');                // old text is gone
});

test('show separates series and standalone works', function (): void {
    $m = Mangaka::factory()->create(['name' => 'CircleA']);
    $series = Series::factory()->for($m)->create(['name' => 'MyShelf']);
    Work::factory()->for($m)->create(['title' => 'SeriesVol1', 'series_id' => $series->id, 'sort_title' => 'SeriesVol1']);
    Work::factory()->for($m)->create(['title' => 'LoneWork', 'series_id' => null, 'sort_title' => 'LoneWork']);

    $this->get('/mangaka/'.$m->slug)->assertOk()
        ->assertSee('MyShelf')
        ->assertSee('href="/series/'.$series->id.'"', false)
        ->assertSee('LoneWork')
        ->assertSee('href="/work/'.Work::where('title', 'LoneWork')->first()->id.'"', false);
});
