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
